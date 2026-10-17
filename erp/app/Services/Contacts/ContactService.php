<?php

namespace App\Services\Contacts;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Gstin;
use CodeIgniter\Database\BaseConnection;

/**
 * Unified client / vendor / prospect master.
 */
class ContactService
{
    /** Fields a billing operator may receive (no notes, balances, tags, history). */
    public const BILLING_FIELDS = ['id', 'contact_code', 'name', 'display_name', 'legal_name', 'contact_person', 'phone', 'email',
        'gstin', 'gst_registration_type', 'state_code', 'city', 'pincode', 'credit_days'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $in raw form/import input
     *
     * @return array{data: array<string, mixed>, billing: array<string, mixed>|null, service: array<string, mixed>|null, people: list<array<string, mixed>>, warnings: list<string>}
     *
     * @throws BusinessRuleException
     */
    public function normalize(array $in, ?array $existing = null): array
    {
        $s = static fn ($k) => isset($in[$k]) && trim((string) $in[$k]) !== '' ? trim((string) $in[$k]) : null;
        $errors   = [];
        $warnings = [];

        $name = $s('name');
        if ($name === null) {
            $errors['name'] = 'Name is required.';
        }
        $isCustomer = ! empty($in['is_customer']);
        $isVendor   = ! empty($in['is_vendor']);
        $isProspect = ! empty($in['is_prospect']);
        if (! $isCustomer && ! $isVendor && ! $isProspect) {
            $isCustomer = true;
        }

        $gstin   = Gstin::normalize($s('gstin'));
        $regType = in_array($s('gst_registration_type'), ['registered', 'unregistered', 'composition', 'consumer', 'sez', 'overseas', 'uin'], true) ? $s('gst_registration_type') : null;
        $suggestedState = null;
        if ($gstin !== null) {
            if ($regType === 'uin') {
                if (! preg_match('/^[0-9A-Z]{15}$/', $gstin)) {
                    $errors['gstin'] = 'UIN must be 15 letters/digits.';
                }
            } else {
                $v = Gstin::validate($gstin);
                if (! $v['valid']) {
                    $errors['gstin'] = $v['error'];
                } else {
                    $suggestedState = $v['state_code'];
                }
            }
            if ($regType === null || in_array($regType, ['unregistered', 'consumer'], true)) {
                $regType = 'registered';
            }
        } elseif ($regType === null || in_array($regType, ['registered', 'composition', 'sez'], true)) {
            if (in_array($regType, ['registered', 'composition', 'sez'], true)) {
                $errors['gstin'] = 'GSTIN is required for a registered / composition / SEZ customer.';
            }
            $regType ??= 'unregistered';
        }

        $pan = $s('pan') ? strtoupper((string) $s('pan')) : null;
        if ($pan === null && $gstin !== null && $regType !== 'uin') {
            $pan = substr($gstin, 2, 10);
        }
        if ($pan !== null && ! Gstin::validPan($pan)) {
            $errors['pan'] = 'PAN format is invalid (e.g. ABCDE1234F).';
        }

        $state       = $s('state_code');
        $stateSource = 'manual';
        if ($state === null && $suggestedState !== null) {
            $state       = $suggestedState;
            $stateSource = 'gstin';
        } elseif ($state !== null && $suggestedState !== null) {
            if ($state === $suggestedState) {
                $stateSource = 'gstin';
            } else {
                $reason = $s('state_override_reason');
                if ($reason === null) {
                    $errors['state_code'] = "GSTIN belongs to state {$suggestedState}. To use a different state, give a reason (it will be audited).";
                } else {
                    $warnings[] = "State overridden from GSTIN state {$suggestedState} to {$state}: {$reason}";
                }
            }
        }
        if ($state !== null && $this->db->table('states')->where('code', $state)->countAllResults() === 0) {
            $errors['state_code'] = 'Unknown state code.';
        }
        if ($state === null && $regType !== 'overseas') {
            $warnings[] = 'No state set — place of supply will have to be chosen manually on each invoice.';
        }

        $email = $s('email');
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }
        $pin = $s('pincode');
        if ($pin !== null && ! preg_match('/^[1-9][0-9]{5}$/', $pin)) {
            $errors['pincode'] = 'PIN code must be 6 digits.';
        }
        $phone = $s('phone');
        if ($phone !== null && strlen((string) normalize_phone($phone)) < 10) {
            $errors['phone'] = 'Phone must have at least 10 digits.';
        }

        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }

        $data = [
            'is_customer'           => $isCustomer ? 1 : 0,
            'is_vendor'             => $isVendor ? 1 : 0,
            'is_prospect'           => $isProspect ? 1 : 0,
            'entity_kind'           => $s('entity_kind') === 'individual' ? 'individual' : 'company',
            'name'                  => $name,
            'display_name'          => $s('display_name'),
            'legal_name'            => $s('legal_name'),
            'contact_person'        => $s('contact_person'),
            'phone'                 => $phone,
            'phone_normalized'      => normalize_phone($phone),
            'alt_phone'             => $s('alt_phone'),
            'whatsapp'              => $s('whatsapp'),
            'email'                 => $email,
            'gstin'                 => $gstin,
            'gst_registration_type' => $regType,
            'pan'                   => $pan,
            'state_code'            => $state,
            'state_source'          => $state === null ? null : $stateSource,
            'city'                  => $s('city'),
            'district'              => $s('district'),
            'pincode'               => $pin,
            'industry'              => $s('industry'),
            'source'                => $s('source'),
            'category'              => $s('category'),
            'tags'                  => $s('tags'),
            'credit_days'           => $s('credit_days') !== null ? max(0, min(365, (int) $s('credit_days'))) : null,
            'credit_limit'          => $s('credit_limit') !== null ? number_format((float) str_replace(',', '', (string) $s('credit_limit')), 2, '.', '') : null,
            'opening_balance'       => number_format((float) str_replace(',', '', (string) ($s('opening_balance') ?? '0')), 2, '.', ''),
            'opening_balance_type'  => $s('opening_balance_type') === 'cr' ? 'cr' : 'dr',
            'notes'                 => $s('notes'),
            'dnd'                   => ! empty($in['dnd']) ? 1 : 0,
        ];

        $billing = $s('billing_line1') !== null ? [
            'address_type' => 'billing', 'line1' => $s('billing_line1'), 'line2' => $s('billing_line2'),
            'city' => $s('billing_city') ?? $data['city'], 'district' => $s('billing_district') ?? $data['district'],
            'state_code' => $s('billing_state_code') ?? $state, 'pincode' => $s('billing_pincode') ?? $pin, 'is_default' => 1,
        ] : null;
        $service = $s('service_line1') !== null ? [
            'address_type' => 'service', 'line1' => $s('service_line1'), 'line2' => $s('service_line2'),
            'city' => $s('service_city'), 'district' => null, 'state_code' => $s('service_state_code'), 'pincode' => $s('service_pincode'), 'is_default' => 0,
        ] : null;
        $people = [];
        foreach ((array) ($in['people'] ?? []) as $p) {
            if (trim((string) ($p['name'] ?? '')) !== '') {
                $people[] = ['name' => trim((string) $p['name']), 'designation' => trim((string) ($p['designation'] ?? '')) ?: null,
                    'phone' => trim((string) ($p['phone'] ?? '')) ?: null, 'email' => trim((string) ($p['email'] ?? '')) ?: null, 'is_primary' => ! empty($p['is_primary']) ? 1 : 0];
            }
        }

        return ['data' => $data, 'billing' => $billing, 'service' => $service, 'people' => $people, 'warnings' => $warnings];
    }

    /** @return list<array<string, mixed>> possible duplicates by GSTIN or normalised phone */
    public function duplicates(?string $gstin, ?string $phone, ?int $excludeId = null): array
    {
        $gstin = Gstin::normalize($gstin);
        $norm  = normalize_phone($phone);
        if ($gstin === null && $norm === null) {
            return [];
        }
        $b = $this->db->table('contacts')->select('id, contact_code, name, gstin, phone, city')->where('deleted_at', null)->where('merged_into_id', null);
        $b->groupStart();
        if ($gstin !== null) {
            $b->where('gstin', $gstin);
        }
        if ($norm !== null) {
            $gstin !== null ? $b->orWhere('phone_normalized', $norm) : $b->where('phone_normalized', $norm);
        }
        $b->groupEnd();
        if ($excludeId !== null) {
            $b->where('id !=', $excludeId);
        }

        return $b->limit(10)->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array{id: int, warnings: list<string>}
     */
    public function create(array $in, bool $allowDuplicate = false): array
    {
        $n = $this->normalize($in);
        if (! $allowDuplicate) {
            $dupes = $this->duplicates($n['data']['gstin'], $n['data']['phone'], null);
            if ($dupes !== []) {
                $list = implode(', ', array_map(static fn ($d) => $d['contact_code'] . ' ' . $d['name'], $dupes));

                throw new BusinessRuleException('Possible duplicate of: ' . $list . '. Open the existing record, or tick "Save anyway" if this is genuinely a different party.', ['duplicate' => $list]);
            }
        }
        $uid = service('authz')->currentUserId();
        $this->db->transBegin();
        try {
            $this->db->table('contacts')->insert($n['data'] + ['contact_code' => 'TMP-' . bin2hex(random_bytes(6)), 'created_by' => $uid]);
            $id = (int) $this->db->insertID();
            $this->db->table('contacts')->where('id', $id)->update(['contact_code' => 'C' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
            $this->saveChildren($id, $n);
            service('audit')->log('contact.created', 'contact', $id, null, $n['data'], 'Contact created: ' . $n['data']['name']);
            foreach ($n['warnings'] as $w) {
                if (str_starts_with($w, 'State overridden')) {
                    service('audit')->log('contact.state_override', 'contact', $id, null, ['state_code' => $n['data']['state_code']], $w);
                }
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return ['id' => $id, 'warnings' => $n['warnings']];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return list<string> warnings
     */
    public function update(int $id, array $in): array
    {
        $before = $this->db->table('contacts')->where('id', $id)->get()->getRowArray() ?? throw new BusinessRuleException('Contact not found.');
        $n      = $this->normalize($in, $before);
        unset($n['data']['opening_balance'], $n['data']['opening_balance_type']); // opening balance changes go through finance
        $this->db->transBegin();
        try {
            $this->db->table('contacts')->where('id', $id)->update($n['data'] + ['updated_by' => service('authz')->currentUserId()]);
            $this->saveChildren($id, $n, true);
            service('audit')->log('contact.updated', 'contact', $id, $before, $n['data'], 'Contact updated: ' . $n['data']['name']);
            if ($before['state_code'] !== $n['data']['state_code']) {
                service('audit')->log('contact.state_changed', 'contact', $id, ['state_code' => $before['state_code']], ['state_code' => $n['data']['state_code']], 'Tax state changed' . ($n['warnings'] ? ': ' . implode('; ', $n['warnings']) : ''));
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $n['warnings'];
    }

    /** @param array<string, mixed> $n */
    private function saveChildren(int $id, array $n, bool $replace = false): void
    {
        foreach (['billing', 'service'] as $type) {
            if ($n[$type] === null) {
                continue;
            }
            $existing = $this->db->table('contact_addresses')->where(['contact_id' => $id, 'address_type' => $type])->orderBy('is_default', 'DESC')->get()->getRowArray();
            if ($existing !== null) {
                $this->db->table('contact_addresses')->where('id', $existing['id'])->update($n[$type]);
            } else {
                $this->db->table('contact_addresses')->insert($n[$type] + ['contact_id' => $id]);
            }
        }
        if ($replace || $n['people'] !== []) {
            if ($replace) {
                $this->db->table('contact_people')->where('contact_id', $id)->delete();
            }
            foreach ($n['people'] as $p) {
                $this->db->table('contact_people')->insert($p + ['contact_id' => $id]);
            }
        }
    }

    /** Billing-safe projection with formatted billing address. @return array<string, mixed>|null */
    public function billingView(int $id): ?array
    {
        $c = $this->db->table('contacts')->select(implode(',', self::BILLING_FIELDS))->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($c === null) {
            return null;
        }
        $addr = $this->db->table('contact_addresses')->where(['contact_id' => $id, 'address_type' => 'billing'])->orderBy('is_default', 'DESC')->get()->getRowArray();
        $state = $this->db->table('states')->select('name')->where('code', $addr['state_code'] ?? $c['state_code'])->get()->getRowArray();
        $lines = $addr ? array_filter([$addr['line1'], $addr['line2'], trim(($addr['city'] ?? '') . ' ' . ($addr['pincode'] ?? '')), $state['name'] ?? null])
            : array_filter([trim(($c['city'] ?? '') . ' ' . ($c['pincode'] ?? '')), $state['name'] ?? null]);
        $c['billing_address']    = implode("\n", $lines);
        $c['billing_state_code'] = $addr['state_code'] ?? $c['state_code'];
        $c['customer_type']      = $c['gst_registration_type'] === 'sez' ? 'sez' : ($c['gst_registration_type'] === 'overseas' ? 'export' : ($c['gstin'] ? 'b2b' : 'b2c'));

        return $c;
    }

    /** Merge $sourceId into $targetId, re-pointing every reference. */
    public function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new BusinessRuleException('Choose two different contacts to merge.');
        }
        $src = $this->db->table('contacts')->where('id', $sourceId)->where('deleted_at', null)->get()->getRowArray() ?? throw new BusinessRuleException('Source contact not found.');
        $tgt = $this->db->table('contacts')->where('id', $targetId)->where('deleted_at', null)->get()->getRowArray() ?? throw new BusinessRuleException('Target contact not found.');
        if ($src['gstin'] && $tgt['gstin'] && $src['gstin'] !== $tgt['gstin']) {
            throw new BusinessRuleException('These contacts have different GSTINs (' . $src['gstin'] . ' vs ' . $tgt['gstin'] . ') and are different registrations — they cannot be merged.');
        }
        $refs = [
            ['billing_documents', 'contact_id'], ['payment_receipts', 'contact_id'], ['journal_lines', 'contact_id'],
            ['expenses', 'vendor_contact_id'], ['expenses', 'project_contact_id'], ['recurring_expenses', 'vendor_contact_id'],
            ['recurring_billing_profiles', 'contact_id'], ['leads', 'contact_id'], ['visits', 'contact_id'], ['meetings', 'contact_id'],
            ['lead_conversion_links', 'contact_id'], ['incentive_entries', 'contact_id'],
            ['contact_addresses', 'contact_id'], ['contact_people', 'contact_id'], ['contact_files', 'contact_id'],
        ];
        $moved = [];
        $this->db->transBegin();
        try {
            foreach ($refs as [$table, $col]) {
                $this->db->table($table)->where($col, $sourceId)->update([$col => $targetId]);
                $moved[$table . '.' . $col] = $this->db->affectedRows();
            }
            $this->db->table('contacts')->where('id', $targetId)->update([
                'is_customer' => (int) ($src['is_customer'] || $tgt['is_customer']),
                'is_vendor'   => (int) ($src['is_vendor'] || $tgt['is_vendor']),
                'is_prospect' => (int) ($src['is_prospect'] || $tgt['is_prospect']),
                'gstin'       => $tgt['gstin'] ?? $src['gstin'],
                'phone'       => $tgt['phone'] ?? $src['phone'],
                'phone_normalized' => $tgt['phone_normalized'] ?? $src['phone_normalized'],
                'email'       => $tgt['email'] ?? $src['email'],
                'opening_balance' => number_format((float) $tgt['opening_balance'] * ($tgt['opening_balance_type'] === 'cr' ? -1 : 1) + (float) $src['opening_balance'] * ($src['opening_balance_type'] === 'cr' ? -1 : 1), 2, '.', ''),
                'updated_by'  => service('authz')->currentUserId(),
            ]);
            $ob = (float) $this->db->table('contacts')->select('opening_balance')->where('id', $targetId)->get()->getRow()->opening_balance;
            $this->db->table('contacts')->where('id', $targetId)->update(['opening_balance' => number_format(abs($ob), 2, '.', ''), 'opening_balance_type' => $ob < 0 ? 'cr' : 'dr']);
            $this->db->table('contacts')->where('id', $sourceId)->update([
                'merged_into_id' => $targetId, 'is_active' => 0, 'deleted_at' => date('Y-m-d H:i:s'),
                'gstin' => null, 'phone_normalized' => null, 'updated_by' => service('authz')->currentUserId(),
            ]);
            $this->db->table('contact_merges')->insert([
                'source_contact_id' => $sourceId, 'target_contact_id' => $targetId,
                'snapshot' => json_encode(['source' => $src, 'moved' => $moved]), 'merged_by' => service('authz')->currentUserId(),
            ]);
            service('audit')->log('contact.merged', 'contact', $targetId, ['merged_from' => $src['contact_code'] . ' ' . $src['name']], ['moved' => $moved], $src['contact_code'] . ' merged into ' . $tgt['contact_code']);
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }
}
