<?php

namespace App\Controllers\Dealer;

use App\Services\ClaimService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Warranty claims from the dealer's side: raise one, add photographs, answer
 * a question. Internal notes and risk indicators are never shown here.
 */
class Claims extends DealerController
{
    public function index(): string
    {
        $builder = $this->scope->apply(
            db_connect()->table('warranty_claims c')->select('c.id, c.claim_number, c.status, c.submitted_at, c.reported_issue, c.issue_category, m.serial_number, p.name product')
                ->join('mattresses m', 'm.id = c.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id'),
            'warranty_claims', 'c',
        )->where('c.deleted_at', null)->orderBy('c.submitted_at', 'DESC');

        return $this->render('dealer/claims', 'Claims', 'claims', ['list' => $this->paginate($builder)]);
    }

    public function new(): string
    {
        $serial = strtoupper(trim((string) $this->request->getGet('serial')));
        $sold   = $this->scope->apply(
            db_connect()->table('mattresses m')->select('m.serial_number, m.sold_at, p.name product, v.size_label, w.end_date, w.status warranty_status')
                ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
                ->join('warranties w', 'w.mattress_id = m.id AND w.deleted_at IS NULL', 'left'),
            'mattresses', 'm',
        )->where('m.deleted_at', null)->whereIn('m.current_status', ['SOLD', 'CLAIM_OPEN'])
            ->orderBy('m.sold_at', 'DESC')->limit(300)->get()->getResultArray();

        return $this->render('dealer/claim_new', 'Raise a claim', 'claims', [
            'serial' => $serial, 'sold' => $sold, 'categories' => ClaimService::ISSUE_CATEGORIES,
        ]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'serial'         => 'required|serial_number',
            'issue_category' => 'required|in_list[' . implode(',', array_keys(ClaimService::ISSUE_CATEGORIES)) . ']',
            'reported_issue' => 'required|min_length[5]|max_length[200]|safe_text',
            'description'    => 'required|min_length[20]|max_length[4000]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(function () use ($in) {
            $claim = ClaimService::instance()->submit([
                'serial'         => strtoupper(trim($in['serial'])),
                'issue_category' => $in['issue_category'],
                'reported_issue' => $in['reported_issue'],
                'description'    => $in['description'],
            ]);
            // Photographs can be attached in the same submission.
            $files = $this->request->getFileMultiple('files') ?? [];
            if ($files !== []) {
                ClaimService::instance()->attachMedia($claim['id'], $files);
            }

            return $claim;
        }, static fn ($r) => "Claim {$r['claim_number']} raised. You will be told as soon as it has been reviewed.", site_url('dealer/claims'));
    }

    public function show(string $id): string
    {
        $db    = db_connect();
        $claim = $this->scope->assertOwns('warranty_claims',
            $db->table('warranty_claims c')->select('c.*, m.serial_number, m.id mattress_id, p.name product, v.size_label, cu.full_name customer, w.end_date, w.status warranty_status')
                ->join('mattresses m', 'm.id = c.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
                ->join('customers cu', 'cu.id = c.customer_id', 'left')->join('warranties w', 'w.id = c.warranty_id', 'left')
                ->where(['c.id' => $id, 'c.deleted_at' => null])->get()->getRowArray(), 'claim');

        return $this->render('dealer/claim', 'Claim ' . $claim['claim_number'], 'claims', [
            'c' => $claim,
            // Internal notes stay internal: the scope adds is_internal = 0.
            'events' => $this->scope->apply($db->table('claim_events e')->select('e.event_type, e.from_status, e.to_status, e.note, e.created_at'), 'claim_events', 'e')
                ->where('e.claim_id', $id)->orderBy('e.created_at')->get()->getResultArray(),
            'media' => $this->scope->apply($db->table('claim_media'), 'claim_media')->select('id, kind, mime_type, caption, created_at')
                ->where('claim_id', $id)->orderBy('created_at')->get()->getResultArray(),
            'closed' => in_array($claim['status'], ClaimService::CLOSED_STATES, true),
        ]);
    }

    public function media(string $id): RedirectResponse
    {
        $files = $this->request->getFileMultiple('files') ?? [];

        return $this->act(fn () => ClaimService::instance()->attachMedia($id, $files), static function ($r): string {
            $message = "{$r['uploaded']} file(s) added.";
            foreach ($r['rejected'] as $rejected) {
                $message .= " {$rejected['name']}: {$rejected['reason']}";
            }

            return $message;
        }, site_url('dealer/claims/' . $id));
    }

    public function reply(string $id): RedirectResponse
    {
        return $this->act(
            fn () => ClaimService::instance()->dealerReply($id, $this->post('message')),
            'Your reply has been sent to the warranty team.',
            site_url('dealer/claims/' . $id),
        );
    }
}
