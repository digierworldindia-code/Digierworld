<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CompanyModel;
use App\Models\ProductEnquiryModel;
use App\Models\ProductModel;
use App\Models\SavedProductModel;

class Product extends BaseController
{
    public function show(string $slug)
    {
        $product = model(ProductModel::class)->where('slug', $slug)->first();
        $viewer  = service('visibility')->current();
        $vis     = service('visibility');
        if (! $product || ! $vis->canView($product, $viewer)) {
            // Restricted listings are indistinguishable from missing ones.
            $this->notFound('This listing is not available.');
        }
        $db       = db_connect();
        $supplier = model(CompanyModel::class)->find($product['company_id']);
        $id       = (int) $product['id'];

        if (! $viewer->owns($product) && ! $viewer->isStaff) {
            $db->query('UPDATE products SET view_count = view_count + 1 WHERE id = ?', [$id]);
        }

        $docs = array_values(array_filter(service('documents')->forEntity('product', $id), static fn ($d) => service('documents')->canAccess($d, $viewer)));
        $indexable = $vis->isIndexable($product);
        $canPrice  = $vis->canSeePrice($product, $viewer);
        $supplierLabel = $vis->supplierLabel($supplier, $product, $viewer);

        $comparison = [];
        foreach (service('search')->sameParts($product, $viewer) as $row) {
            $co = ['id' => $row['supplier_id'], 'legal_name' => $row['legal_name'], 'trade_name' => $row['trade_name'], 'public_alias' => $row['public_alias'],
                'verification_status' => $row['verification_status'], 'public_profile_enabled' => $row['public_profile_enabled'], 'show_contact_public' => $row['show_contact_public'],
                'status' => $row['company_status'], 'company_type' => $row['company_type']];
            $comparison[] = ['product' => $row, 'supplier' => $co, 'label' => $vis->supplierLabel($co, $row, $viewer), 'verified' => service('entitlements')->isVerifiedSupplier($co), 'price' => $vis->canSeePrice($row, $viewer)];
        }

        $saved = false;
        if ($viewer->isBuyer()) {
            $saved = model(SavedProductModel::class)->where('user_id', $viewer->userId)->where('product_id', $id)->countAllResults() > 0;
        }

        if (! $indexable) {
            $this->response->setHeader('Cache-Control', 'private, no-store');
            $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $images = $db->table('product_images')->where('product_id', $id)->orderBy('is_primary', 'DESC')->orderBy('sort_order')->get()->getResultArray();

        return view('web/product', [
            'title'      => $product['part_number'] . ' ' . $product['name'],
            'metaDescription' => mb_substr(trim(($product['brand_id'] ? '' : '') . $product['name'] . ' — part number ' . $product['part_number'] . '. ' . strip_tags((string) $product['description'])), 0, 155),
            'canonical'  => site_url('product/' . $product['slug']),
            'robots'     => $indexable ? 'index,follow' : 'noindex,nofollow',
            'product'    => $product,
            'supplier'   => $supplier,
            'supplierLabel' => $supplierLabel,
            'showIdentity' => $vis->canSeeSupplierIdentity($supplier, $product, $viewer),
            'showContact' => $vis->canSeeSupplierContact($supplier, $product, $viewer),
            'supplierVerified' => service('entitlements')->isVerifiedSupplier($supplier),
            'brand'      => $product['brand_id'] ? $db->table('brands')->where('id', $product['brand_id'])->get()->getRowArray() : null,
            'category'   => $db->table('categories')->where('id', $product['category_id'])->get()->getRowArray(),
            'images'     => $images,
            'specs'      => $db->table('product_specifications')->where('product_id', $id)->orderBy('sort_order')->get()->getResultArray(),
            'partNumbers' => $db->table('part_numbers')->where('product_id', $id)->get()->getResultArray(),
            'xrefs'      => $db->table('cross_references')->where('product_id', $id)->get()->getResultArray(),
            'fitments'   => $db->table('fitments')->where('product_id', $id)->get()->getResultArray(),
            'tiers'      => $canPrice ? $db->table('pricing_tiers')->where('product_id', $id)->orderBy('min_qty')->get()->getResultArray() : [],
            'documents'  => $docs,
            'canPrice'   => $canPrice,
            'available'  => service('inventory')->available($product),
            'related'    => service('search')->related($product, $viewer),
            'comparison' => $comparison,
            'saved'      => $saved,
            'viewer'     => $viewer,
            'jsonLd'     => $indexable ? $this->schema($product, $canPrice, $images) : null,
        ]);
    }

    private function schema(array $p, bool $canPrice, array $images): array
    {
        $data = [
            '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'sku' => $p['sku'], 'mpn' => $p['part_number'],
            'description' => strip_tags((string) $p['description']) ?: $p['name'],
            'itemCondition' => in_array($p['item_condition'], ['used'], true) ? 'https://schema.org/UsedCondition' : ($p['item_condition'] === 'refurbished' ? 'https://schema.org/RefurbishedCondition' : 'https://schema.org/NewCondition'),
        ];
        if ($images) {
            $data['image'] = array_map(static fn ($i) => product_image_url($i['path']), $images);
        }
        // Offers are only exposed when prices are public (never for gated prices).
        if ($canPrice && ! auth()->loggedIn() && $p['unit_price'] !== null) {
            $data['offers'] = ['@type' => 'Offer', 'priceCurrency' => $p['currency'], 'price' => $p['unit_price'], 'availability' => 'https://schema.org/InStock'];
        }

        return $data;
    }

    public function enquire(int $id)
    {
        $product = model(ProductModel::class)->find($id);
        if (! $product || ! service('visibility')->canView($product)) {
            $this->notFound();
        }
        if ($r = $this->invalid(['message' => 'required|min_length[10]|max_length[2000]', 'quantity' => 'permit_empty|is_natural_no_zero'])) {
            return $r;
        }
        $supplier = model(CompanyModel::class)->find($product['company_id']);
        model(ProductEnquiryModel::class)->insert([
            'product_id' => $id, 'buyer_company_id' => service('companyContext')->companyId(), 'user_id' => $this->userId(),
            'quantity' => $this->request->getPost('quantity') ?: null, 'message' => $this->request->getPost('message'), 'status' => 'open',
        ]);
        service('notifications')->notifyCompany((int) $supplier['id'], 'enquiry.new', 'New enquiry for ' . $product['part_number'], mb_substr((string) $this->request->getPost('message'), 0, 200), 'supplier/enquiries');

        return redirect()->to(site_url('product/' . $product['slug']))->with('success', 'Your enquiry was sent. Replies appear under Buyer → Enquiries. Supplier identity stays confidential unless they choose to share it.');
    }

    public function toggleSave(int $id)
    {
        $product = model(ProductModel::class)->find($id);
        if (! $product || ! service('visibility')->canView($product)) {
            $this->notFound();
        }
        $m = model(SavedProductModel::class);
        if ($row = $m->where('user_id', $this->userId())->where('product_id', $id)->first()) {
            $m->delete($row['id']);
            $msg = 'Removed from saved inventory.';
        } else {
            $m->insert(['user_id' => $this->userId(), 'product_id' => $id]);
            $msg = 'Saved. Find it under Buyer → Saved inventory.';
        }

        return redirect()->back()->with('success', $msg);
    }
}
