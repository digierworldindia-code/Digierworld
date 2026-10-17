<?php

namespace App\Controllers\Agent;

use App\Controllers\Warehouse\FloorController;
use App\Services\SurveyService;

/**
 * The dealers an agent covers, and what the records say each one holds.
 *
 * Every figure on these screens is counted from the database. There is no
 * cached total and no number typed into a template: an agent comparing a
 * shelf against a stale figure would raise discrepancies that are not real,
 * and stop trusting the ones that are.
 */
class Dealers extends FloorController
{
    public function index(): string
    {
        return $this->render('agent/dealers/index', 'My dealers', [
            'assignments' => SurveyService::instance()->assignments((string) $this->ctx->userId()),
        ], site_url('agent'));
    }

    /** One dealer: their stock, filtered however the agent needs it. */
    public function show(string $dealerId): string
    {
        $survey  = SurveyService::instance();
        $agentId = (string) $this->ctx->userId();
        $survey->assertAssigned($agentId, $dealerId);

        $filters = [
            'status'   => $this->oneOfGet('status', ['DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED']),
            'model'    => $this->uuidGet('model'),
            'size'     => $this->uuidGet('size'),
            'from'     => $this->dateGet('from'),
            'to'       => $this->dateGet('to'),
            'warranty' => $this->oneOfGet('warranty', ['ACTIVE', 'NONE']),
            'q'        => $this->textGet('q', 60),
        ];

        $db     = db_connect();
        $dealer = $db->table('dealers')->where(['id' => $dealerId, 'deleted_at' => null])->get()->getRowArray()
            ?? $this->notFound();

        return $this->render('agent/dealers/show', $dealer['business_name'], [
            'dealer'  => $dealer,
            'totals'  => $survey->dealerTotals($dealerId),
            'rows'    => $survey->dealerStock($dealerId, $filters),
            'filters' => $filters,
            'open'    => $db->table('dealer_surveys')
                ->where(['dealer_id' => $dealerId, 'agent_user_id' => $agentId, 'status' => 'IN_PROGRESS'])
                ->get()->getRowArray(),
            'history' => $survey->history($agentId, $dealerId, 10),
            // Only the models and sizes this dealer actually holds: a filter
            // offering sizes that cannot appear is noise on a phone.
            'models' => $db->query(
                'SELECT DISTINCT p.id, p.name FROM mattresses m
                   JOIN product_variants v ON v.id = m.product_variant_id
                   JOIN products p ON p.id = v.product_id
                  WHERE m.current_dealer_id = ? AND m.deleted_at IS NULL ORDER BY p.name',
                [$dealerId],
            )->getResultArray(),
            'sizes' => $db->query(
                'SELECT DISTINCT v.id, v.size_label, p.name AS product FROM mattresses m
                   JOIN product_variants v ON v.id = m.product_variant_id
                   JOIN products p ON p.id = v.product_id
                  WHERE m.current_dealer_id = ? AND m.deleted_at IS NULL ORDER BY p.name, v.size_label',
                [$dealerId],
            )->getResultArray(),
        ], site_url('agent/dealers'));
    }

    private function oneOfGet(string $name, array $allowed): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function uuidGet(string $name): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && is_uuid($value) ? $value : null;
    }

    private function dateGet(string $name): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function textGet(string $name, int $max): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
