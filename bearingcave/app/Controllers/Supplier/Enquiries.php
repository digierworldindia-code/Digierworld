<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\ProductEnquiryModel;

class Enquiries extends BaseController
{
    public function index(): string
    {
        $rows = db_connect()->table('product_enquiries e')->select('e.*, p.name, p.part_number, p.slug, c.country_code AS buyer_country, c.verification_status AS buyer_verification')
            ->join('products p', 'p.id = e.product_id')->join('companies c', 'c.id = e.buyer_company_id')
            ->where('p.company_id', $this->company()['id'])->orderBy('e.status = \'open\'', 'DESC', false)->orderBy('e.id', 'DESC')->limit(200)->get()->getResultArray();

        return view('supplier/enquiries', ['title' => 'Product enquiries', 'area' => 'supplier', 'rows' => $rows]);
    }

    public function respond(int $id)
    {
        $e = db_connect()->table('product_enquiries e')->select('e.*')->join('products p', 'p.id = e.product_id')
            ->where('e.id', $id)->where('p.company_id', $this->company()['id'])->get()->getRowArray();
        if (! $e) {
            service('audit')->security('access.denied', "Supplier tried to answer enquiry #{$id}");
            $this->notFound();
        }
        if ($r = $this->invalid(['response' => 'required|min_length[2]|max_length[3000]'])) {
            return $r;
        }
        model(ProductEnquiryModel::class)->update($id, ['response' => $this->request->getPost('response'), 'status' => 'responded', 'responded_by' => $this->userId(), 'responded_at' => date('Y-m-d H:i:s')]);
        service('notifications')->notifyCompany((int) $e['buyer_company_id'], 'enquiry.responded', 'A supplier answered your enquiry', mb_substr((string) $this->request->getPost('response'), 0, 200), 'buyer/enquiries');

        return redirect()->back()->with('success', 'Reply sent to the buyer.');
    }
}
