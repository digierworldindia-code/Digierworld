<?php

namespace App\Controllers\Crm;

use App\Exceptions\BusinessRuleException;
use App\Libraries\SheetIO;

/** Bulk lead import with header mapping, duplicate detection by normalized phone and an error file. */
class ImportController extends CrmBase
{
    private const FIELDS = [
        'company_name' => ['company', 'business', 'company_name', 'business_name', 'lead', 'name', 'firm'],
        'contact_person' => ['contact_person', 'contact', 'person', 'owner_name', 'contact_name'],
        'mobile' => ['mobile', 'phone', 'mobile_no', 'phone_number', 'contact_number', 'number', 'mobile_number'],
        'alt_mobile' => ['alt_mobile', 'alternate', 'alternate_mobile', 'phone_2', 'mobile_2'],
        'email' => ['email', 'email_id', 'mail'],
        'address' => ['address', 'full_address'],
        'area' => ['area', 'locality'],
        'city' => ['city', 'town', 'district'],
        'pincode' => ['pincode', 'pin', 'pin_code', 'zip'],
        'industry' => ['industry', 'category', 'business_category', 'field'],
        'services_interested' => ['services', 'service', 'services_interested', 'service_interest', 'requirement'],
        'source' => ['source', 'lead_source'],
        'campaign' => ['campaign'],
        'priority' => ['priority'],
        'assigned' => ['assigned', 'assigned_to', 'telecaller', 'owner'],
        'remarks' => ['remarks', 'remark', 'notes', 'comment', 'comments'],
    ];

    public function form()
    {
        return $this->render('crm/import', ['pageTitle' => 'Import leads', 'breadcrumbs' => [['CRM'], ['Leads', 'crm/leads'], ['Import']], 'preview' => null, 'fields' => array_keys(self::FIELDS)]);
    }

    public function preview()
    {
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->with('error', 'Choose a .csv or .xlsx file.');
        }
        try {
            $fid = service('files')->storeUpload($file, 'sheet', 'import');
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('crm/leads/import/review?file=' . $fid));
    }

    /** @return array{0: list<array<string, string>>, 1: array<string, string>, 2: string} rows, header→field map, file name */
    private function read(int $fid): array
    {
        $f = service('files')->find($fid);
        if ($f === null || $f['category'] !== 'import' || (int) $f['uploaded_by'] !== (int) $this->userId) {
            $this->notFound();
        }
        $data = SheetIO::read(service('files')->path($f), $f['original_name']);
        $map  = [];
        foreach ($data['headers'] as $h) {
            foreach (self::FIELDS as $field => $aliases) {
                if (in_array($h, $aliases, true) && ! in_array($field, $map, true)) {
                    $map[$h] = $field;
                    break;
                }
            }
        }

        return [$data['rows'], $map, $f['original_name']];
    }

    /** @return list<array<string, mixed>> */
    private function analyse(array $rows, array $map): array
    {
        $seen = [];
        $out  = [];
        $existing = [];
        $phones = array_filter(array_map(static function ($r) use ($map) {
            $k = array_search('mobile', $map, true);

            return $k === false ? null : normalize_phone($r[$k] ?? '');
        }, $rows));
        foreach (array_chunk(array_values(array_unique($phones)), 500) as $chunk) {
            foreach ($this->db->table('leads')->select('mobile_normalized, lead_code')->whereIn('mobile_normalized', $chunk)->where('deleted_at', null)->get()->getResultArray() as $e) {
                $existing[$e['mobile_normalized']] = $e['lead_code'];
            }
        }
        foreach ($rows as $i => $r) {
            $rec = [];
            foreach ($map as $h => $field) {
                $rec[$field] = trim((string) ($r[$h] ?? ''));
            }
            $phone  = normalize_phone($rec['mobile'] ?? '');
            $status = 'ok';
            $note   = '';
            if ($phone === null || strlen($phone) < 10) {
                [$status, $note] = ['error', 'Missing or invalid mobile'];
            } elseif (($rec['company_name'] ?? '') === '' && ($rec['contact_person'] ?? '') === '') {
                [$status, $note] = ['error', 'Missing business / contact name'];
            } elseif (isset($existing[$phone])) {
                [$status, $note] = ['duplicate', 'Already in CRM as ' . $existing[$phone]];
            } elseif (isset($seen[$phone])) {
                [$status, $note] = ['duplicate', 'Repeated in this file (row ' . $seen[$phone] . ')'];
            }
            if ($phone !== null) {
                $seen[$phone] ??= $i + 2;
            }
            $out[] = ['row' => $i + 2, 'data' => $rec, 'status' => $status, 'note' => $note];
        }

        return $out;
    }

    public function review()
    {
        $fid = (int) $this->request->getGet('file');
        [$rows, $map, $name] = $this->read($fid);
        $analysed = $this->analyse($rows, $map);
        $counts = array_count_values(array_column($analysed, 'status'));

        return $this->render('crm/import', [
            'pageTitle' => 'Import leads', 'breadcrumbs' => [['CRM'], ['Leads', 'crm/leads'], ['Import']], 'fields' => array_keys(self::FIELDS),
            'preview' => ['file' => $fid, 'name' => $name, 'map' => $map, 'rows' => array_slice($analysed, 0, 50), 'counts' => $counts, 'total' => count($analysed)],
            'users' => $this->can('crm.lead.assign') ? $this->assignableUsers() : [],
        ]);
    }

    public function commit()
    {
        $fid = (int) $this->request->getPost('file');
        [$rows, $map] = $this->read($fid);
        $analysed = $this->analyse($rows, $map);
        $users = [];
        foreach ($this->db->table('users u')->select('u.id, p.full_name, i.secret AS email')->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')->where('u.active', 1)->get()->getResultArray() as $u) {
            $users[strtolower((string) $u['full_name'])] = (int) $u['id'];
            $users[strtolower((string) $u['email'])] = (int) $u['id'];
        }
        $sources = array_change_key_case(array_column($this->db->table('lead_sources')->get()->getResultArray(), 'id', 'name'));
        $default = (int) ($this->request->getPost('assign_to') ?: $this->userId);
        $created = 0;
        $errors  = [];
        foreach ($analysed as $a) {
            if ($a['status'] !== 'ok') {
                $errors[] = ['row' => $a['row'], 'reason' => $a['note']] + $a['data'];

                continue;
            }
            $d = $a['data'];
            $assignee = $users[strtolower($d['assigned'] ?? '')] ?? $default;
            try {
                service('leads')->create([
                    'company_name' => $d['company_name'] ?? '', 'contact_person' => $d['contact_person'] ?? '', 'mobile' => $d['mobile'] ?? '', 'alt_mobile' => $d['alt_mobile'] ?? '',
                    'email' => $d['email'] ?? '', 'address' => $d['address'] ?? '', 'area' => $d['area'] ?? '', 'city' => $d['city'] ?? '', 'pincode' => $d['pincode'] ?? '',
                    'industry' => $d['industry'] ?? '', 'services_interested' => $d['services_interested'] ?? '', 'source_id' => $sources[strtolower($d['source'] ?? '')] ?? null,
                    'campaign' => $d['campaign'] ?? '', 'priority' => strtolower($d['priority'] ?? 'medium'), 'remarks' => $d['remarks'] ?? '', 'assigned_to' => $assignee,
                ], false, 'import');
                $created++;
            } catch (BusinessRuleException $e) {
                $errors[] = ['row' => $a['row'], 'reason' => $e->getMessage() . ' ' . implode('; ', $e->errors())] + $d;
            }
        }
        service('audit')->log('crm.import', 'lead', null, null, ['created' => $created, 'skipped' => count($errors)], "Lead import: {$created} created, " . count($errors) . ' skipped');
        if ($errors !== [] && $this->request->getPost('download_errors')) {
            $cols = array_merge([['key' => 'row', 'label' => 'Row', 'type' => 'int'], ['key' => 'reason', 'label' => 'Why skipped', 'width' => 40]], array_map(static fn ($f) => ['key' => $f, 'label' => $f], array_keys(self::FIELDS)));

            return service('exports')->download('crm_import_errors', 'xlsx', 'Lead_import_skipped', [['title' => 'Skipped', 'columns' => $cols, 'rows' => $errors]]);
        }

        return redirect()->to(site_url('crm/leads'))->with('success', "{$created} lead(s) imported. " . (count($errors) ? count($errors) . ' row(s) skipped (duplicates or errors).' : ''));
    }
}
