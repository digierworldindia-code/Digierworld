<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;

/**
 * Recurring retainers. A profile only ever produces DRAFTS for a person to
 * review and finalize — no number is consumed and nothing is posted until
 * someone with finalize permission issues the document.
 */
class RecurringBillingService
{
    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $in
     */
    public function save(array $in, ?int $id = null): int
    {
        $errors  = [];
        $contact = $this->db->table('contacts')->where(['id' => (int) ($in['contact_id'] ?? 0), 'deleted_at' => null])->get()->getRowArray();
        $start   = ymd((string) ($in['start_date'] ?? ''));
        $end     = ymd((string) ($in['end_date'] ?? ''));
        $next    = ymd((string) ($in['next_run_date'] ?? '')) ?? $start;
        $freq    = isset(self::MONTHS[$in['frequency'] ?? '']) ? $in['frequency'] : null;
        $docType = in_array($in['doc_type'] ?? '', ['tax_invoice', 'cash_memo', 'proforma'], true) ? $in['doc_type'] : 'tax_invoice';
        $name    = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the retainer a name.';
        }
        if ($contact === null) {
            $errors['contact_id'] = 'Choose the client.';
        }
        if ($start === null) {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($freq === null) {
            $errors['frequency'] = 'Choose how often to bill.';
        }
        if ($end !== null && $start !== null && $end < $start) {
            $errors['end_date'] = 'End date is before the start date.';
        }
        $lines = [];
        foreach (array_values((array) ($in['lines'] ?? [])) as $i => $l) {
            if (trim((string) ($l['item_name'] ?? '')) === '' && (float) ($l['unit_rate'] ?? 0) == 0.0) {
                continue;
            }
            $rate = str_replace(',', '', (string) ($l['unit_rate'] ?? '0'));
            if (trim((string) ($l['item_name'] ?? '')) === '' || ! is_numeric($rate) || (float) $rate < 0) {
                $errors['lines'] = 'Each line needs a description and a rate.';

                continue;
            }
            $lines[] = [
                'line_no' => count($lines) + 1, 'service_id' => ((int) ($l['service_id'] ?? 0)) ?: null, 'item_name' => mb_substr(trim((string) $l['item_name']), 0, 200),
                'description' => trim((string) ($l['description'] ?? '')) ?: null, 'sac_code' => preg_replace('/\D/', '', (string) ($l['sac_code'] ?? '')) ?: null,
                'qty' => is_numeric($l['qty'] ?? null) ? (string) $l['qty'] : '1', 'unit' => trim((string) ($l['unit'] ?? '')) ?: null, 'unit_rate' => $rate,
                'tax_treatment' => (string) ($l['tax_treatment'] ?? 'regular') ?: 'regular', 'gst_rate' => is_numeric($l['gst_rate'] ?? null) ? (string) $l['gst_rate'] : '18',
            ];
        }
        if ($lines === [] && ! isset($errors['lines'])) {
            $errors['lines'] = 'Add at least one line.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }
        $data = [
            'name' => mb_substr($name, 0, 150), 'contact_id' => (int) $contact['id'], 'doc_type' => $docType, 'frequency' => $freq, 'start_date' => $start, 'end_date' => $end,
            'next_run_date' => $next ?? $start, 'contract_value' => is_numeric(str_replace(',', '', (string) ($in['contract_value'] ?? ''))) ? Dec::round(str_replace(',', '', (string) $in['contract_value'])) : null,
            'installments_total' => ((int) ($in['installments_total'] ?? 0)) ?: null, 'renewal_reminder_days' => max(0, min(90, (int) ($in['renewal_reminder_days'] ?? 15))),
            'place_of_supply_state_code' => preg_match('/^\d{2}$/', (string) ($in['place_of_supply_state_code'] ?? '')) ? $in['place_of_supply_state_code'] : null,
            'gst_type' => in_array($in['gst_type'] ?? '', ['cgst_sgst', 'cgst_utgst', 'igst', 'none'], true) ? $in['gst_type'] : null,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'is_active' => $id === null || ! empty($in['is_active']) ? 1 : 0, 'updated_by' => service('authz')->currentUserId(),
        ];

        $this->db->transBegin();
        try {
            if ($id === null) {
                $this->db->table('recurring_billing_profiles')->insert($data + ['created_by' => service('authz')->currentUserId()]);
                $id = (int) $this->db->insertID();
            } else {
                $this->db->table('recurring_billing_profiles')->where('id', $id)->update($data);
                $this->db->table('recurring_billing_lines')->where('profile_id', $id)->delete();
            }
            foreach ($lines as $l) {
                $this->db->table('recurring_billing_lines')->insert($l + ['profile_id' => $id]);
            }
            service('audit')->log('recurring.saved', 'recurring_billing_profile', $id, null, $data + ['lines' => count($lines)], 'Retainer saved: ' . $data['name']);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $id;
    }

    /** Generate the draft for the profile's next period. Idempotent per period. */
    public function generate(int $profileId): int
    {
        $this->db->transBegin();
        try {
            $p = $this->db->query('SELECT * FROM recurring_billing_profiles WHERE id = ? FOR UPDATE', [$profileId])->getRowArray() ?? throw new BusinessRuleException('Retainer not found.');
            if ((int) $p['is_active'] !== 1) {
                throw new BusinessRuleException('This retainer is paused or ended.');
            }
            if ($p['end_date'] !== null && $p['next_run_date'] > $p['end_date']) {
                throw new BusinessRuleException('The retainer ended on ' . dmy($p['end_date']) . '.');
            }
            if ($p['installments_total'] !== null && (int) $p['installments_done'] >= (int) $p['installments_total']) {
                throw new BusinessRuleException('All installments of this retainer have been billed.');
            }
            $months = self::MONTHS[$p['frequency']];
            $from   = $p['next_run_date'];
            $to     = date('Y-m-d', strtotime($from . " +{$months} months -1 day"));
            $exists = $this->db->table('billing_documents')->where(['recurring_profile_id' => $profileId, 'service_period_from' => $from])->where('status !=', 'cancelled')->get()->getRowArray();
            if ($exists !== null) {
                throw new BusinessRuleException('A document for ' . dmy($from) . ' – ' . dmy($to) . ' already exists (' . ($exists['document_number'] ?? $exists['draft_ref']) . ').');
            }
            $lines = $this->db->table('recurring_billing_lines')->where('profile_id', $profileId)->orderBy('line_no')->get()->getResultArray();
            $docId = service('billing')->saveDraft([
                'doc_type' => $p['doc_type'], 'contact_id' => (int) $p['contact_id'], 'issue_date' => date('Y-m-d'),
                'service_period_from' => $from, 'service_period_to' => $to,
                'place_of_supply_state_code' => $p['place_of_supply_state_code'], 'gst_type' => $p['gst_type'], 'notes' => $p['notes'],
                'lines' => array_map(static fn ($l) => array_intersect_key($l, array_flip(['service_id', 'item_name', 'description', 'sac_code', 'qty', 'unit', 'unit_rate', 'tax_treatment', 'gst_rate'])), $lines),
            ]);
            $this->db->table('billing_documents')->where('id', $docId)->update(['recurring_profile_id' => $profileId]);
            $done = (int) $p['installments_done'] + 1;
            $nextRun = date('Y-m-d', strtotime($from . " +{$months} months"));
            $ended   = ($p['installments_total'] !== null && $done >= (int) $p['installments_total']) || ($p['end_date'] !== null && $nextRun > $p['end_date']);
            $this->db->table('recurring_billing_profiles')->where('id', $profileId)->update([
                'next_run_date' => $nextRun, 'installments_done' => $done, 'last_generated_document_id' => $docId, 'is_active' => $ended ? 0 : 1,
            ]);
            service('audit')->log('recurring.generated', 'recurring_billing_profile', $profileId, null, ['document_id' => $docId, 'period' => [$from, $to]], 'Draft generated for ' . $p['name'] . ' (' . dmy($from) . ' – ' . dmy($to) . ')');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
        if ($p['created_by']) {
            service('notifier')->notify((int) $p['created_by'], 'billing', 'Retainer draft ready: ' . $p['name'], 'Review and finalize the ' . doc_type_label($p['doc_type']) . ' for ' . dmy($from) . ' – ' . dmy($to) . '.',
                site_url('billing/documents/' . $docId), 'billing_document', $docId, 'recurring:' . $profileId . ':' . $from);
        }

        return $docId;
    }

    /**
     * Cron: generate drafts that are due and send renewal reminders.
     *
     * @return array{generated: int, failed: list<string>, reminders: int}
     */
    public function runDue(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $out   = ['generated' => 0, 'failed' => [], 'reminders' => 0];
        $authz = service('authz');
        foreach ($this->db->table('recurring_billing_profiles')->where('is_active', 1)->where('next_run_date <=', $today)->get()->getResultArray() as $p) {
            $authz->actAs($p['created_by'] ? (int) $p['created_by'] : null);
            try {
                $this->generate((int) $p['id']);
                $out['generated']++;
            } catch (\Throwable $e) {
                $out['failed'][] = $p['name'] . ': ' . $e->getMessage();
                if ($p['created_by']) {
                    service('notifier')->notify((int) $p['created_by'], 'billing', 'Retainer draft not generated: ' . $p['name'], $e->getMessage(), site_url('billing/recurring/' . $p['id']), 'recurring_billing_profile', (int) $p['id'], 'recurring-fail:' . $p['id'] . ':' . $p['next_run_date']);
                }
            }
        }
        foreach ($this->db->table('recurring_billing_profiles')->where('is_active', 1)->where('end_date IS NOT NULL', null, false)->get()->getResultArray() as $p) {
            $daysLeft = (int) floor((strtotime($p['end_date']) - strtotime($today)) / 86400);
            if ($daysLeft >= 0 && $daysLeft <= (int) $p['renewal_reminder_days'] && $p['created_by']) {
                if (service('notifier')->notify((int) $p['created_by'], 'billing', 'Retainer ending: ' . $p['name'], 'Ends on ' . dmy($p['end_date']) . '. Discuss renewal with the client.', site_url('billing/recurring/' . $p['id']), 'recurring_billing_profile', (int) $p['id'], 'renewal:' . $p['id'] . ':' . $p['end_date'])) {
                    $out['reminders']++;
                }
            }
        }
        $authz->actAs(null);

        return $out;
    }
}
