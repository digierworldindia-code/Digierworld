<?php

namespace App\Controllers\Admin;

use App\Services\ClaimService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Warranty claim review. Risk indicators are shown with their evidence and
 * never decide anything: a person approves or rejects, with a reason.
 */
class Claims extends AdminController
{
    private const STATUSES = ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN'];

    public function index(): string
    {
        $db     = db_connect();
        $status = $this->request->getGet('status') === 'open' ? 'open' : $this->oneOf('status', self::STATUSES);
        $risk   = $this->oneOf('risk', ['LOW', 'MEDIUM', 'HIGH']);
        $dealer = $this->filter('dealer', 36);
        $q      = $this->filter('q', 40);

        $builder = $db->table('warranty_claims c')
            ->select('c.id, c.claim_number, c.status, c.risk_level, c.risk_score, c.submitted_at, c.issue_category, c.reported_issue, c.resolution, m.serial_number, d.business_name dealer, d.city')
            ->join('mattresses m', 'm.id = c.mattress_id')->join('dealers d', 'd.id = c.dealer_id')
            ->where('c.deleted_at', null);
        if ($status === 'open') {
            $builder->whereIn('c.status', ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED']);
        } elseif ($status) {
            $builder->where('c.status', $status);
        }
        if ($risk) {
            $builder->where('c.risk_level', $risk);
        }
        if ($dealer && is_uuid($dealer)) {
            $builder->where('c.dealer_id', $dealer);
        }
        if ($q) {
            $term = strtoupper($this->like($q));
            $builder->groupStart()->like('c.claim_number', $term)->orLike('m.serial_number', $term)->groupEnd();
        }
        // Riskiest first while triaging; newest first otherwise.
        $status === 'open' || in_array($status, ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'], true)
            ? $builder->orderBy('c.risk_score', 'DESC')->orderBy('c.submitted_at', 'DESC')
            : $builder->orderBy('c.submitted_at', 'DESC');

        return $this->render('admin/claims/index', 'Claims', 'admin/claims', [
            'list'     => $this->paginate($builder),
            'statuses' => self::STATUSES,
            'dealers'  => $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray(),
            'f'        => compact('status', 'risk', 'dealer', 'q'),
        ]);
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $c  = $db->table('warranty_claims c')
            ->select('c.*, m.serial_number, m.id mattress_id, m.sold_at, m.received_at, m.dispatched_at, v.size_label, p.name product,
                      d.business_name dealer, d.code dealer_code, d.city dealer_city, d.phone dealer_phone, d.id dealer_id,
                      cu.full_name customer, cu.city customer_city, cu.state customer_state,
                      w.start_date, w.end_date, w.status warranty_status, w.years, w.id warranty_id,
                      su.full_name submitted_by, ru.full_name reviewed_by')
            ->join('mattresses m', 'm.id = c.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = c.dealer_id')->join('customers cu', 'cu.id = c.customer_id', 'left')
            ->join('warranties w', 'w.id = c.warranty_id', 'left')
            ->join('users su', 'su.id = c.submitted_by_user_id', 'left')->join('users ru', 'ru.id = c.reviewed_by_user_id', 'left')
            ->where(['c.id' => $id, 'c.deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        $dealerStats = $db->query(
            'SELECT (SELECT COUNT(*) FROM sales WHERE dealer_id = ? AND deleted_at IS NULL) sales,
                    (SELECT COUNT(*) FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL) claims',
            [$c['dealer_id'], $c['dealer_id']],
        )->getRowArray();

        return $this->render('admin/claims/show', 'Claim ' . $c['claim_number'], 'admin/claims', [
            'c'           => $c,
            'signals'     => json_decode((string) $c['risk_signals'], true) ?: [],
            'events'      => $db->table('claim_events e')->select('e.*, u.full_name actor')->join('users u', 'u.id = e.actor_user_id', 'left')
                ->where('e.claim_id', $id)->orderBy('e.created_at')->get()->getResultArray(),
            'media'       => $this->ctx->can('claim:media:view')
                ? $db->table('claim_media')->select('id, kind, mime_type, byte_size, caption, created_at')->where('claim_id', $id)->orderBy('created_at')->get()->getResultArray()
                : null,
            'replacement' => $db->table('replacements r')->select('r.*, n.serial_number new_serial, n.id new_id')
                ->join('mattresses n', 'n.id = r.replacement_mattress_id')->where('r.claim_id', $id)->get()->getRowArray(),
            'dealerStats' => $dealerStats,
            'next'        => ClaimService::TRANSITIONS[$c['status']] ?? [],
        ]);
    }

    public function note(string $id): RedirectResponse
    {
        return $this->act(fn () => ClaimService::instance()->addNote($id, $this->post('note'), $this->request->getPost('internal') !== '0'), 'Note added.');
    }

    public function requestInformation(string $id): RedirectResponse
    {
        return $this->act(fn () => ClaimService::instance()->requestInformation($id, $this->post('message')), 'The dealer has been asked for more information.');
    }

    public function inspection(string $id): RedirectResponse
    {
        return $this->act(fn () => ClaimService::instance()->scheduleInspection($id, $this->post('date', 10), $this->post('note')), 'Inspection recorded and the dealer notified.');
    }

    public function media(string $id): RedirectResponse
    {
        $files = $this->request->getFileMultiple('files') ?? [];

        return $this->act(fn () => ClaimService::instance()->attachMedia($id, $files), static function ($r): string {
            $msg = "{$r['uploaded']} file(s) attached.";
            foreach ($r['rejected'] as $x) {
                $msg .= " {$x['name']}: {$x['reason']}";
            }

            return $msg;
        });
    }

    public function decide(string $id): RedirectResponse
    {
        $decision   = (string) $this->request->getPost('decision');
        $resolution = $this->request->getPost('resolution');

        return $this->act(
            fn () => ClaimService::instance()->decide($id, $decision, $this->post('reason'), is_string($resolution) && $resolution !== '' ? $resolution : null),
            $decision === 'APPROVED' ? 'Claim approved.' : ($decision === 'UNDER_REVIEW' ? 'Claim moved into review.' : 'Claim ' . strtolower(humanise($decision)) . '.'),
        );
    }

    public function close(string $id): RedirectResponse
    {
        return $this->act(fn () => ClaimService::instance()->close($id, $this->post('note')), 'Claim closed.');
    }

    public function replacement(string $id): RedirectResponse
    {
        return $this->act(
            fn () => ClaimService::instance()->issueReplacement($id, strtoupper($this->post('serial', 20)), $this->post('remarks', 500) ?: null),
            static fn ($r) => "Replacement {$r['replacement']} issued for {$r['original']}. Its warranty runs to " . local_date($r['warranty_end']) . '.',
        );
    }

    public function recomputeRisk(string $id): RedirectResponse
    {
        return $this->act(fn () => ClaimService::instance()->recomputeRisk($id), static fn ($r) => "Risk recomputed: {$r['level']} ({$r['score']}).");
    }
}
