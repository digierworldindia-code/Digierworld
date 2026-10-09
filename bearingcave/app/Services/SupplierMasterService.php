<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;
use App\Models\ImportJobModel;
use App\Models\ImportJobRowModel;
use App\Models\KycRecordModel;
use App\Models\SupplierMasterAttributeModel;
use App\Models\SupplierMasterFieldModel;
use App\Models\SupplierProfileModel;

/**
 * Supplier master directory import (the spreadsheet's "Supplier list" tab).
 *
 * Every source column is preserved: columns mapped to a normalized target
 * populate companies / supplier_profiles / kyc_records, and EVERY mapped
 * column value is also kept in supplier_master_attributes. Headings that do
 * not match a registered field are registered automatically, so a sheet with
 * all 86 headings imports without losing data.
 */
class SupplierMasterService
{
    public const TARGETS = [
        'companies.legal_name', 'companies.trade_name', 'companies.registration_number', 'companies.business_type',
        'companies.year_established', 'companies.employee_count', 'companies.country_code', 'companies.state', 'companies.city',
        'companies.address_line1', 'companies.address_line2', 'companies.postal_code', 'companies.phone', 'companies.email',
        'companies.website', 'companies.description',
        'supplier_profiles.supplier_type', 'supplier_profiles.main_categories', 'supplier_profiles.brands_handled',
        'supplier_profiles.production_capacity', 'supplier_profiles.export_markets',
        'kyc_records.gst_number', 'kyc_records.pan_number', 'kyc_records.cin_number', 'kyc_records.vat_number', 'kyc_records.tax_id',
    ];

    public function fields(): array
    {
        return model(SupplierMasterFieldModel::class)->orderBy('sort_order')->findAll();
    }

    /**
     * Saves the mapping; unmapped headings are registered as new attribute fields.
     *
     * @param array<int,string> $map heading index => field_key ('' = auto-register)
     */
    public function saveMapping(array $job, array $map): void
    {
        $headers = json_decode($job['headers'], true);
        $fieldsM = model(SupplierMasterFieldModel::class);
        $known   = array_column($this->fields(), null, 'field_key');
        $clean   = [];
        $max     = (int) ($fieldsM->selectMax('sort_order')->first()['sort_order'] ?? 0);
        foreach ($headers as $i => $heading) {
            if ($heading === '') {
                continue;
            }
            $key = $map[$i] ?? '';
            if ($key === '' || ! isset($known[$key])) {
                $key = substr(make_slug($heading, 90), 0, 90);
                $key = str_replace('-', '_', $key);
                if (! isset($known[$key])) {
                    $fieldsM->insert(['source_heading' => mb_substr($heading, 0, 191), 'field_key' => $key, 'field_group' => 'unclassified', 'target' => null, 'data_type' => 'string', 'sort_order' => ++$max, 'is_confirmed' => 1]);
                    $known[$key] = ['field_key' => $key, 'target' => null];
                }
            }
            $clean[$i] = $key;
        }
        $targets = array_column(array_intersect_key($known, array_flip($clean)), 'target');
        if (! in_array('companies.legal_name', $targets, true)) {
            throw new BusinessRuleException('Map one column to a field whose target is the company legal name.');
        }
        model(ImportJobModel::class)->update($job['id'], ['column_map' => json_encode($clean), 'status' => 'mapped']);
    }

    public function validate(array $job): array
    {
        $map       = json_decode((string) $job['column_map'], true) ?: [];
        $fields    = array_column($this->fields(), null, 'field_key');
        $countries = [];
        foreach (db_connect()->table('countries')->get()->getResultArray() as $c) {
            $countries[strtolower($c['iso2'])] = $c['iso2'];
            $countries[strtolower($c['name'])] = $c['iso2'];
        }
        $rowsM  = model(ImportJobRowModel::class);
        $counts = ['valid' => 0, 'error' => 0, 'duplicate' => 0];
        $seen   = [];
        foreach ($rowsM->where('import_job_id', $job['id'])->orderBy('row_number')->findAll() as $row) {
            $raw     = json_decode($row['data'], true);
            $raw     = $raw['raw'] ?? $raw;
            $values  = [];
            $targets = [];
            foreach ($map as $idx => $key) {
                $v            = trim((string) ($raw[$idx] ?? ''));
                $values[$key] = $v;
                if (! empty($fields[$key]['target']) && $v !== '') {
                    $targets[$fields[$key]['target']] = $v;
                }
            }
            $err = [];
            if (($targets['companies.legal_name'] ?? '') === '') {
                $err[] = 'Legal name is required';
            }
            $cc = $targets['companies.country_code'] ?? '';
            $iso = $countries[strtolower($cc)] ?? null;
            if (! $iso) {
                $err[] = $cc === '' ? 'Country is required' : "Unknown country \"{$cc}\"";
            } else {
                $targets['companies.country_code'] = $iso;
            }
            if (isset($targets['companies.email']) && ! filter_var($targets['companies.email'], FILTER_VALIDATE_EMAIL)) {
                $err[] = 'Invalid email';
            }
            if (isset($targets['supplier_profiles.supplier_type'])) {
                $t = str_replace([' ', '-'], '_', strtolower($targets['supplier_profiles.supplier_type']));
                $targets['supplier_profiles.supplier_type'] = in_array($t, ['manufacturer', 'oem_supplier', 'aftermarket_supplier', 'distributor', 'trader', 'stockist'], true) ? $t : 'other';
            }
            $dupKey = strtolower(($targets['companies.registration_number'] ?? '') ?: (($targets['companies.legal_name'] ?? '') . '|' . $iso));
            $status = $err ? 'error' : 'valid';
            $dupeOf = null;
            if (! $err) {
                $q = model(CompanyModel::class)->where('company_type', 'supplier');
                if (! empty($targets['companies.registration_number'])) {
                    $q->where('registration_number', $targets['companies.registration_number']);
                } else {
                    $q->where('legal_name', $targets['companies.legal_name'])->where('country_code', $iso);
                }
                $existing = $q->first();
                if ($existing || isset($seen[$dupKey])) {
                    $status = 'duplicate';
                    $dupeOf = $existing['id'] ?? null;
                    $err[]  = $existing ? 'Supplier already exists (#' . $existing['id'] . ') — will update its master data' : 'Duplicate of row ' . $seen[$dupKey];
                }
            }
            $seen[$dupKey] ??= $row['row_number'];
            $counts[$status]++;
            $rowsM->update($row['id'], ['data' => json_encode(['raw' => $raw, 'values' => $values, 'targets' => $targets, 'existing_id' => $dupeOf], JSON_UNESCAPED_UNICODE), 'errors' => $err ? json_encode($err) : null, 'status' => $status]);
        }
        model(ImportJobModel::class)->update($job['id'], ['status' => 'validated', 'valid_rows' => $counts['valid'], 'error_rows' => $counts['error'], 'duplicate_rows' => $counts['duplicate']]);

        return $counts;
    }

    public function commit(array $job, bool $updateExisting): array
    {
        if ($job['status'] !== 'validated') {
            throw new BusinessRuleException('Validate the file before importing.');
        }
        $rowsM    = model(ImportJobRowModel::class);
        $statuses = $updateExisting ? ['valid', 'duplicate'] : ['valid'];
        $done     = 0;
        foreach ($rowsM->where('import_job_id', $job['id'])->whereIn('status', $statuses)->orderBy('row_number')->findAll() as $row) {
            $d = json_decode($row['data'], true);
            if ($row['status'] === 'duplicate' && empty($d['existing_id'])) {
                continue; // duplicate inside the file
            }
            $db = db_connect();
            $db->transBegin();

            try {
                $companyId = $this->upsert($d, (int) $job['id']);
                $rowsM->update($row['id'], ['status' => 'imported', 'entity_id' => $companyId]);
                $db->transCommit();
                $done++;
            } catch (\Throwable $e) {
                $db->transRollback();
                $rowsM->update($row['id'], ['status' => 'error', 'errors' => json_encode([$e->getMessage()])]);
            }
        }
        model(ImportJobModel::class)->update($job['id'], ['status' => 'completed', 'imported_rows' => $done, 'completed_at' => date('Y-m-d H:i:s')]);
        service('audit')->log('supplier_master.imported', ['entity_type' => 'import_job', 'entity_id' => (int) $job['id'], 'description' => "{$done} supplier records imported/updated"]);

        return ['imported' => $done];
    }

    private function upsert(array $d, int $jobId): int
    {
        $t        = $d['targets'];
        $companyM = model(CompanyModel::class);
        $company  = [];
        $profile  = [];
        $kyc      = [];
        foreach ($t as $target => $v) {
            [$table, $col] = explode('.', $target, 2);
            match ($table) {
                'companies'         => $company[$col] = $v,
                'supplier_profiles' => $profile[$col] = $v,
                'kyc_records'       => $kyc[$col] = $v,
                default             => null,
            };
        }
        if (isset($company['year_established']) && ! ctype_digit($company['year_established'])) {
            unset($company['year_established']);
        }
        if (! empty($d['existing_id'])) {
            $id = (int) $d['existing_id'];
            $companyM->update($id, $company);
        } else {
            $company += ['uuid' => uuid4(), 'company_type' => 'supplier', 'public_alias' => service('companies')->newAlias('S'), 'verification_status' => 'unverified', 'status' => 'active'];
            $company['slug'] = service('companies')->uniqueSlug($company['legal_name']);
            $id              = (int) $companyM->insert($company);
        }
        $pm = model(SupplierProfileModel::class);
        if ($pm->where('company_id', $id)->first()) {
            if ($profile) {
                $pm->where('company_id', $id)->set($profile)->update();
            }
        } else {
            $pm->insert($profile + ['company_id' => $id]);
        }
        if ($kyc) {
            $km = model(KycRecordModel::class);
            if ($km->where('company_id', $id)->first()) {
                $km->where('company_id', $id)->set($kyc)->update();
            } else {
                $km->insert($kyc + ['company_id' => $id]);
            }
        }
        $am = model(SupplierMasterAttributeModel::class);
        foreach ($d['values'] as $key => $v) {
            if ($v === '') {
                continue;
            }
            $existing = $am->where('company_id', $id)->where('field_key', $key)->first();
            if ($existing) {
                $am->update($existing['id'], ['value' => $v, 'import_job_id' => $jobId]);
            } else {
                $am->insert(['company_id' => $id, 'field_key' => $key, 'value' => $v, 'import_job_id' => $jobId]);
            }
        }

        return $id;
    }

    /**
     * CSV template with all registered master-data headings.
     */
    public function templateCsv(): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_column($this->fields(), 'source_heading'));
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF" . $csv;
    }
}
