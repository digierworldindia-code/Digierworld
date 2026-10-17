<?php

namespace App\Services\Sync;

use CodeIgniter\Database\BaseConnection;

/**
 * Builds the row written to Google Sheets from the CURRENT database state,
 * so a retried job always writes the latest data. Column A is always the
 * stable database key used for upserts. Mobile numbers of leads marked
 * Do-Not-Call or with withdrawn consent are masked; amounts are not written
 * unless the column is explicitly enabled.
 */
final class SheetsRowBuilder
{
    /** worksheet key => entity type */
    public const ENTITIES = ['leads' => 'lead', 'calls' => 'call_log', 'followups' => 'followup', 'visits' => 'visit', 'conversions' => 'conversion', 'daily' => 'daily_summary'];

    /** worksheet key => [column key => header] (order = available columns) */
    public const COLUMNS = [
        'leads' => ['id' => 'Record ID', 'lead_code' => 'Lead code', 'company_name' => 'Lead / company', 'contact_person' => 'Contact person', 'mobile' => 'Mobile',
            'address' => 'Address', 'city' => 'City', 'industry' => 'Field / industry', 'services' => 'Service interest', 'source' => 'Source', 'assigned_to' => 'Assigned telecaller',
            'stage' => 'Lead status', 'priority' => 'Priority', 'next_followup' => 'Next follow-up', 'last_action' => 'Last action', 'remarks' => 'Remark',
            'updated_at' => 'Last updated', 'sync_status' => 'Sync status'],
        'calls' => ['id' => 'Record ID', 'lead_id' => 'Lead ID', 'company_name' => 'Lead / company', 'mobile' => 'Mobile', 'caller' => 'Telecaller', 'called_at' => 'Call date',
            'direction' => 'Direction', 'outcome' => 'Call outcome', 'notes' => 'Remark', 'next_action' => 'Next action', 'next_action_at' => 'Next follow-up',
            'updated_at' => 'Last updated', 'sync_status' => 'Sync status'],
        'followups' => ['id' => 'Record ID', 'lead_id' => 'Lead ID', 'company_name' => 'Lead / company', 'owner' => 'Owner', 'due_at' => 'Due', 'status' => 'Status',
            'title' => 'Follow-up', 'completed_at' => 'Completed', 'outcome_notes' => 'Outcome', 'updated_at' => 'Last updated', 'sync_status' => 'Sync status'],
        'visits' => ['id' => 'Record ID', 'lead_id' => 'Lead ID', 'company_name' => 'Lead / company', 'visitor' => 'Visitor', 'scheduled_at' => 'Scheduled',
            'address' => 'Address', 'purpose' => 'Purpose', 'status' => 'Status', 'outcome' => 'Outcome', 'client_feedback' => 'Client feedback', 'next_action' => 'Next action',
            'updated_at' => 'Last updated', 'sync_status' => 'Sync status'],
        'conversions' => ['id' => 'Record ID', 'lead_id' => 'Lead ID', 'company_name' => 'Lead / company', 'client' => 'Client', 'document' => 'Estimate / invoice',
            'converted_at' => 'Converted', 'attributed_to' => 'Attributed to', 'contract_value' => 'Contract value', 'updated_at' => 'Last updated', 'sync_status' => 'Sync status'],
        'daily' => ['id' => 'Key', 'date' => 'Date', 'telecaller' => 'Telecaller', 'calls' => 'Calls', 'connected' => 'Connected', 'followups_done' => 'Follow-ups done',
            'meetings' => 'Meetings', 'visits' => 'Visits completed', 'conversions' => 'Conversions', 'updated_at' => 'Last updated'],
    ];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @param list<string>|null $enabled @return list<string> column keys, 'id' always first */
    public static function columns(string $worksheet, ?array $enabled): array
    {
        $all  = array_keys(self::COLUMNS[$worksheet]);
        $keys = $enabled === null || $enabled === [] ? $all : array_values(array_intersect($all, $enabled));

        return array_values(array_unique(array_merge(['id'], $keys)));
    }

    /** @param list<string> $cols @return list<string> */
    public static function headers(string $worksheet, array $cols): array
    {
        return array_map(static fn ($c) => self::COLUMNS[$worksheet][$c], $cols);
    }

    private function user(?int $id): string
    {
        if (! $id) {
            return '';
        }

        return $this->names[$id] ??= (string) ($this->db->table('user_profiles')->select('full_name')->where('user_id', $id)->get()->getRow()->full_name ?? ('User #' . $id));
    }

    private static function dt(?string $v): string
    {
        return $v ? date('d-m-Y H:i', strtotime($v)) : '';
    }

    private static function maskMobile(?string $m): string
    {
        $d = preg_replace('/\D/', '', (string) $m) ?? '';

        return $d === '' ? '' : substr($d, 0, 2) . str_repeat('X', max(0, strlen($d) - 4)) . substr($d, -2);
    }

    private function leadMobile(array $lead): string
    {
        return (int) $lead['dnd'] === 1 || $lead['consent_status'] === 'withdrawn' ? self::maskMobile($lead['mobile']) . ' (DND)' : (string) $lead['mobile'];
    }

    /** @return array<string, mixed>|null */
    private function lead(int $id): ?array
    {
        return $this->db->table('leads l')->select('l.*, s.name AS stage_name, src.name AS source_name')
            ->join('crm_stages s', 's.id = l.stage_id', 'left')->join('lead_sources src', 'src.id = l.source_id', 'left')
            ->where('l.id', $id)->get()->getRowArray();
    }

    /**
     * @param list<string> $cols
     *
     * @return list<string>|null null when the record no longer exists
     */
    public function row(string $worksheet, int $entityId, array $cols): ?array
    {
        $data = match ($worksheet) {
            'leads'       => $this->leadRow($entityId),
            'calls'       => $this->callRow($entityId),
            'followups'   => $this->followupRow($entityId),
            'visits'      => $this->visitRow($entityId),
            'conversions' => $this->conversionRow($entityId),
            'daily'       => $this->dailyRow($entityId),
            default       => null,
        };
        if ($data === null) {
            return null;
        }
        $data['sync_status'] = 'Synced ' . date('d-m-Y H:i');

        return array_map(static fn ($c) => (string) ($data[$c] ?? ''), $cols);
    }

    private function leadRow(int $id): ?array
    {
        $l = $this->lead($id);
        if ($l === null) {
            return null;
        }
        $svc = json_decode((string) $l['services_interested'], true) ?: [];

        return [
            'id' => 'L' . $l['id'], 'lead_code' => $l['lead_code'], 'company_name' => $l['company_name'], 'contact_person' => $l['contact_person'], 'mobile' => $this->leadMobile($l),
            'address' => trim($l['address'] . ($l['pincode'] ? ' - ' . $l['pincode'] : '')), 'city' => trim(($l['area'] ? $l['area'] . ', ' : '') . $l['city']), 'industry' => $l['industry'],
            'services' => implode(', ', $svc), 'source' => $l['source_name'], 'assigned_to' => $this->user($l['assigned_to'] ? (int) $l['assigned_to'] : null),
            'stage' => $l['deleted_at'] ? 'Archived' : $l['stage_name'], 'priority' => ucfirst((string) $l['priority']), 'next_followup' => self::dt($l['next_followup_at']),
            'last_action' => $l['last_action'], 'remarks' => mb_substr((string) $l['remarks'], 0, 500), 'updated_at' => self::dt($l['updated_at'] ?? $l['created_at']),
        ];
    }

    private function callRow(int $id): ?array
    {
        $c = $this->db->table('call_logs')->where('id', $id)->get()->getRowArray();
        $l = $c ? $this->lead((int) $c['lead_id']) : null;
        if ($c === null || $l === null) {
            return null;
        }

        return ['id' => 'C' . $c['id'], 'lead_id' => 'L' . $c['lead_id'], 'company_name' => $l['company_name'], 'mobile' => $this->leadMobile($l), 'caller' => $this->user((int) $c['caller_id']),
            'called_at' => self::dt($c['called_at']), 'direction' => $c['direction'], 'outcome' => str_replace('_', ' ', $c['outcome']), 'notes' => mb_substr((string) $c['notes'], 0, 500),
            'next_action' => $c['next_action'], 'next_action_at' => self::dt($c['next_action_at']), 'updated_at' => self::dt($c['created_at'])];
    }

    private function followupRow(int $id): ?array
    {
        $f = $this->db->table('followups')->where('id', $id)->get()->getRowArray();
        $l = $f ? $this->lead((int) $f['lead_id']) : null;
        if ($f === null || $l === null) {
            return null;
        }
        $status = $f['status'] === 'pending' && $f['due_at'] < date('Y-m-d H:i:s') ? 'overdue' : $f['status'];

        return ['id' => 'F' . $f['id'], 'lead_id' => 'L' . $f['lead_id'], 'company_name' => $l['company_name'], 'owner' => $this->user((int) $f['owner_id']), 'due_at' => self::dt($f['due_at']),
            'status' => $status, 'title' => $f['title'], 'completed_at' => self::dt($f['completed_at']), 'outcome_notes' => mb_substr((string) $f['outcome_notes'], 0, 300),
            'updated_at' => self::dt($f['updated_at'] ?? $f['created_at'])];
    }

    private function visitRow(int $id): ?array
    {
        $v = $this->db->table('visits')->where('id', $id)->get()->getRowArray();
        if ($v === null) {
            return null;
        }
        $l = $v['lead_id'] ? $this->lead((int) $v['lead_id']) : null;

        return ['id' => 'V' . $v['id'], 'lead_id' => $v['lead_id'] ? 'L' . $v['lead_id'] : '', 'company_name' => $l['company_name'] ?? $v['location'], 'visitor' => $this->user((int) $v['visitor_id']),
            'scheduled_at' => self::dt($v['scheduled_at']), 'address' => $v['address'], 'purpose' => $v['purpose'], 'status' => $v['status'], 'outcome' => mb_substr((string) $v['outcome'], 0, 300),
            'client_feedback' => mb_substr((string) $v['client_feedback'], 0, 300), 'next_action' => trim($v['next_action'] . ($v['next_action_at'] ? ' (' . self::dt($v['next_action_at']) . ')' : '')),
            'updated_at' => self::dt($v['updated_at'] ?? $v['created_at'])];
    }

    private function conversionRow(int $id): ?array
    {
        $c = $this->db->table('lead_conversion_links k')->select('k.*, ct.name AS client, d.document_number, d.draft_ref')
            ->join('contacts ct', 'ct.id = k.contact_id', 'left')->join('billing_documents d', 'd.id = k.document_id', 'left')->where('k.id', $id)->get()->getRowArray();
        $l = $c ? $this->lead((int) $c['lead_id']) : null;
        if ($c === null || $l === null) {
            return null;
        }

        return ['id' => 'K' . $c['id'], 'lead_id' => 'L' . $c['lead_id'], 'company_name' => $l['company_name'], 'client' => $c['client'], 'document' => $c['document_number'] ?? $c['draft_ref'],
            'converted_at' => self::dt($c['converted_at']), 'attributed_to' => $this->user($c['attributed_user_id'] ? (int) $c['attributed_user_id'] : null),
            'contract_value' => $c['contract_value'], 'updated_at' => self::dt($c['converted_at'])];
    }

    /** Daily summary key = YYYYMMDD * 1,000,000 + user id. */
    public static function dailyKey(string $date, int $userId): int
    {
        return (int) str_replace('-', '', $date) * 1000000 + $userId;
    }

    private function dailyRow(int $key): ?array
    {
        $uid  = $key % 1000000;
        $ymd  = (string) intdiv($key, 1000000);
        $date = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
        $from = $date . ' 00:00:00';
        $to   = $date . ' 23:59:59';
        $calls = $this->db->query('SELECT COUNT(*) n, COALESCE(SUM(is_connected),0) c FROM call_logs WHERE caller_id = ? AND called_at BETWEEN ? AND ?', [$uid, $from, $to])->getRowArray();

        return ['id' => $date . '|' . $uid, 'date' => date('d-m-Y', strtotime($date)), 'telecaller' => $this->user($uid), 'calls' => (int) $calls['n'], 'connected' => (int) $calls['c'],
            'followups_done' => $this->db->table('followups')->where(['completed_by' => $uid, 'status' => 'done'])->where('completed_at >=', $from)->where('completed_at <=', $to)->countAllResults(),
            'meetings' => $this->db->table('meetings')->where('organizer_id', $uid)->where('created_at >=', $from)->where('created_at <=', $to)->countAllResults(),
            'visits' => $this->db->table('visits')->where(['visitor_id' => $uid, 'status' => 'completed'])->where('completed_at >=', $from)->where('completed_at <=', $to)->countAllResults(),
            'conversions' => $this->db->table('lead_conversion_links')->where('attributed_user_id', $uid)->where('converted_at >=', $from)->where('converted_at <=', $to)->countAllResults(),
            'updated_at' => date('d-m-Y H:i')];
    }
}
