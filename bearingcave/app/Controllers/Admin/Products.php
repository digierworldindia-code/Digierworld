<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyModel;
use App\Models\CrossReferenceModel;
use App\Models\ProductModel;

class Products extends AdminController
{
    public function index(): string
    {
        $m = model(ProductModel::class)->select('products.*, companies.legal_name, companies.is_sample AS company_sample, categories.name AS category_name')
            ->join('companies', 'companies.id = products.company_id')->join('categories', 'categories.id = products.category_id');
        $status = $this->request->getGet('status') ?? 'pending_review';
        if ($status !== '') {
            $m->where('products.status', $status);
        }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $m->groupStart()->like('products.name', $q)->orLike('products.part_number_norm', normalize_part($q))->orLike('companies.legal_name', $q)->groupEnd();
        }
        $m->orderBy('products.updated_at', 'ASC');

        return $this->page('products', ['title' => 'Product review', 'rows' => $m->paginate(25), 'pager' => $m->pager, 'status' => $status]);
    }

    public function show(int $id): string
    {
        $p  = model(ProductModel::class)->find($id) ?? $this->notFound();
        $db = db_connect();

        return $this->page('product', [
            'title' => $p['part_number'] . ' — ' . $p['name'], 'p' => $p, 'supplier' => model(CompanyModel::class)->find($p['company_id']),
            'category' => $db->table('categories')->where('id', $p['category_id'])->get()->getRowArray(), 'brand' => $p['brand_id'] ? $db->table('brands')->where('id', $p['brand_id'])->get()->getRowArray() : null,
            'xrefs' => $db->table('cross_references')->where('product_id', $id)->get()->getResultArray(), 'countries' => $db->table('product_country_rules')->where('product_id', $id)->get()->getResultArray(),
            'lots' => $db->table('inventory_lots')->where('product_id', $id)->get()->getResultArray(), 'images' => $db->table('product_images')->where('product_id', $id)->get()->getResultArray(),
            'documents' => service('documents')->forEntity('product', $id), 'specs' => $db->table('product_specifications')->where('product_id', $id)->get()->getResultArray(),
        ]);
    }

    public function review(int $id)
    {
        $p = model(ProductModel::class)->find($id) ?? $this->notFound();
        $decision = $this->request->getPost('decision') === 'approve' ? 'approve' : 'reject';
        if ($decision === 'reject' && trim((string) $this->request->getPost('notes')) === '') {
            return redirect()->back()->with('error', 'Tell the supplier what to change.');
        }

        return $this->attempt(fn () => service('products')->review($p, $decision, $this->request->getPost('notes') ?: null, $this->userId()), $decision === 'approve' ? 'Listing approved and published.' : 'Listing returned to the supplier.', 'admin/products');
    }

    public function status(int $id)
    {
        $p = model(ProductModel::class)->find($id) ?? $this->notFound();

        return $this->attempt(fn () => service('products')->setStatus($p, (string) $this->request->getPost('status')), 'Status updated.');
    }

    /** Marks a supplier-declared cross reference as verified interchange. */
    public function verifyXref(int $id, int $xrefId)
    {
        $x = model(CrossReferenceModel::class)->where('product_id', $id)->find($xrefId) ?? $this->notFound();
        $verify = $this->request->getPost('verify') === '1';
        model(CrossReferenceModel::class)->update($xrefId, ['relation' => $verify ? 'verified_interchange' : 'declared_equivalent', 'verified_by' => $verify ? $this->userId() : null, 'verified_at' => $verify ? date('Y-m-d H:i:s') : null, 'notes' => $this->request->getPost('notes') ?: $x['notes']]);
        service('audit')->log('xref.' . ($verify ? 'verified' : 'unverified'), ['entity_type' => 'product', 'entity_id' => $id, 'description' => $x['ref_brand'] . ' ' . $x['ref_part_number']]);

        return redirect()->back()->with('success', $verify ? 'Cross reference marked as verified interchange.' : 'Verification removed.');
    }
}
