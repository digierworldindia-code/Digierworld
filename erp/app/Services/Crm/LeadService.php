<?php

namespace App\Services\Crm;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use App\Services\Sync\SheetsSyncService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Telecalling CRM. Every change is committed to MySQL first, written to the
 * lead timeline (calls are never overwritten), and queues its Google Sheets
 * sync job in the same transaction.
 */
class LeadService
{
    public const OUTCOMES = [
        'connected' => 'Connected', 'interested' => 'Interested', 'call_back' => 'Call back later', 'not_interested' => 'Not interested',
        'no_answer' => 'No answer', 'busy' => 'Busy', 'switched_off' => 'Switched off / unreachable', 'wrong_number' => 'Wrong number', 'dnd_requested' => 'Asked not to be called (DND)',
    ];

    /** Call outcome → stage it advances to (only forward, never out of a closed stage). */
    private const OUTCOME_STAGE = [
        'no_answer' => 'attempted', 'busy' => 'attempted', 'switched_off' => 'attempted', 'wrong_number' => 'attempted',
        'connected' => 'connected', 'interested' => 'interested', 'call_back' => 'followup', 'not_interested' => 'not_interested', 'dnd_requested' => 'dnc',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $stages = null;

    public function __construct(private readonly BaseConnection $db, private readonly SheetsSyncService $sync)
    {
    }

    private function uid(): int
    {
        return (int) service('authz')->currentUserId();
    }

    /** @return array<string, array<string, mixed>> code => stage */
    public function stages(): array
    {
        return $this->stages ??= array_column($this->db->table('crm_stages')->where('is_active', 1)->orderBy('sort_order')->get()->getResultArray(), null, 'code');
    }

    private function stageById(int $id): ?array
    {
        foreach ($this->stages() as $s) {
            if ((int) $s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /** Run $fn in a transaction; rethrow after rollback. */
    private function tx(callable $fn): mixed
    {
        $this->db->transBegin();
        try {
            $r = $fn();
            $this->db->transCommit();

            return $r;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function activity(int $leadId, string $type, string $title, ?string $details = null, ?string $refType = null, ?int $refId = null, ?string $at = null): void
    {
        $this->db->table('lead_activities')->insert([
            'lead_id' => $leadId, 'activity_type' => $type, 'title' => mb_substr($title, 0, 255), 'details' => $details,
            'ref_type' => $refType, 'ref_id' => $refId, 'actor_id' => $this->uid() ?: null, 'occurred_at' => $at ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $lead = $this->db->table('leads')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($lead === null || ! service('leadAccess')->canView($lead)) {
            throw new BusinessRuleException('Lead not found or not accessible.');
        }

        return $lead;
    }

    // ---- leads -----------------------------------------------------------

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function normalize(array $in, ?int $excludeId = null, bool $allowDuplicate = false): array
    {
        $errors  = [];
        $company = trim((string) ($in['company_name'] ?? ''));
        $person  = trim((string) ($in['contact_person'] ?? ''));
        $mobile  = normalize_phone((string) ($in['mobile'] ?? ''));
        if ($company === '' && $person === '') {
            $errors['company_name'] = 'Enter the business name or the contact person.';
        }
        if ($mobile === null || strlen($mobile) < 10 || strlen($mobile) > 12) {
            $errors['mobile'] = 'Enter a valid 10-digit mobile number (or landline with STD code).';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email address looks invalid.';
        }
        $pin = preg_replace('/\D/', '', (string) ($in['pincode'] ?? ''));
        if ($pin !== '' && strlen((string) $pin) !== 6) {
            $errors['pincode'] = 'PIN code must be 6 digits.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }
        if (! $allowDuplicate) {
            $dupe = $this->db->table('leads')->select('id, lead_code, company_name, assigned_to')->where('mobile_normalized', $mobile)->where('deleted_at', null)
                ->where('id !=', $excludeId ?? 0)->get()->getRowArray();
            if ($dupe !== null) {
                $who = service('leadAccess')->canView($dupe) ? ' (' . $dupe['lead_code'] . ' ' . $dupe['company_name'] . ')' : ' (assigned to another team member)';

                throw new BusinessRuleException('A lead with this mobile number already exists' . $who . '.', ['mobile' => 'duplicate', 'duplicate' => $dupe['lead_code']]);
            }
        }
        $services = array_values(array_filter(array_map('trim', is_array($in['services_interested'] ?? null) ? $in['services_interested'] : explode(',', (string) ($in['services_interested'] ?? '')))));
        $next     = ! empty($in['next_followup_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['next_followup_at'])) : null;

        return [
            'company_name' => mb_substr($company !== '' ? $company : $person, 0, 200), 'contact_person' => $person !== '' ? mb_substr($person, 0, 150) : null,
            'mobile' => mb_substr(trim((string) $in['mobile']), 0, 20), 'mobile_normalized' => $mobile,
            'alt_mobile' => trim((string) ($in['alt_mobile'] ?? '')) ?: null, 'email' => $email ?: null,
            'address' => trim((string) ($in['address'] ?? '')) ?: null, 'pincode' => $pin ?: null, 'area' => trim((string) ($in['area'] ?? '')) ?: null,
            'city' => trim((string) ($in['city'] ?? '')) ?: null, 'industry' => trim((string) ($in['industry'] ?? '')) ?: null,
            'services_interested' => json_encode($services, JSON_UNESCAPED_UNICODE), 'source_id' => ((int) ($in['source_id'] ?? 0)) ?: null,
            'campaign' => trim((string) ($in['campaign'] ?? '')) ?: null,
            'priority' => in_array($in['priority'] ?? '', ['low', 'medium', 'high', 'hot'], true) ? $in['priority'] : 'medium',
            'expected_value' => is_numeric(str_replace(',', '', (string) ($in['expected_value'] ?? ''))) ? number_format((float) str_replace(',', '', (string) $in['expected_value']), 2, '.', '') : null,
            'remarks' => trim((string) ($in['remarks'] ?? '')) ?: null, '_next' => $next,
            'consent_status' => in_array($in['consent_status'] ?? '', ['unknown', 'consented', 'withdrawn'], true) ? $in['consent_status'] : 'unknown',
        ];
    }

    /** @param array<string, mixed> $in */
    public function create(array $in, bool $allowDuplicate = false, string $via = 'manual'): int
    {
        $data   = $this->normalize($in, null, $allowDuplicate);
        $next   = $data['_next'];
        unset($data['_next']);
        $assign = $this->resolveAssignee((int) ($in['assigned_to'] ?? 0));

        return $this->tx(function () use ($data, $assign, $next, $via): int {
            $stage = $this->stages()['new']['id'] ?? null;
            $retention = (int) (service('settingsStore')->get('crm.retention_months', 36));
            $this->db->table('leads')->insert($data + [
                'lead_code' => 'TMP' . bin2hex(random_bytes(6)), 'assigned_to' => $assign, 'stage_id' => $stage, 'call_attempts' => 0, 'connected_calls' => 0,
                'dnd' => 0, 'retention_until' => date('Y-m-d', strtotime("+{$retention} months")), 'last_action' => 'Lead created', 'last_action_at' => date('Y-m-d H:i:s'),
                'created_by' => $this->uid() ?: null,
            ]);
            $id = (int) $this->db->insertID();
            $this->db->table('leads')->where('id', $id)->update(['lead_code' => 'LD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            $this->activity($id, $via === 'import' ? 'import' : 'created', $via === 'import' ? 'Lead imported' : 'Lead created', null);
            if ($assign) {
                $this->db->table('lead_assignments')->insert(['lead_id' => $id, 'from_user_id' => null, 'to_user_id' => $assign, 'assigned_by' => $this->uid() ?: null, 'reason' => 'Initial assignment', 'assigned_at' => date('Y-m-d H:i:s')]);
            }
            if ($next !== null && $assign) {
                $this->addFollowup($id, $assign, $next, 'First call', null, false);
            }
            $this->sync->enqueue('leads', $id);
            service('audit')->log('lead.created', 'lead', $id, null, ['company' => $data['company_name'], 'assigned_to' => $assign], 'Lead created: ' . $data['company_name']);

            return $id;
        });
    }

    /** Users without assign rights can only keep leads with themselves. */
    private function resolveAssignee(int $requested): ?int
    {
        $me = $this->uid();
        if ($requested <= 0) {
            return $me ?: null;
        }
        if ($requested !== $me && ! service('authz')->can('crm.lead.assign')) {
            return $me ?: null;
        }
        $active = $this->db->table('users')->where(['id' => $requested, 'active' => 1, 'deleted_at' => null])->countAllResults() > 0;

        return $active ? $requested : ($me ?: null);
    }

    /** @param array<string, mixed> $in */
    public function update(int $id, array $in): void
    {
        $before = $this->find($id);
        $data   = $this->normalize($in + ['company_name' => $before['company_name'], 'mobile' => $before['mobile']], $id, ! empty($in['allow_duplicate']));
        unset($data['_next']);
        $this->tx(function () use ($id, $before, $data): void {
            $this->db->table('leads')->where('id', $id)->update($data + ['updated_by' => $this->uid() ?: null]);
            $changed = array_keys(array_diff_assoc(array_map('strval', array_intersect_key($data, $before)), array_map('strval', array_intersect_key($before, $data))));
            if ($changed !== []) {
                $this->activity($id, 'edit', 'Lead details updated', 'Changed: ' . implode(', ', str_replace('_', ' ', $changed)));
            }
            $this->sync->enqueue('leads', $id);
            service('audit')->log('lead.updated', 'lead', $id, array_intersect_key($before, array_flip($changed)), array_intersect_key($data, array_flip($changed)), 'Lead updated');
        });
    }

    public function changeStage(int $id, int $stageId, ?int $lostReasonId = null, string $note = ''): void
    {
        $lead  = $this->find($id);
        $stage = $this->stageById($stageId) ?? throw new BusinessRuleException('Unknown stage.');
        if ((int) $stage['is_won'] === 1) {
            throw new BusinessRuleException('Use "Convert to client" to mark a lead Won, so the client and any estimate are linked.');
        }
        if ((int) $stage['is_lost'] === 1 && ! $lostReasonId) {
            throw new BusinessRuleException('Choose why the lead was lost.', ['lost_reason_id' => 'required']);
        }
        $this->tx(function () use ($lead, $stage, $lostReasonId, $note): void {
            $old = $this->stageById((int) $lead['stage_id']);
            $upd = ['stage_id' => $stage['id'], 'lost_reason_id' => (int) $stage['is_lost'] === 1 ? $lostReasonId : null, 'last_action' => 'Stage: ' . $stage['name'], 'last_action_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->uid()];
            if ((int) $stage['is_dnd'] === 1) {
                $upd += ['dnd' => 1, 'dnd_marked_at' => date('Y-m-d H:i:s'), 'consent_status' => 'withdrawn'];
            }
            $this->db->table('leads')->where('id', $lead['id'])->update($upd);
            $this->activity((int) $lead['id'], 'stage_change', ($old['name'] ?? '—') . ' → ' . $stage['name'], $note ?: null);
            if ((int) $stage['is_closed'] === 1) {
                $this->closeOpenFollowups((int) $lead['id'], 'Lead closed: ' . $stage['name']);
            }
            $this->sync->enqueue('leads', (int) $lead['id']);
        });
    }

    public function assign(int $id, int $toUser, string $reason = ''): void
    {
        if (! service('authz')->can('crm.lead.assign')) {
            throw new AccessDeniedException();
        }
        $lead = $this->find($id);
        if ($this->db->table('users')->where(['id' => $toUser, 'active' => 1, 'deleted_at' => null])->countAllResults() === 0) {
            throw new BusinessRuleException('Choose an active user.');
        }
        $this->tx(function () use ($lead, $toUser, $reason): void {
            $this->db->table('leads')->where('id', $lead['id'])->update(['assigned_to' => $toUser, 'updated_by' => $this->uid()]);
            $this->db->table('lead_assignments')->insert(['lead_id' => $lead['id'], 'from_user_id' => $lead['assigned_to'], 'to_user_id' => $toUser, 'assigned_by' => $this->uid(), 'reason' => mb_substr($reason, 0, 255) ?: null, 'assigned_at' => date('Y-m-d H:i:s')]);
            // open follow-ups move with the lead
            $this->db->table('followups')->where(['lead_id' => $lead['id'], 'status' => 'pending', 'owner_id' => $lead['assigned_to']])->update(['owner_id' => $toUser]);
            $name = $this->db->table('user_profiles')->select('full_name')->where('user_id', $toUser)->get()->getRow()->full_name ?? ('#' . $toUser);
            $this->activity((int) $lead['id'], 'assignment', 'Assigned to ' . $name, $reason ?: null);
            $this->sync->enqueue('leads', (int) $lead['id']);
            foreach ($this->db->table('followups')->select('id')->where(['lead_id' => $lead['id'], 'status' => 'pending'])->get()->getResultArray() as $f) {
                $this->sync->enqueue('followups', (int) $f['id']);
            }
        });
        service('notifier')->notify($toUser, 'crm', 'Lead assigned to you: ' . $lead['company_name'], $reason ?: null, site_url('crm/leads/' . $lead['id']), 'lead', (int) $lead['id']);
    }

    public function markDnd(int $id, string $reason): void
    {
        $lead = $this->find($id);
        $this->tx(function () use ($lead, $reason): void {
            $this->db->table('leads')->where('id', $lead['id'])->update(['dnd' => 1, 'dnd_marked_at' => date('Y-m-d H:i:s'), 'consent_status' => 'withdrawn', 'stage_id' => $this->stages()['dnc']['id'] ?? $lead['stage_id'], 'updated_by' => $this->uid()]);
            $this->activity((int) $lead['id'], 'dnd', 'Marked Do-Not-Call', $reason ?: null);
            $this->closeOpenFollowups((int) $lead['id'], 'Do-Not-Call');
            $this->sync->enqueue('leads', (int) $lead['id']);
        });
        service('audit')->log('lead.dnd', 'lead', (int) $lead['id'], null, ['reason' => $reason], 'Lead marked Do-Not-Call');
    }

    public function archive(int $id, string $reason): void
    {
        $lead = $this->find($id);
        $this->tx(function () use ($lead, $reason): void {
            $this->db->table('leads')->where('id', $lead['id'])->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->uid()]);
            $this->activity((int) $lead['id'], 'note', 'Lead archived', $reason ?: null);
            $this->closeOpenFollowups((int) $lead['id'], 'Lead archived');
            $this->sync->enqueue('leads', (int) $lead['id']);
        });
        service('audit')->log('lead.archived', 'lead', (int) $lead['id'], null, ['reason' => $reason], 'Lead archived');
    }

    // ---- calls -------------------------------------------------------------

    /**
     * Rapid call log. Each call is a new timeline event; the lead's counters,
     * last action, stage and next follow-up are updated from it.
     *
     * @param array<string, mixed> $in
     */
    public function logCall(int $leadId, array $in): int
    {
        $lead    = $this->find($leadId);
        $outcome = (string) ($in['outcome'] ?? '');
        if (! isset(self::OUTCOMES[$outcome])) {
            throw new BusinessRuleException('Choose the call outcome.', ['outcome' => 'required']);
        }
        $direction = ($in['direction'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound';
        if ($direction === 'outbound' && (int) $lead['dnd'] === 1) {
            throw new BusinessRuleException('This lead asked not to be called (Do-Not-Call since ' . dmy($lead['dnd_marked_at'], true) . '). Outbound calls are blocked; log only calls they make to you.');
        }
        $at     = ! empty($in['called_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['called_at'])) : date('Y-m-d H:i:s');
        if ($at > date('Y-m-d H:i:s', time() + 300)) {
            throw new BusinessRuleException('Call time cannot be in the future.', ['called_at' => 'future']);
        }
        $next   = ! empty($in['next_action_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['next_action_at'])) : null;
        if ($next !== null && $next < date('Y-m-d H:i:s', time() - 60)) {
            throw new BusinessRuleException('The next follow-up must be in the future.', ['next_action_at' => 'past']);
        }
        $connected = in_array($outcome, ['connected', 'interested', 'call_back', 'not_interested', 'dnd_requested'], true);
        $ownerId   = (int) ($in['followup_owner_id'] ?? 0) ?: (int) ($lead['assigned_to'] ?: $this->uid());
        if ($ownerId !== $this->uid() && ! in_array($ownerId, service('leadAccess')->assignableUserIds(), true) && ! service('authz')->can('crm.task.assign')) {
            $ownerId = $this->uid();
        }

        return $this->tx(function () use ($lead, $in, $outcome, $direction, $at, $next, $connected, $ownerId): int {
            $this->db->table('call_logs')->insert([
                'lead_id' => $lead['id'], 'called_at' => $at, 'direction' => $direction, 'person_spoken' => mb_substr(trim((string) ($in['person_spoken'] ?? '')), 0, 150) ?: null,
                'duration_seconds' => is_numeric($in['duration_minutes'] ?? null) ? (int) round((float) $in['duration_minutes'] * 60) : null,
                'outcome' => $outcome, 'is_connected' => $connected ? 1 : 0, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
                'next_action' => mb_substr(trim((string) ($in['next_action'] ?? '')), 0, 255) ?: null, 'next_action_at' => $next,
                'followup_owner_id' => $next ? $ownerId : null, 'caller_id' => $this->uid(),
            ]);
            $callId = (int) $this->db->insertID();
            $this->activity((int) $lead['id'], 'call', ($direction === 'inbound' ? 'Incoming call — ' : 'Call — ') . self::OUTCOMES[$outcome], trim((string) ($in['notes'] ?? '')) ?: null, 'call_log', $callId, $at);

            // The call fulfils this caller's open follow-ups that are due by now
            foreach ($this->db->table('followups')->where(['lead_id' => $lead['id'], 'status' => 'pending'])->where('due_at <=', date('Y-m-d 23:59:59'))->get()->getResultArray() as $f) {
                $this->db->table('followups')->where('id', $f['id'])->update(['status' => 'done', 'completed_at' => date('Y-m-d H:i:s'), 'completed_by' => $this->uid(), 'outcome_notes' => 'Call: ' . self::OUTCOMES[$outcome], 'updated_by' => $this->uid()]);
                $this->activity((int) $lead['id'], 'followup_done', 'Follow-up done: ' . $f['title'], null, 'followup', (int) $f['id']);
                $this->sync->enqueue('followups', (int) $f['id']);
            }
            if ($next !== null) {
                $this->addFollowup((int) $lead['id'], $ownerId, $next, trim((string) ($in['next_action'] ?? '')) ?: 'Follow-up call', $callId, ! empty($in['remind']));
            }

            // Stage: explicit choice wins; otherwise advance only forward from open stages
            $stages  = $this->stages();
            $current = $this->stageById((int) $lead['stage_id']);
            $target  = null;
            if (! empty($in['stage_id']) && ($s = $this->stageById((int) $in['stage_id'])) && (int) $s['is_won'] !== 1) {
                $target = $s;
            } elseif (isset($stages[self::OUTCOME_STAGE[$outcome]])) {
                $cand = $stages[self::OUTCOME_STAGE[$outcome]];
                $closedNow = $current !== null && (int) $current['is_closed'] === 1;
                $forward = $current === null || (int) $cand['sort_order'] > (int) $current['sort_order'] || (int) $cand['is_closed'] === 1;
                if (! $closedNow && $forward) {
                    $target = $cand;
                }
            }
            $upd = [
                'call_attempts' => (int) $lead['call_attempts'] + 1, 'connected_calls' => (int) $lead['connected_calls'] + ($connected ? 1 : 0),
                'last_action' => mb_substr(self::OUTCOMES[$outcome] . (trim((string) ($in['notes'] ?? '')) !== '' ? ': ' . trim((string) $in['notes']) : ''), 0, 255), 'last_action_at' => $at, 'updated_by' => $this->uid(),
            ];
            if ($target !== null && (int) $target['id'] !== (int) $lead['stage_id']) {
                $upd['stage_id'] = $target['id'];
                $this->activity((int) $lead['id'], 'stage_change', ($current['name'] ?? '—') . ' → ' . $target['name'], 'From call outcome', 'call_log', $callId);
            }
            if ($outcome === 'dnd_requested') {
                $upd += ['dnd' => 1, 'dnd_marked_at' => date('Y-m-d H:i:s'), 'consent_status' => 'withdrawn'];
                $this->closeOpenFollowups((int) $lead['id'], 'Customer asked not to be called');
            } elseif ($outcome === 'interested' && $lead['consent_status'] === 'unknown') {
                $upd['consent_status'] = 'consented';
            }
            $this->db->table('leads')->where('id', $lead['id'])->update($upd);
            $this->refreshNextFollowup((int) $lead['id']);

            $this->sync->enqueue('calls', $callId);
            $this->sync->enqueue('leads', (int) $lead['id']);
            $this->sync->enqueueDaily(substr($at, 0, 10), $this->uid());

            return $callId;
        });
    }

    // ---- follow-ups ----------------------------------------------------------

    public function addFollowup(int $leadId, int $ownerId, string $dueAt, string $title, ?int $callId = null, bool $remind = true): int
    {
        $this->db->table('followups')->insert([
            'lead_id' => $leadId, 'owner_id' => $ownerId, 'due_at' => $dueAt, 'title' => mb_substr($title, 0, 255), 'status' => 'pending',
            'source_call_id' => $callId, 'created_by' => $this->uid() ?: null,
        ]);
        $id = (int) $this->db->insertID();
        $this->activity($leadId, 'followup_set', 'Follow-up set for ' . dmy($dueAt, true) . ': ' . $title, null, 'followup', $id);
        if ($remind) {
            $this->db->table('task_reminders')->insert(['entity_type' => 'followup', 'entity_id' => $id, 'user_id' => $ownerId, 'remind_at' => date('Y-m-d H:i:s', strtotime($dueAt) - 600), 'channel' => 'in_app']);
        }
        $this->refreshNextFollowup($leadId);
        $this->sync->enqueue('followups', $id);
        $this->sync->enqueue('leads', $leadId);

        return $id;
    }

    /** @param array<string, mixed> $in */
    public function createFollowup(int $leadId, array $in): int
    {
        $lead = $this->find($leadId);
        $due  = ! empty($in['due_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['due_at'])) : null;
        if ($due === null) {
            throw new BusinessRuleException('Choose when to follow up.', ['due_at' => 'required']);
        }
        if ((int) $lead['dnd'] === 1) {
            throw new BusinessRuleException('This lead is Do-Not-Call; follow-up calls cannot be scheduled.');
        }
        $owner = (int) ($in['owner_id'] ?? 0) ?: (int) ($lead['assigned_to'] ?: $this->uid());
        if ($owner !== $this->uid() && ! service('authz')->can('crm.task.assign') && ! service('authz')->can('crm.lead.assign')) {
            $owner = $this->uid();
        }

        return $this->tx(fn () => $this->addFollowup($leadId, $owner, $due, trim((string) ($in['title'] ?? '')) ?: 'Follow-up', null, ! isset($in['remind']) || ! empty($in['remind'])));
    }

    public function completeFollowup(int $followupId, string $outcome, ?string $rescheduleTo = null): void
    {
        $f = $this->db->table('followups')->where('id', $followupId)->get()->getRowArray() ?? throw new BusinessRuleException('Follow-up not found.');
        $this->find((int) $f['lead_id']);
        if ($f['status'] !== 'pending') {
            throw new BusinessRuleException('This follow-up is already ' . $f['status'] . '.');
        }
        $this->tx(function () use ($f, $outcome, $rescheduleTo): void {
            if ($rescheduleTo !== null) {
                $due = date('Y-m-d H:i:s', strtotime($rescheduleTo));
                $this->db->table('followups')->where('id', $f['id'])->update(['due_at' => $due, 'outcome_notes' => $outcome ?: null, 'updated_by' => $this->uid()]);
                $this->db->table('task_reminders')->where(['entity_type' => 'followup', 'entity_id' => $f['id']])->where('sent_at', null)->update(['remind_at' => date('Y-m-d H:i:s', strtotime($due) - 600)]);
                $this->activity((int) $f['lead_id'], 'followup_set', 'Follow-up moved to ' . dmy($due, true), $outcome ?: null, 'followup', (int) $f['id']);
            } else {
                $this->db->table('followups')->where('id', $f['id'])->update(['status' => 'done', 'completed_at' => date('Y-m-d H:i:s'), 'completed_by' => $this->uid(), 'outcome_notes' => $outcome ?: null, 'updated_by' => $this->uid()]);
                $this->activity((int) $f['lead_id'], 'followup_done', 'Follow-up done: ' . $f['title'], $outcome ?: null, 'followup', (int) $f['id']);
                $this->sync->enqueueDaily(date('Y-m-d'), $this->uid());
            }
            $this->refreshNextFollowup((int) $f['lead_id']);
            $this->sync->enqueue('followups', (int) $f['id']);
            $this->sync->enqueue('leads', (int) $f['lead_id']);
        });
    }

    private function closeOpenFollowups(int $leadId, string $why): void
    {
        foreach ($this->db->table('followups')->select('id')->where(['lead_id' => $leadId, 'status' => 'pending'])->get()->getResultArray() as $f) {
            $this->db->table('followups')->where('id', $f['id'])->update(['status' => 'cancelled', 'outcome_notes' => $why, 'updated_by' => $this->uid() ?: null]);
            $this->sync->enqueue('followups', (int) $f['id']);
        }
        $this->db->table('leads')->where('id', $leadId)->update(['next_followup_at' => null]);
    }

    private function refreshNextFollowup(int $leadId): void
    {
        $next = $this->db->table('followups')->selectMin('due_at', 'd')->where(['lead_id' => $leadId, 'status' => 'pending'])->get()->getRow()->d ?? null;
        $this->db->table('leads')->where('id', $leadId)->update(['next_followup_at' => $next]);
    }

    // ---- visits & meetings --------------------------------------------------

    /** @param array<string, mixed> $in */
    public function scheduleVisit(array $in): int
    {
        $leadId = (int) ($in['lead_id'] ?? 0);
        $lead   = $leadId ? $this->find($leadId) : null;
        $when   = ! empty($in['scheduled_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['scheduled_at'])) : null;
        $visitor = (int) ($in['visitor_id'] ?? 0) ?: $this->uid();
        if ($when === null) {
            throw new BusinessRuleException('Choose the visit date and time.', ['scheduled_at' => 'required']);
        }
        if ($lead === null && empty($in['contact_id']) && trim((string) ($in['location'] ?? '')) === '') {
            throw new BusinessRuleException('Link the visit to a lead or client, or enter where it is.');
        }
        if ($visitor !== $this->uid() && ! service('authz')->can('crm.visit.assign')) {
            throw new AccessDeniedException();
        }

        return $this->tx(function () use ($in, $lead, $when, $visitor): int {
            $this->db->table('visits')->insert([
                'lead_id' => $lead['id'] ?? null, 'contact_id' => ((int) ($in['contact_id'] ?? 0)) ?: ($lead['contact_id'] ?? null), 'visitor_id' => $visitor, 'scheduled_at' => $when,
                'location' => mb_substr(trim((string) ($in['location'] ?? '')), 0, 200) ?: ($lead['company_name'] ?? null),
                'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 300) ?: ($lead['address'] ?? null),
                'purpose' => mb_substr(trim((string) ($in['purpose'] ?? '')), 0, 300) ?: null, 'status' => 'scheduled', 'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            $this->db->table('task_reminders')->insert(['entity_type' => 'visit', 'entity_id' => $id, 'user_id' => $visitor, 'remind_at' => date('Y-m-d H:i:s', strtotime($when) - 3600), 'channel' => 'in_app']);
            if ($lead !== null) {
                $name = $this->db->table('user_profiles')->select('full_name')->where('user_id', $visitor)->get()->getRow()->full_name ?? '';
                $this->activity((int) $lead['id'], 'visit', 'Visit scheduled ' . dmy($when, true) . ($name ? ' — ' . $name : ''), $in['purpose'] ?? null, 'visit', $id);
                if (isset($this->stages()['visit_scheduled']) && ($cur = $this->stageById((int) $lead['stage_id'])) && (int) $cur['is_closed'] === 0 && (int) $cur['sort_order'] < (int) $this->stages()['visit_scheduled']['sort_order']) {
                    $this->db->table('leads')->where('id', $lead['id'])->update(['stage_id' => $this->stages()['visit_scheduled']['id']]);
                }
                $this->db->table('leads')->where('id', $lead['id'])->update(['last_action' => 'Visit scheduled', 'last_action_at' => date('Y-m-d H:i:s')]);
                $this->sync->enqueue('leads', (int) $lead['id']);
            }
            $this->sync->enqueue('visits', $id);
            if ($visitor !== $this->uid()) {
                service('notifier')->notify($visitor, 'crm', 'Visit assigned: ' . ($lead['company_name'] ?? $in['location'] ?? ''), dmy($when, true) . ($in['purpose'] ? ' — ' . $in['purpose'] : ''), site_url('crm/visits/' . $id), 'visit', $id);
            }

            return $id;
        });
    }

    /** Record what happened at the visit, client feedback and the next action. */
    public function completeVisit(int $visitId, array $in): void
    {
        $v = $this->db->table('visits')->where('id', $visitId)->get()->getRowArray() ?? throw new BusinessRuleException('Visit not found.');
        if ($v['status'] !== 'scheduled' && $v['status'] !== 'rescheduled') {
            throw new BusinessRuleException('This visit is already ' . $v['status'] . '.');
        }
        $status = in_array($in['status'] ?? 'completed', ['completed', 'cancelled'], true) ? ($in['status'] ?? 'completed') : 'completed';
        if ($status === 'completed' && trim((string) ($in['outcome'] ?? '')) === '') {
            throw new BusinessRuleException('Record the visit outcome.', ['outcome' => 'required']);
        }
        $next = ! empty($in['next_action_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['next_action_at'])) : null;
        $this->tx(function () use ($v, $in, $status, $next): void {
            $this->db->table('visits')->where('id', $v['id'])->update([
                'status' => $status, 'outcome' => trim((string) ($in['outcome'] ?? '')) ?: null, 'client_feedback' => trim((string) ($in['client_feedback'] ?? '')) ?: null,
                'next_action' => mb_substr(trim((string) ($in['next_action'] ?? '')), 0, 255) ?: null, 'next_action_at' => $next,
                'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null, 'updated_by' => $this->uid(),
            ]);
            if ($v['lead_id']) {
                $this->activity((int) $v['lead_id'], 'visit', 'Visit ' . $status . ($in['outcome'] ? ': ' . mb_strimwidth((string) $in['outcome'], 0, 120, '…') : ''), trim((string) ($in['client_feedback'] ?? '')) ? 'Client feedback: ' . $in['client_feedback'] : null, 'visit', (int) $v['id']);
                $lead = $this->db->table('leads')->where('id', $v['lead_id'])->get()->getRowArray();
                if ($next !== null && $lead && (int) $lead['dnd'] === 0) {
                    $this->addFollowup((int) $v['lead_id'], (int) ($lead['assigned_to'] ?: $this->uid()), $next, trim((string) ($in['next_action'] ?? '')) ?: 'After visit', null, true);
                }
                $this->db->table('leads')->where('id', $v['lead_id'])->update(['last_action' => 'Visit ' . $status, 'last_action_at' => date('Y-m-d H:i:s')]);
                $this->sync->enqueue('leads', (int) $v['lead_id']);
            }
            $this->db->table('task_reminders')->where(['entity_type' => 'visit', 'entity_id' => $v['id']])->where('sent_at', null)->update(['cancelled_at' => date('Y-m-d H:i:s')]);
            $this->sync->enqueue('visits', (int) $v['id']);
            if ($status === 'completed') {
                $this->sync->enqueueDaily(date('Y-m-d'), (int) $v['visitor_id']);
            }
        });
    }

    /** @param array<string, mixed> $in */
    public function scheduleMeeting(array $in): int
    {
        $leadId = (int) ($in['lead_id'] ?? 0);
        $lead   = $leadId ? $this->find($leadId) : null;
        $when   = ! empty($in['meeting_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['meeting_at'])) : null;
        if ($when === null || trim((string) ($in['title'] ?? '')) === '') {
            throw new BusinessRuleException('Enter a title and the meeting time.');
        }
        $attendees = array_values(array_unique(array_filter(array_map('intval', (array) ($in['attendee_ids'] ?? [])))));

        return $this->tx(function () use ($in, $lead, $when, $attendees): int {
            $this->db->table('meetings')->insert([
                'lead_id' => $lead['id'] ?? null, 'contact_id' => ((int) ($in['contact_id'] ?? 0)) ?: ($lead['contact_id'] ?? null), 'title' => mb_substr(trim((string) $in['title']), 0, 200),
                'meeting_at' => $when, 'duration_minutes' => max(5, min(600, (int) ($in['duration_minutes'] ?? 30))),
                'mode' => in_array($in['mode'] ?? '', ['in_person', 'phone', 'video'], true) ? $in['mode'] : 'in_person',
                'location_or_link' => mb_substr(trim((string) ($in['location_or_link'] ?? '')), 0, 300) ?: null, 'organizer_id' => $this->uid(),
                'attendee_ids' => json_encode($attendees), 'status' => 'scheduled', 'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            foreach (array_unique(array_merge([$this->uid()], $attendees)) as $u) {
                $this->db->table('task_reminders')->insert(['entity_type' => 'meeting', 'entity_id' => $id, 'user_id' => $u, 'remind_at' => date('Y-m-d H:i:s', strtotime($when) - 900), 'channel' => 'in_app']);
            }
            if ($lead !== null) {
                $this->activity((int) $lead['id'], 'meeting', 'Meeting scheduled ' . dmy($when, true) . ': ' . $in['title'], null, 'meeting', $id);
                if (isset($this->stages()['meeting_scheduled']) && ($cur = $this->stageById((int) $lead['stage_id'])) && (int) $cur['is_closed'] === 0 && (int) $cur['sort_order'] < (int) $this->stages()['meeting_scheduled']['sort_order']) {
                    $this->db->table('leads')->where('id', $lead['id'])->update(['stage_id' => $this->stages()['meeting_scheduled']['id']]);
                }
                $this->db->table('leads')->where('id', $lead['id'])->update(['last_action' => 'Meeting scheduled', 'last_action_at' => date('Y-m-d H:i:s')]);
                $this->sync->enqueue('leads', (int) $lead['id']);
            }
            $this->sync->enqueueDaily(date('Y-m-d'), $this->uid());

            return $id;
        });
    }

    public function completeMeeting(int $id, string $status, string $outcome): void
    {
        $m = $this->db->table('meetings')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Meeting not found.');
        if ($m['status'] !== 'scheduled') {
            throw new BusinessRuleException('This meeting is already ' . $m['status'] . '.');
        }
        $status = $status === 'cancelled' ? 'cancelled' : 'completed';
        $this->tx(function () use ($m, $status, $outcome): void {
            $this->db->table('meetings')->where('id', $m['id'])->update(['status' => $status, 'outcome' => $outcome ?: null, 'updated_by' => $this->uid()]);
            if ($m['lead_id']) {
                $this->activity((int) $m['lead_id'], 'meeting', 'Meeting ' . $status . ': ' . $m['title'], $outcome ?: null, 'meeting', (int) $m['id']);
                $this->sync->enqueue('leads', (int) $m['lead_id']);
            }
        });
    }

    // ---- tasks ----------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public function createTask(array $in): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new BusinessRuleException('Enter the task.', ['title' => 'required']);
        }
        $assignee = (int) ($in['assigned_to'] ?? 0) ?: $this->uid();
        if ($assignee !== $this->uid() && ! service('authz')->can('crm.task.assign')) {
            throw new AccessDeniedException();
        }
        if ($this->db->table('users')->where(['id' => $assignee, 'active' => 1, 'deleted_at' => null])->countAllResults() === 0) {
            throw new BusinessRuleException('Choose an active user.');
        }
        $leadId = (int) ($in['lead_id'] ?? 0);
        if ($leadId) {
            $this->find($leadId);
        }
        $due = ! empty($in['due_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['due_at'])) : null;

        return $this->tx(function () use ($in, $title, $assignee, $leadId, $due): int {
            $this->db->table('tasks')->insert([
                'title' => mb_substr($title, 0, 255), 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'assigned_to' => $assignee, 'assigned_by' => $this->uid(),
                'due_at' => $due, 'priority' => in_array($in['priority'] ?? '', ['low', 'medium', 'high'], true) ? $in['priority'] : 'medium', 'status' => 'pending',
                'related_type' => $leadId ? 'lead' : (trim((string) ($in['related_type'] ?? '')) ?: null), 'related_id' => $leadId ?: (((int) ($in['related_id'] ?? 0)) ?: null),
                'created_by' => $this->uid(),
            ]);
            $id = (int) $this->db->insertID();
            if ($due !== null) {
                $this->db->table('task_reminders')->insert(['entity_type' => 'task', 'entity_id' => $id, 'user_id' => $assignee, 'remind_at' => date('Y-m-d H:i:s', strtotime($due) - 1800), 'channel' => 'in_app']);
            }
            if ($leadId) {
                $this->activity($leadId, 'task', 'Task: ' . $title, null, 'task', $id);
            }
            if ($assignee !== $this->uid()) {
                service('notifier')->notify($assignee, 'task', 'New task: ' . $title, $due ? 'Due ' . dmy($due, true) : null, site_url('crm/tasks?mine=1'), 'task', $id);
            }

            return $id;
        });
    }

    public function updateTaskStatus(int $taskId, string $status, string $notes = ''): void
    {
        $t = $this->db->table('tasks')->where('id', $taskId)->get()->getRowArray() ?? throw new BusinessRuleException('Task not found.');
        $uid = $this->uid();
        if (! in_array($uid, [(int) $t['assigned_to'], (int) $t['assigned_by'], (int) $t['created_by']], true) && ! service('authz')->can('crm.task.view_all')) {
            throw new AccessDeniedException();
        }
        if (! in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
            throw new BusinessRuleException('Unknown status.');
        }
        $this->tx(function () use ($t, $status, $notes, $uid): void {
            $this->db->table('tasks')->where('id', $t['id'])->update([
                'status' => $status, 'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
                'completion_notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : $t['completion_notes'], 'updated_by' => $uid,
            ]);
            if (in_array($status, ['completed', 'cancelled'], true)) {
                $this->db->table('task_reminders')->where(['entity_type' => 'task', 'entity_id' => $t['id']])->where('sent_at', null)->update(['cancelled_at' => date('Y-m-d H:i:s')]);
            }
            if ($t['related_type'] === 'lead' && $t['related_id']) {
                $this->activity((int) $t['related_id'], 'task', 'Task ' . str_replace('_', ' ', $status) . ': ' . $t['title'], $notes ?: null, 'task', (int) $t['id']);
            }
        });
        if ($status === 'completed' && (int) $t['assigned_by'] !== $uid && $t['assigned_by']) {
            service('notifier')->notify((int) $t['assigned_by'], 'task', 'Task completed: ' . $t['title'], $notes ?: null, site_url('crm/tasks'), 'task', (int) $t['id']);
        }
    }

    // ---- conversion -------------------------------------------------------------

    /**
     * Convert a lead into a client (new or existing), optionally creating an
     * estimate/invoice DRAFT. Never creates an incentive payout by itself.
     *
     * @param array<string, mixed> $in
     *
     * @return array{contact_id: int, document_id: int|null, conversion_id: int}
     */
    public function convert(int $leadId, array $in): array
    {
        $lead = $this->find($leadId);
        if ($lead['converted_at'] !== null) {
            throw new BusinessRuleException('This lead was already converted on ' . dmy($lead['converted_at'], true) . '.');
        }
        $docType = in_array($in['create_document'] ?? 'none', ['estimate', 'quotation', 'proforma', 'tax_invoice'], true) ? $in['create_document'] : null;
        $attributed = (int) ($in['attributed_user_id'] ?? 0) ?: (int) ($lead['assigned_to'] ?: $this->uid());

        return $this->tx(function () use ($lead, $in, $docType, $attributed): array {
            $contactId = (int) ($in['contact_id'] ?? 0);
            if ($contactId > 0) {
                if ($this->db->table('contacts')->where(['id' => $contactId, 'deleted_at' => null])->countAllResults() === 0) {
                    throw new BusinessRuleException('Choose an existing client.');
                }
                $this->db->table('contacts')->where('id', $contactId)->update(['is_customer' => 1]);
            } else {
                $contactId = (new \App\Services\Contacts\ContactService($this->db))->create([
                    'name' => $lead['company_name'], 'contact_person' => $lead['contact_person'], 'phone' => $lead['mobile'], 'alt_phone' => $lead['alt_mobile'], 'email' => $lead['email'],
                    'gstin' => $in['gstin'] ?? null, 'state_code' => $in['state_code'] ?? null, 'billing_line1' => $lead['address'], 'city' => $lead['city'], 'pincode' => $lead['pincode'],
                    'industry' => $lead['industry'], 'source' => 'CRM ' . $lead['lead_code'], 'is_customer' => 1,
                ], ! empty($in['allow_duplicate']))['id'];
            }
            $docId = null;
            if ($docType !== null) {
                $lines = [];
                foreach (array_filter(array_map('intval', (array) ($in['service_ids'] ?? []))) as $sid) {
                    $s = $this->db->table('services s')->select('s.*, t.rate AS gst_rate')->join('tax_rates t', 't.id = s.default_tax_rate_id', 'left')->where('s.id', $sid)->get()->getRowArray();
                    if ($s) {
                        $lines[] = ['service_id' => $s['id'], 'item_name' => $s['name'], 'description' => $s['description'], 'sac_code' => $s['sac_code'], 'qty' => '1', 'unit' => $s['unit'],
                            'unit_rate' => (string) ($s['default_rate'] ?? '0'), 'tax_treatment' => $s['default_tax_treatment'] ?: 'regular', 'gst_rate' => (string) ($s['gst_rate'] ?? '18')];
                    }
                }
                if ($lines === []) {
                    $lines[] = ['item_name' => 'Digital marketing services (as discussed)', 'qty' => '1', 'unit_rate' => (string) ((float) ($in['contract_value'] ?? $lead['expected_value'] ?? 0)), 'tax_treatment' => 'regular', 'gst_rate' => '18', 'sac_code' => '998361'];
                }
                $docId = service('billing')->saveDraft(['doc_type' => $docType, 'contact_id' => $contactId, 'lines' => $lines, 'notes' => 'From lead ' . $lead['lead_code']]);
                $this->db->table('billing_documents')->where('id', $docId)->update(['lead_id' => $lead['id']]);
            }
            $value = is_numeric(str_replace(',', '', (string) ($in['contract_value'] ?? ''))) ? number_format((float) str_replace(',', '', (string) $in['contract_value']), 2, '.', '') : $lead['expected_value'];
            $this->db->table('lead_conversion_links')->insert([
                'lead_id' => $lead['id'], 'contact_id' => $contactId, 'document_id' => $docId, 'attributed_user_id' => $attributed,
                'attribution' => json_encode([['user_id' => $attributed, 'share' => 100]]), 'contract_value' => $value, 'converted_by' => $this->uid(), 'converted_at' => date('Y-m-d H:i:s'),
            ]);
            $convId = (int) $this->db->insertID();
            $this->db->table('leads')->where('id', $lead['id'])->update([
                'stage_id' => $this->stages()['won']['id'] ?? $lead['stage_id'], 'contact_id' => $contactId, 'converted_at' => date('Y-m-d H:i:s'),
                'last_action' => 'Converted to client', 'last_action_at' => date('Y-m-d H:i:s'), 'updated_by' => $this->uid(),
            ]);
            $this->closeOpenFollowups((int) $lead['id'], 'Converted');
            $this->activity((int) $lead['id'], 'conversion', 'Converted to client' . ($docId ? ' with ' . doc_type_label($docType) . ' draft' : ''), null, 'contact', $contactId);
            $this->sync->enqueue('conversions', $convId);
            $this->sync->enqueue('leads', (int) $lead['id']);
            $this->sync->enqueueDaily(date('Y-m-d'), $attributed);
            service('audit')->log('lead.converted', 'lead', (int) $lead['id'], null, ['contact_id' => $contactId, 'document_id' => $docId, 'attributed_to' => $attributed], 'Lead converted: ' . $lead['company_name']);

            return ['contact_id' => $contactId, 'document_id' => $docId, 'conversion_id' => $convId];
        });
    }
}
