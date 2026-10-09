<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyModel;
use App\Models\RfqMatchModel;

/**
 * Database-driven RFQ → supplier matching.
 *
 * Candidates come only from real data: the suppliers' own visible inventory
 * (part number, OEM number, declared cross reference, brand, category) and
 * Approved Vendor List entries. Every score is stored with its reasons so
 * administrators can see exactly why a supplier was suggested. No supplier
 * is suggested without at least one concrete reason.
 */
class MatchingService
{
    public const DEFAULT_WEIGHTS = [
        'part_number'     => 50,
        'oem_number'      => 40,
        'cross_reference' => 25,
        'brand'           => 15,
        'category'        => 10,
        'stock_sufficient' => 10,
        'avl'             => 10,
        'performance_max' => 10,
    ];

    /**
     * Runs matching and stores suggestions. Returns the matches.
     */
    public function run(array $rfq): array
    {
        $w        = array_merge(self::DEFAULT_WEIGHTS, (array) policy('rfq.matching_weights', []));
        $minScore = (float) policy('rfq.min_match_score', 20);
        $viewer   = service('rfqs')->buyerViewer($rfq);
        $items    = service('rfqs')->itemsOf((int) $rfq['id']);
        $db       = db_connect();
        $ent      = service('entitlements');

        /** @var array<int,array{score:float,reasons:list<string>,products:list<int>}> $cand */
        $cand = [];
        $add  = static function (int $sid, float $pts, string $reason, ?int $pid = null) use (&$cand): void {
            $cand[$sid] ??= ['score' => 0.0, 'reasons' => [], 'products' => []];
            $cand[$sid]['score'] += $pts;
            if (! in_array($reason, $cand[$sid]['reasons'], true)) {
                $cand[$sid]['reasons'][] = $reason;
            }
            if ($pid !== null && ! in_array($pid, $cand[$sid]['products'], true)) {
                $cand[$sid]['products'][] = $pid;
            }
        };

        foreach ($items as $item) {
            $norm  = $item['part_number_norm'];
            $label = $item['part_number'] ?: $item['description'];
            $b     = $db->table('products p')->select('p.id, p.company_id, p.part_number_norm, p.oem_part_number_norm, p.brand_id, p.category_id, p.stock_on_hand, p.stock_reserved');
            service('visibility')->apply($b, $viewer);
            $b->groupStart();
            $has = false;
            if ($norm) {
                $esc = $db->escape($norm);
                $b->orWhere('p.part_number_norm', $norm)->orWhere('p.oem_part_number_norm', $norm)
                    ->orWhere("EXISTS (SELECT 1 FROM part_numbers pn WHERE pn.product_id = p.id AND pn.part_number_norm = {$esc})", null, false)
                    ->orWhere("EXISTS (SELECT 1 FROM cross_references xr WHERE xr.product_id = p.id AND xr.ref_part_number_norm = {$esc})", null, false);
                $has = true;
            }
            if ($item['brand_id'] && $item['category_id']) {
                $b->orGroupStart()->where('p.brand_id', $item['brand_id'])->where('p.category_id', $item['category_id'])->groupEnd();
                $has = true;
            }
            if ($item['product_id']) {
                $b->orWhere('p.id', $item['product_id']);
                $has = true;
            }
            if (! $has) {
                $b->where('1 = 0', null, false);
            }
            $b->groupEnd();

            foreach ($b->limit(500)->get()->getResultArray() as $p) {
                $sid = (int) $p['company_id'];
                $pid = (int) $p['id'];
                if ($norm && $p['part_number_norm'] === $norm) {
                    $add($sid, $w['part_number'], "Lists part number {$label}", $pid);
                } elseif ($norm && $p['oem_part_number_norm'] === $norm) {
                    $add($sid, $w['oem_number'], "Lists OEM number {$label}", $pid);
                } elseif ($norm && $db->table('part_numbers')->where('product_id', $pid)->where('part_number_norm', $norm)->countAllResults() > 0) {
                    $add($sid, $w['oem_number'], "Lists alternate number {$label}", $pid);
                } elseif ($norm && $db->table('cross_references')->where('product_id', $pid)->where('ref_part_number_norm', $norm)->countAllResults() > 0) {
                    $add($sid, $w['cross_reference'], "Declares a cross reference to {$label} (interchangeability not verified)", $pid);
                } elseif ((int) $item['product_id'] === $pid) {
                    $add($sid, $w['part_number'], 'Owns the listing the buyer selected', $pid);
                }
                if ($item['brand_id'] && (int) $p['brand_id'] === (int) $item['brand_id']) {
                    $add($sid, $w['brand'], 'Stocks the requested brand', $pid);
                }
                if ($item['category_id'] && (int) $p['category_id'] === (int) $item['category_id']) {
                    $add($sid, $w['category'], 'Stocks the requested category', $pid);
                }
                if ((int) $p['stock_on_hand'] - (int) $p['stock_reserved'] >= (int) $item['quantity']) {
                    $add($sid, $w['stock_sufficient'], 'Has enough listed stock for the requested quantity', $pid);
                }
            }

            // Approved Vendor List approvals (category / brand), without inventory.
            $avl = $db->table('approved_vendors')->select('company_id, scope_type')->where('status', 'approved')
                ->groupStart()->where('valid_until', null)->orWhere('valid_until >=', date('Y-m-d'))->groupEnd()
                ->groupStart()
                ->where('scope_type', 'general')
                ->orGroupStart()->where('scope_type', 'category')->where('scope_id', $item['category_id'] ?: 0)->groupEnd()
                ->orGroupStart()->where('scope_type', 'brand')->where('scope_id', $item['brand_id'] ?: 0)->groupEnd()
                ->groupEnd()->get()->getResultArray();
            foreach ($avl as $a) {
                if ($a['scope_type'] === 'general' && ! isset($cand[(int) $a['company_id']])) {
                    continue; // general approval only boosts suppliers that already match something
                }
                $add((int) $a['company_id'], $w['avl'], 'Approved vendor for this ' . $a['scope_type']);
            }
        }

        $matchModel = model(RfqMatchModel::class);
        $saved      = [];
        foreach ($cand as $sid => $c) {
            $supplier = model(CompanyModel::class)->find($sid);
            if (! $supplier || ! $ent->isVerifiedSupplier($supplier) || ! $ent->has($supplier, 'premium_rfq_leads')) {
                continue; // only verified suppliers entitled to premium leads
            }
            $perf = $db->table('supplier_profiles')->select('performance_score')->where('company_id', $sid)->get()->getRowArray();
            if ($perf && $perf['performance_score'] !== null) {
                $c['score'] += round(((float) $perf['performance_score'] / 100) * $w['performance_max'], 2);
                $c['reasons'][] = 'Performance score ' . $perf['performance_score'];
            }
            if ($c['score'] < $minScore) {
                continue;
            }
            $existing = $matchModel->where('rfq_id', $rfq['id'])->where('supplier_company_id', $sid)->first();
            $row      = ['match_score' => $c['score'], 'match_reasons' => json_encode($c['reasons']), 'matched_product_ids' => json_encode($c['products'])];
            if ($existing) {
                $matchModel->update($existing['id'], $row);
            } else {
                $matchModel->insert($row + ['rfq_id' => $rfq['id'], 'supplier_company_id' => $sid, 'status' => 'suggested']);
            }
            $saved[] = $sid;
        }

        if ($rfq['status'] === 'submitted') {
            model(\App\Models\RfqModel::class)->update($rfq['id'], ['status' => 'under_review']);
        }

        $max = (int) policy('rfq.max_auto_invites', 10);
        if (policy('rfq.auto_distribute', false) && $saved !== []) {
            $top = $matchModel->where('rfq_id', $rfq['id'])->where('status', 'suggested')->orderBy('match_score', 'DESC')->findAll($max);
            service('rfqs')->distribute(model(\App\Models\RfqModel::class)->find($rfq['id']), array_map('intval', array_column($top, 'supplier_company_id')), auth()->loggedIn() ? (int) auth()->id() : null);
        }

        service('audit')->log('rfq.matched', ['entity_type' => 'rfq', 'entity_id' => (int) $rfq['id'], 'description' => count($saved) . ' supplier(s) matched']);

        return $matchModel->where('rfq_id', $rfq['id'])->orderBy('match_score', 'DESC')->findAll();
    }
}
