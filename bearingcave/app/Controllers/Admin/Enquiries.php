<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\ProductEnquiryModel;

/**
 * Product enquiries routed to BearingCave because the supplier's plan has no
 * direct enquiries (workbook: "Direct enquiry submission from product pages
 * to suppliers" — Not Verified Supplier: No). Staff answer the buyer or pass
 * the enquiry on to the supplier. Direct enquiries are listed read-only.
 */
class Enquiries extends AdminController
{
    private function base()
    {
        return db_connect()->table('product_enquiries e')
            ->select('e.*, p.name, p.part_number, p.slug, p.company_id AS supplier_id, s.legal_name AS supplier_name, s.verification_status AS supplier_verification, b.legal_name AS buyer_name, b.country_code AS buyer_country')
            ->join('products p', 'p.id = e.product_id')
            ->join('companies s', 's.id = p.company_id')
            ->join('companies b', 'b.id = e.buyer_company_id');
    }

    public function index(): string
    {
        $routing = $this->request->getGet('routing') === 'direct' ? 'direct' : 'platform';
        $status  = (string) $this->request->getGet('status');
        $b       = $this->base()->where('e.routing', $routing);
        if (in_array($status, ['open', 'responded', 'closed', 'converted'], true)) {
            $b->where('e.status', $status);
        }
        $page  = max(1, (int) $this->request->getGet('page'));
        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy("e.status = 'open'", 'DESC', false)->orderBy('e.id', 'DESC')->limit(30, ($page - 1) * 30)->get()->getResultArray();

        return $this->page('enquiries', [
            'title' => 'Product enquiries', 'rows' => $rows, 'routing' => $routing, 'status' => $status,
            'page' => $page, 'pages' => (int) ceil(max(1, $total) / 30),
            'openPlatform' => db_connect()->table('product_enquiries')->where('routing', 'platform')->where('status', 'open')->where('forwarded_at', null)->countAllResults(),
        ]);
    }

    private function platformEnquiry(int $id): array
    {
        $e = $this->base()->where('e.id', $id)->where('e.routing', 'platform')->get()->getRowArray();
        if (! $e) {
            $this->notFound();
        }

        return $e;
    }

    public function respond(int $id)
    {
        $e = $this->platformEnquiry($id);
        if ($r = $this->invalid(['response' => 'required|min_length[2]|max_length[3000]'])) {
            return $r;
        }
        model(ProductEnquiryModel::class)->update($id, ['response' => $this->request->getPost('response'), 'status' => 'responded', 'responded_by' => $this->userId(), 'responded_at' => date('Y-m-d H:i:s')]);
        service('notifications')->notifyCompany((int) $e['buyer_company_id'], 'enquiry.responded', 'BearingCave answered your enquiry', mb_substr((string) $this->request->getPost('response'), 0, 200), 'buyer/enquiries');
        service('audit')->log('enquiry.answered_by_platform', ['entity_type' => 'product_enquiry', 'entity_id' => $id]);

        return redirect()->back()->with('success', 'Reply sent to the buyer.');
    }

    public function forward(int $id)
    {
        $e = $this->platformEnquiry($id);
        if ($e['forwarded_at'] !== null) {
            return redirect()->back()->with('error', 'This enquiry was already passed to the supplier.');
        }
        model(ProductEnquiryModel::class)->update($id, ['forwarded_at' => date('Y-m-d H:i:s'), 'forwarded_by' => $this->userId()]);
        service('notifications')->notifyCompany((int) $e['supplier_id'], 'enquiry.new', 'Enquiry for ' . $e['part_number'] . ' (passed on by BearingCave)', mb_substr((string) $e['message'], 0, 200), 'supplier/enquiries');
        service('audit')->log('enquiry.forwarded', ['entity_type' => 'product_enquiry', 'entity_id' => $id, 'company_id' => (int) $e['supplier_id']]);

        return redirect()->back()->with('success', 'Enquiry passed to the supplier. The buyer\'s identity stays confidential.');
    }

    public function close(int $id)
    {
        $this->platformEnquiry($id);
        model(ProductEnquiryModel::class)->update($id, ['status' => 'closed']);

        return redirect()->back()->with('success', 'Enquiry closed.');
    }
}
