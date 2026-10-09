<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\BusinessRuleException;
use App\Models\ImportJobModel;
use App\Models\SupplierMasterFieldModel;
use App\Services\SupplierMasterService;

/**
 * Supplier master directory: field map (86 source columns) and imports.
 */
class SupplierMaster extends AdminController
{
    public function index(): string
    {
        $m = model(ImportJobModel::class)->where('import_type', 'supplier_master')->orderBy('id', 'DESC');

        return $this->page('supplier_master', ['title' => 'Supplier master data', 'fields' => service('supplierMaster')->fields(), 'targets' => SupplierMasterService::TARGETS,
            'jobs' => $m->paginate(20), 'pager' => $m->pager,
            'records' => db_connect()->table('supplier_master_attributes')->select('COUNT(DISTINCT company_id) AS companies, COUNT(*) AS cells')->get()->getRowArray()]);
    }

    public function template()
    {
        return $this->response->download('bearingcave-supplier-master-template.csv', service('supplierMaster')->templateCsv());
    }

    public function updateField(int $id)
    {
        $f = model(SupplierMasterFieldModel::class)->find($id) ?? $this->notFound();
        $target = (string) $this->request->getPost('target');
        if ($target !== '' && ! in_array($target, SupplierMasterService::TARGETS, true)) {
            return redirect()->back()->with('error', 'Invalid target.');
        }
        model(SupplierMasterFieldModel::class)->update($id, ['source_heading' => mb_substr(trim((string) $this->request->getPost('source_heading')) ?: $f['source_heading'], 0, 191), 'target' => $target ?: null, 'field_group' => mb_substr((string) ($this->request->getPost('field_group') ?: $f['field_group']), 0, 60), 'is_confirmed' => 1]);

        return redirect()->back()->with('success', 'Field updated.');
    }

    public function upload()
    {
        return $this->attempt(function () {
            $file = $this->request->getFile('file');
            if (! $file || ! $file->isValid()) {
                throw new BusinessRuleException('Choose a CSV or Excel file.');
            }
            $job = service('imports')->upload($file, 'supplier_master', null, $this->userId());
            $fields = [];
            foreach (service('supplierMaster')->fields() as $f) {
                $fields[$f['field_key']] = [$f['source_heading']];
            }
            model(ImportJobModel::class)->update($job['id'], ['column_map' => json_encode(service('imports')->autoMap(json_decode($job['headers'], true), $fields))]);

            return redirect()->to(site_url('admin/supplier-master/' . $job['uuid']));
        }, 'File uploaded.');
    }

    private function job(string $uuid): array
    {
        return model(ImportJobModel::class)->where('uuid', $uuid)->where('import_type', 'supplier_master')->first() ?? $this->notFound();
    }

    public function show(string $uuid): string
    {
        $job = $this->job($uuid);

        return $this->page('supplier_master_job', ['title' => 'Supplier master import', 'job' => $job, 'headers' => json_decode((string) $job['headers'], true) ?: [],
            'map' => json_decode((string) $job['column_map'], true) ?: [], 'fields' => service('supplierMaster')->fields(),
            'rows' => service('imports')->rowsOf((int) $job['id'], null, 300)]);
    }

    public function map(string $uuid)
    {
        $job = $this->job($uuid);

        return $this->attempt(function () use ($job) {
            service('supplierMaster')->saveMapping($job, (array) $this->request->getPost('map'));
            $c = service('supplierMaster')->validate(model(ImportJobModel::class)->find($job['id']));

            return redirect()->to(site_url('admin/supplier-master/' . $job['uuid']))->with('info', "{$c['valid']} new, {$c['duplicate']} existing/duplicate, {$c['error']} with errors.");
        }, 'Validated.');
    }

    public function confirm(string $uuid)
    {
        $job = $this->job($uuid);

        return $this->attempt(function () use ($job) {
            $r = service('supplierMaster')->commit($job, $this->request->getPost('update_existing') === '1');

            return redirect()->to(site_url('admin/supplier-master/' . $job['uuid']))->with('success', "{$r['imported']} supplier record(s) imported/updated. All source columns were preserved.");
        }, 'Imported.');
    }
}
