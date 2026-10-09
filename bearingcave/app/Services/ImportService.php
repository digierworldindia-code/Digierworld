<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BrandModel;
use App\Models\CategoryModel;
use App\Models\CompanyModel;
use App\Models\ImportJobModel;
use App\Models\ImportJobRowModel;
use App\Models\ProductModel;
use App\Services\DocumentService;
use CodeIgniter\Files\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bulk inventory import: upload → map columns → validate & preview
 * (errors + duplicates) → confirm. Nothing is written to the catalog until
 * the user confirms the preview.
 */
class ImportService
{
    /** target field => [label, required, example] */
    public const PRODUCT_FIELDS = [
        'sku'               => ['SKU', true, 'BRG-6204-001'],
        'name'              => ['Product name', true, 'Deep groove ball bearing 6204-2RS'],
        'part_number'       => ['Manufacturer part number', true, '6204-2RS'],
        'oem_part_number'   => ['OEM part number', false, ''],
        'brand'             => ['Brand', false, 'SKF'],
        'category'          => ['Category', true, 'Ball Bearings'],
        'item_condition'    => ['Condition (new, new_old_stock, new_open_box, refurbished, used)', true, 'new'],
        'quantity'          => ['Available quantity', true, '500'],
        'received_on'       => ['Stock received date (YYYY-MM-DD)', true, '2025-06-30'],
        'moq'               => ['Minimum order quantity', false, '50'],
        'sale_mode'         => ['Sale mode (lot, piece, both)', false, 'lot'],
        'lot_quantity'      => ['Lot size', false, '500'],
        'lot_price'         => ['Lot price', false, '45000'],
        'unit_price'        => ['Per-piece price', false, ''],
        'currency'          => ['Currency (ISO code)', false, 'INR'],
        'price_visibility'  => ['Price visibility (show, on_request)', false, 'show'],
        'warehouse_city'    => ['Warehouse city', false, 'Pune'],
        'warehouse_country' => ['Warehouse country (ISO-2)', false, 'IN'],
        'description'       => ['Description', false, ''],
        'inner_diameter_mm' => ['Inner diameter (mm)', false, '20'],
        'outer_diameter_mm' => ['Outer diameter (mm)', false, '47'],
        'width_mm'          => ['Width (mm)', false, '14'],
        'weight_kg'         => ['Weight (kg)', false, '0.11'],
        'cross_references'  => ['Cross references (BRAND:PART; ...)', false, 'FAG:6204-2RSR'],
        'allowed_countries' => ['Allowed countries (ISO-2, comma separated)', false, ''],
        'excluded_countries' => ['Excluded countries (ISO-2, comma separated)', false, ''],
    ];

    public function __construct(
        private ImportJobModel $jobs = new ImportJobModel(),
        private ImportJobRowModel $rows = new ImportJobRowModel(),
    ) {
    }

    public function templateSpreadsheet(): Spreadsheet
    {
        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Inventory');
        $col = 1;
        foreach (self::PRODUCT_FIELDS as $key => [$label, $req, $example]) {
            $sheet->setCellValue([$col, 1], $key);
            $sheet->setCellValue([$col, 2], $example);
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            $col++;
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $notes = $ss->createSheet();
        $notes->setTitle('Instructions');
        $notes->setCellValue('A1', 'Column');
        $notes->setCellValue('B1', 'Meaning');
        $notes->setCellValue('C1', 'Required');
        $r = 2;
        foreach (self::PRODUCT_FIELDS as $key => [$label, $req]) {
            $notes->setCellValue("A{$r}", $key);
            $notes->setCellValue("B{$r}", $label);
            $notes->setCellValue("C{$r}", $req ? 'Yes' : 'No');
            $r++;
        }
        $notes->setCellValue('A' . ($r + 1), 'Row 2 of the Inventory sheet is an EXAMPLE — replace or delete it before uploading.');

        return $ss;
    }

    public function templateBinary(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bct');
        (new Xlsx($this->templateSpreadsheet()))->save($tmp);
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    /**
     * Stores the file and reads raw rows.
     */
    public function upload(File $file, string $type, ?int $companyId, int $userId): array
    {
        service('documents')->validate($file, ['csv', 'xlsx', 'xls']);
        $dir      = WRITEPATH . 'uploads/imports';
        $name     = bin2hex(random_bytes(16)) . '.' . DocumentService::extension($file);
        $original = DocumentService::originalName($file);
        DocumentService::place($file, $dir, $name);
        $path = $dir . '/' . $name;

        [$headers, $data] = $this->read($path);
        if ($headers === []) {
            throw new BusinessRuleException('The file has no header row.');
        }
        $max = (int) policy('imports.max_rows', 5000);
        if (count($data) > $max) {
            throw new BusinessRuleException("The file has " . count($data) . " rows; the limit is {$max} per import.");
        }
        if ($data === []) {
            throw new BusinessRuleException('The file has no data rows.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $jobId = (int) $this->jobs->insert([
                'uuid' => uuid4(), 'import_type' => $type, 'company_id' => $companyId, 'created_by' => $userId,
                'original_name' => $original, 'stored_path' => 'imports/' . $name, 'status' => 'uploaded',
                'headers' => json_encode($headers), 'total_rows' => count($data),
            ]);
            foreach ($data as $i => $row) {
                $this->rows->insert(['import_job_id' => $jobId, 'row_number' => $i + 2, 'data' => json_encode($row, JSON_UNESCAPED_UNICODE), 'status' => 'valid']);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('audit')->log('import.uploaded', ['entity_type' => 'import_job', 'entity_id' => $jobId, 'company_id' => $companyId, 'description' => $original]);

        return $this->jobs->find($jobId);
    }

    /**
     * @return array{0:list<string>,1:list<list<string>>}
     */
    public function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (\Throwable $e) {
            throw new BusinessRuleException('The file could not be read as CSV/Excel: ' . $e->getMessage());
        }
        $rows    = $sheet->toArray(null, true, true, false);
        $headers = array_map(static fn ($h) => trim((string) $h), array_shift($rows) ?? []);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        $out = [];
        foreach ($rows as $r) {
            $r = array_slice(array_map(static fn ($v) => is_string($v) ? trim($v) : ($v === null ? '' : (string) $v), $r), 0, count($headers));
            if (implode('', $r) === '') {
                continue;
            }
            $out[] = array_pad($r, count($headers), '');
        }

        return [$headers, $out];
    }

    /**
     * Suggests a mapping source heading index => target field.
     *
     * @param array<string,array> $fields
     */
    public function autoMap(array $headers, array $fields): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $n = preg_replace('/[^a-z0-9]/', '', strtolower($h));
            foreach ($fields as $key => $def) {
                $label = is_array($def) ? $def[0] : (string) $def;
                if ($n === preg_replace('/[^a-z0-9]/', '', strtolower($key)) || $n === preg_replace('/[^a-z0-9]/', '', strtolower($label))) {
                    $map[$i] = $key;

                    break;
                }
            }
        }

        return $map;
    }

    public function saveMapping(array $job, array $map): void
    {
        $headers = json_decode($job['headers'], true);
        $clean   = [];
        foreach ($map as $idx => $target) {
            if ($target !== '' && isset($headers[(int) $idx])) {
                $clean[(int) $idx] = $target;
            }
        }
        if ($job['import_type'] === 'products') {
            foreach (self::PRODUCT_FIELDS as $k => [$label, $req]) {
                if ($req && ! in_array($k, $clean, true)) {
                    throw new BusinessRuleException("Map a column to the required field \"{$label}\".");
                }
            }
            if (count($clean) !== count(array_unique($clean))) {
                throw new BusinessRuleException('Each target field can be mapped only once.');
            }
        }
        $this->jobs->update($job['id'], ['column_map' => json_encode($clean), 'status' => 'mapped']);
    }

    /**
     * Validates all rows of a product import and detects duplicates.
     */
    public function validateProducts(array $job): array
    {
        $company = model(CompanyModel::class)->find($job['company_id']);
        if (! $company || $company['company_type'] !== 'supplier') {
            throw new BusinessRuleException('Imports must target a supplier company.');
        }
        $map      = json_decode((string) $job['column_map'], true) ?: [];
        $cats     = [];
        foreach (model(CategoryModel::class)->findAll() as $c) {
            $cats[strtolower($c['name'])] = (int) $c['id'];
            $cats[strtolower($c['slug'])] = (int) $c['id'];
        }
        $brands = [];
        foreach (model(BrandModel::class)->findAll() as $b) {
            $brands[strtolower($b['name'])] = (int) $b['id'];
        }
        $countries  = array_flip(array_column(db_connect()->table('countries')->select('iso2')->get()->getResultArray(), 'iso2'));
        $currencies = array_flip(array_column(db_connect()->table('currencies')->select('code')->where('is_enabled', 1)->get()->getResultArray(), 'code'));
        $existing   = array_flip(array_column(model(ProductModel::class)->withDeleted()->select('sku')->where('company_id', $company['id'])->findAll(), 'sku'));
        $ent        = service('entitlements');
        $byStaff    = (int) $job['created_by'] !== 0 && service('companyContext')->isStaff((int) $job['created_by']);
        $seen       = [];
        $counts     = ['valid' => 0, 'error' => 0, 'duplicate' => 0];

        foreach ($this->rows->where('import_job_id', $job['id'])->orderBy('row_number')->findAll() as $row) {
            $raw = json_decode($row['data'], true);
            $raw = $raw['raw'] ?? $raw;
            $d   = [];
            foreach ($map as $idx => $field) {
                $d[$field] = trim((string) ($raw[$idx] ?? ''));
            }
            $err = [];
            foreach (self::PRODUCT_FIELDS as $k => [$label, $req]) {
                if ($req && ($d[$k] ?? '') === '') {
                    $err[] = "{$label} is required";
                }
            }
            $d['category_id'] = $cats[strtolower($d['category'] ?? '')] ?? null;
            if (($d['category'] ?? '') !== '' && ! $d['category_id']) {
                $err[] = "Unknown category \"{$d['category']}\"";
            }
            $d['brand_id'] = null;
            if (($d['brand'] ?? '') !== '') {
                $d['brand_id'] = $brands[strtolower($d['brand'])] ?? null;
                if (! $d['brand_id']) {
                    $err[] = "Unknown brand \"{$d['brand']}\" (ask BearingCave to add it)";
                }
            }
            $d['item_condition'] = strtolower($d['item_condition'] ?? 'new');
            if (! isset(ProductService::CONDITIONS[$d['item_condition']])) {
                $err[] = 'Invalid condition';
            }
            if (! ctype_digit($d['quantity'] ?? '') || (int) $d['quantity'] < 1) {
                $err[] = 'Quantity must be a whole number ≥ 1';
            }
            if (($d['moq'] ?? '') !== '' && ! ctype_digit($d['moq'])) {
                $err[] = 'MOQ must be a whole number';
            }
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $d['received_on'] ?? '');
            if (! $date && is_numeric($d['received_on'] ?? '')) {
                $date = \DateTimeImmutable::createFromMutable(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $d['received_on']));
            }
            if (! $date || $date > new \DateTimeImmutable('today')) {
                $err[] = 'Received date must be a past date in YYYY-MM-DD format';
            } else {
                $d['received_on'] = $date->format('Y-m-d');
            }
            $d['sale_mode'] = strtolower($d['sale_mode'] ?? '') ?: 'lot';
            if (! in_array($d['sale_mode'], ['lot', 'piece', 'both'], true)) {
                $err[] = 'Sale mode must be lot, piece or both';
            } elseif ($d['sale_mode'] !== 'lot' && ! $byStaff && ! $ent->has($company, 'per_piece_pricing')) {
                $err[] = 'Per-piece selling requires the Verified Supplier plan';
            }
            $d['price_visibility'] = strtolower($d['price_visibility'] ?? '') ?: 'show';
            foreach (['lot_price', 'unit_price', 'inner_diameter_mm', 'outer_diameter_mm', 'width_mm', 'weight_kg'] as $num) {
                if (($d[$num] ?? '') !== '' && ! is_numeric($d[$num])) {
                    $err[] = "{$num} must be a number";
                }
            }
            if ($d['sale_mode'] === 'lot') {
                if (($d['lot_quantity'] ?? '') === '') {
                    $d['lot_quantity'] = $d['quantity'] ?? '';
                }
                if ($d['price_visibility'] === 'show' && ($d['lot_price'] ?? '') === '') {
                    $err[] = 'Lot price is required (or set price_visibility to on_request)';
                }
            } elseif ($d['price_visibility'] === 'show' && ($d['unit_price'] ?? '') === '') {
                $err[] = 'Per-piece price is required (or set price_visibility to on_request)';
            }
            $d['currency'] = strtoupper($d['currency'] ?? '') ?: 'INR';
            if (! isset($currencies[$d['currency']])) {
                $err[] = "Currency {$d['currency']} is not enabled";
            }
            $d['warehouse_country'] = strtoupper($d['warehouse_country'] ?? '') ?: $company['country_code'];
            if (! isset($countries[$d['warehouse_country']])) {
                $err[] = 'Invalid warehouse country';
            }
            $d['countries']    = [];
            $d['country_mode'] = 'all';
            foreach (['allowed_countries' => 'allow_list', 'excluded_countries' => 'deny_list'] as $col => $mode) {
                if (($d[$col] ?? '') === '') {
                    continue;
                }
                if ($d['country_mode'] !== 'all') {
                    $err[] = 'Use either allowed or excluded countries, not both';

                    break;
                }
                if (! $byStaff && ! $ent->has($company, 'country_visibility_control')) {
                    $err[] = 'Country selection requires the Verified Supplier plan';

                    break;
                }
                $list = array_filter(array_map(static fn ($c) => strtoupper(trim($c)), explode(',', $d[$col])));
                foreach ($list as $cc) {
                    if (! isset($countries[$cc])) {
                        $err[] = "Unknown country code {$cc}";
                    }
                }
                $d['country_mode'] = $mode;
                $d['countries']    = array_values($list);
            }

            $status = 'valid';
            if ($err !== []) {
                $status = 'error';
            } elseif (isset($existing[$d['sku']]) || isset($seen[$d['sku']])) {
                $status = 'duplicate';
                $err[]  = isset($seen[$d['sku']]) ? 'Duplicate SKU within this file (row ' . $seen[$d['sku']] . ')' : 'SKU already exists in your inventory';
            }
            if (($d['sku'] ?? '') !== '' && ! isset($seen[$d['sku']])) {
                $seen[$d['sku']] = $row['row_number'];
            }
            $counts[$status]++;
            $this->rows->update($row['id'], ['data' => json_encode(['raw' => $raw, 'mapped' => $d], JSON_UNESCAPED_UNICODE), 'errors' => $err ? json_encode($err) : null, 'status' => $status]);
        }
        $this->jobs->update($job['id'], ['status' => 'validated', 'valid_rows' => $counts['valid'], 'error_rows' => $counts['error'], 'duplicate_rows' => $counts['duplicate']]);

        return $counts;
    }

    /**
     * Imports valid rows. Each product + its stock lot is written atomically.
     */
    public function commitProducts(array $job, bool $publish): array
    {
        if ($job['status'] !== 'validated') {
            throw new BusinessRuleException('Validate the file before importing.');
        }
        $company  = model(CompanyModel::class)->find($job['company_id']);
        $byStaff  = service('companyContext')->isStaff((int) $job['created_by']);
        $ps       = service('products');
        $imported = 0;
        $failed   = 0;
        foreach ($this->rows->where('import_job_id', $job['id'])->where('status', 'valid')->orderBy('row_number')->findAll() as $row) {
            $d  = json_decode($row['data'], true)['mapped'];
            $db = db_connect();
            $db->transBegin();

            try {
                $product = $ps->save($company, [
                    'sku' => $d['sku'], 'name' => $d['name'], 'part_number' => $d['part_number'], 'oem_part_number' => $d['oem_part_number'] ?? '',
                    'category_id' => $d['category_id'], 'brand_id' => $d['brand_id'], 'item_condition' => $d['item_condition'],
                    'sale_mode' => $d['sale_mode'], 'unit_price' => $d['unit_price'] ?? '', 'lot_price' => $d['lot_price'] ?? '',
                    'lot_quantity' => $d['lot_quantity'] ?? '', 'moq' => ($d['moq'] ?? '') !== '' ? $d['moq'] : 1, 'currency' => $d['currency'],
                    'price_visibility' => $d['price_visibility'], 'visibility' => 'public', 'country_mode' => $d['country_mode'], 'countries' => $d['countries'],
                    'warehouse_city' => $d['warehouse_city'] ?? null, 'warehouse_country' => $d['warehouse_country'], 'description' => $d['description'] ?? null,
                    'inner_diameter_mm' => $d['inner_diameter_mm'] ?? '', 'outer_diameter_mm' => $d['outer_diameter_mm'] ?? '', 'width_mm' => $d['width_mm'] ?? '',
                    'weight_kg' => $d['weight_kg'] ?? '', 'cross_references' => str_replace(';', "\n", (string) ($d['cross_references'] ?? '')),
                    'hide_supplier_identity' => 1, 'shipping_options' => ['platform_managed'],
                ], null, $byStaff);
                service('inventory')->receive((int) $product['id'], (int) $d['quantity'], $d['received_on'], null, $d['warehouse_city'] ?? null, 'import', 'Import ' . $job['uuid'] . ' row ' . $row['row_number']);
                if ($publish) {
                    $ps->submit(model(ProductModel::class)->find($product['id']), $company, $byStaff, false);
                }
                $this->rows->update($row['id'], ['status' => 'imported', 'entity_id' => $product['id']]);
                $db->transCommit();
                $imported++;
            } catch (\Throwable $e) {
                $db->transRollback();
                $this->rows->update($row['id'], ['status' => 'error', 'errors' => json_encode([$e->getMessage()])]);
                $failed++;
            }
        }
        $this->jobs->update($job['id'], ['status' => 'completed', 'imported_rows' => $imported, 'error_rows' => (int) $job['error_rows'] + $failed, 'completed_at' => date('Y-m-d H:i:s')]);
        if ($publish && $imported > 0 && service('products')->needsReview($company, $byStaff)) {
            service('notifications')->notifyStaff(['procurement_manager'], 'product.review', "{$imported} imported listing(s) awaiting review", $company['legal_name'], 'admin/products?status=pending_review');
        }
        service('audit')->log('import.completed', ['entity_type' => 'import_job', 'entity_id' => (int) $job['id'], 'company_id' => (int) $job['company_id'], 'description' => "{$imported} imported, {$failed} failed"]);

        return ['imported' => $imported, 'failed' => $failed];
    }

    public function rowsOf(int $jobId, ?string $status = null, int $limit = 500): array
    {
        $q = $this->rows->where('import_job_id', $jobId);
        if ($status) {
            $q->where('status', $status);
        }

        return $q->orderBy('row_number')->findAll($limit);
    }
}
