<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\ApprovedVendorModel;
use App\Models\CompanyModel;

/**
 * Approved Vendor List (general / category / brand / product approvals).
 */
class Avl extends AdminController
{
    public function index(): string
    {
        $rows = db_connect()->table('approved_vendors a')->select("a.*, c.legal_name, CASE a.scope_type WHEN 'category' THEN (SELECT name FROM categories WHERE id = a.scope_id) WHEN 'brand' THEN (SELECT name FROM brands WHERE id = a.scope_id) WHEN 'product' THEN (SELECT CONCAT(part_number, ' ', name) FROM products WHERE id = a.scope_id) ELSE 'All categories' END AS scope_label", false)
            ->join('companies c', 'c.id = a.company_id')->orderBy('c.legal_name')->get()->getResultArray();

        return $this->page('avl', ['title' => 'Approved Vendor List', 'rows' => $rows,
            'suppliers' => array_column(model(CompanyModel::class)->select('id, legal_name')->where('company_type', 'supplier')->where('verification_status', 'verified')->orderBy('legal_name')->findAll(), 'legal_name', 'id'),
            'categories' => category_options(), 'brands' => brand_options()]);
    }

    public function store()
    {
        if ($r = $this->invalid(['company_id' => 'required|is_natural_no_zero', 'scope_type' => 'required|in_list[general,category,brand,product]', 'valid_until' => 'permit_empty|valid_date[Y-m-d]'])) {
            return $r;
        }
        $type    = (string) $this->request->getPost('scope_type');
        $scopeId = match ($type) {
            'category' => (int) $this->request->getPost('category_id'), 'brand' => (int) $this->request->getPost('brand_id'),
            'product' => (int) $this->request->getPost('product_id'), default => null,
        };
        if ($type !== 'general' && ! $scopeId) {
            return redirect()->back()->withInput()->with('error', 'Choose the ' . $type . ' being approved.');
        }
        if ($type === 'product' && ! db_connect()->table('products')->where('id', $scopeId)->where('company_id', $this->request->getPost('company_id'))->countAllResults()) {
            return redirect()->back()->withInput()->with('error', 'That product does not belong to the selected supplier.');
        }
        $m = model(ApprovedVendorModel::class);
        $existing = $m->where(['company_id' => $this->request->getPost('company_id'), 'scope_type' => $type, 'scope_id' => $scopeId])->first();
        $row = ['status' => 'approved', 'valid_until' => $this->request->getPost('valid_until') ?: null, 'notes' => $this->request->getPost('notes') ?: null, 'approved_by' => $this->userId(), 'approved_at' => date('Y-m-d H:i:s')];
        $existing ? $m->update($existing['id'], $row) : $m->insert($row + ['company_id' => $this->request->getPost('company_id'), 'scope_type' => $type, 'scope_id' => $scopeId]);
        service('audit')->log('avl.approved', ['entity_type' => 'company', 'entity_id' => (int) $this->request->getPost('company_id'), 'company_id' => (int) $this->request->getPost('company_id'), 'description' => $type . ' #' . $scopeId]);

        return redirect()->back()->with('success', 'Approval saved.');
    }

    public function update(int $id)
    {
        $row = model(ApprovedVendorModel::class)->find($id) ?? $this->notFound();
        $st  = (string) $this->request->getPost('status');
        if (! in_array($st, ['approved', 'suspended', 'revoked'], true)) {
            return redirect()->back()->with('error', 'Invalid status.');
        }
        model(ApprovedVendorModel::class)->update($id, ['status' => $st, 'notes' => $this->request->getPost('notes') ?: $row['notes']]);
        service('audit')->log('avl.' . $st, ['entity_type' => 'company', 'entity_id' => (int) $row['company_id'], 'company_id' => (int) $row['company_id'], 'severity' => $st === 'approved' ? 'info' : 'warning']);

        return redirect()->back()->with('success', 'Approval ' . $st . '.');
    }
}
