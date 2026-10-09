<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;
use App\Models\ImportJobModel;
use App\Services\ImportService;

/**
 * Bulk inventory upload: template → upload → column mapping → validation
 * preview (errors & duplicates) → confirm. Admin\Imports extends this to
 * import on behalf of any supplier.
 */
class Imports extends BaseController
{
    protected string $base = 'supplier/imports';
    protected string $area = 'supplier';

    protected function scopeJobs($m)
    {
        return $m->where('company_id', $this->company()['id']);
    }

    protected function targetCompanyId(): int
    {
        return (int) $this->company()['id'];
    }

    protected function loadJob(string $uuid): array
    {
        $job = $this->scopeJobs(model(ImportJobModel::class))->where('uuid', $uuid)->where('import_type', 'products')->first();
        if (! $job) {
            service('audit')->security('access.denied', 'Import job not accessible: ' . $uuid);
            $this->notFound();
        }

        return $job;
    }

    public function index(): string
    {
        $m = $this->scopeJobs(model(ImportJobModel::class))->where('import_type', 'products')->orderBy('id', 'DESC');

        return view('shared/imports', ['title' => 'Bulk inventory upload', 'area' => $this->area, 'base' => $this->base, 'jobs' => $m->paginate(20), 'pager' => $m->pager,
            'fields' => ImportService::PRODUCT_FIELDS, 'suppliers' => $this->area === 'admin' ? array_column(model(CompanyModel::class)->select('id, legal_name')->where('company_type', 'supplier')->orderBy('legal_name')->findAll(), 'legal_name', 'id') : []]);
    }

    public function template()
    {
        return $this->response->download('bearingcave-inventory-template.xlsx', service('imports')->templateBinary());
    }

    public function upload()
    {
        return $this->attempt(function () {
            $file = $this->request->getFile('file');
            if (! $file || ! $file->isValid()) {
                throw new BusinessRuleException('Choose a CSV or Excel file.');
            }
            $job = service('imports')->upload($file, 'products', $this->targetCompanyId(), $this->userId());
            $map = service('imports')->autoMap(json_decode($job['headers'], true), ImportService::PRODUCT_FIELDS);
            model(ImportJobModel::class)->update($job['id'], ['column_map' => json_encode($map)]);

            return redirect()->to(site_url($this->base . '/' . $job['uuid']));
        }, 'File uploaded. Check the column mapping below.');
    }

    public function show(string $uuid): string
    {
        $job = $this->loadJob($uuid);
        $svc = service('imports');

        return view('shared/import', [
            'title' => 'Import ' . $job['original_name'], 'area' => $this->area, 'base' => $this->base, 'job' => $job,
            'headers' => json_decode((string) $job['headers'], true) ?: [], 'map' => json_decode((string) $job['column_map'], true) ?: [],
            'fields' => ImportService::PRODUCT_FIELDS, 'sample' => $svc->rowsOf((int) $job['id'], null, 5),
            'errors' => $job['status'] !== 'uploaded' ? $svc->rowsOf((int) $job['id'], 'error', 200) : [],
            'duplicates' => $job['status'] !== 'uploaded' ? $svc->rowsOf((int) $job['id'], 'duplicate', 200) : [],
            'valid' => in_array($job['status'], ['validated', 'completed'], true) ? $svc->rowsOf((int) $job['id'], $job['status'] === 'completed' ? 'imported' : 'valid', 20) : [],
            'company' => model(CompanyModel::class)->find($job['company_id']),
        ]);
    }

    public function map(string $uuid)
    {
        $job = $this->loadJob($uuid);
        if (in_array($job['status'], ['completed', 'cancelled'], true)) {
            return redirect()->back()->with('error', 'This import is already finished.');
        }

        return $this->attempt(function () use ($job) {
            service('imports')->saveMapping($job, (array) $this->request->getPost('map'));
            $counts = service('imports')->validateProducts(model(ImportJobModel::class)->find($job['id']));

            return redirect()->to(site_url($this->base . '/' . $job['uuid']) . '#preview')->with('info', "Validation: {$counts['valid']} valid, {$counts['error']} with errors, {$counts['duplicate']} duplicates.");
        }, 'Validation complete — review the preview.');
    }

    public function confirm(string $uuid)
    {
        $job = $this->loadJob($uuid);
        if ($this->request->getPost('action') === 'cancel') {
            model(ImportJobModel::class)->update($job['id'], ['status' => 'cancelled']);

            return redirect()->to(site_url($this->base))->with('success', 'Import cancelled. Nothing was imported.');
        }

        return $this->attempt(function () use ($job) {
            $r = service('imports')->commitProducts($job, $this->request->getPost('publish') === '1');

            return redirect()->to(site_url($this->base . '/' . $job['uuid']))->with('success', "{$r['imported']} product(s) imported" . ($r['failed'] ? ", {$r['failed']} failed (see errors)" : '') . '.');
        }, 'Import completed.');
    }
}
