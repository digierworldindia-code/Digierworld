<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;

/**
 * GST rates (effective-dated, accountant-reviewable) and tax treatments.
 * Tax TYPE (CGST+SGST / IGST) is decided per document from place of supply;
 * tax TREATMENT and RATE are per line — three separate concepts.
 */
class TaxController extends BaseController
{
    public function index()
    {
        return $this->render('settings/tax', [
            'pageTitle'   => 'Tax rates & treatments',
            'breadcrumbs' => [['Settings'], ['Tax rates & treatments']],
            'rates'       => $this->db->table('tax_rates t')->select('t.*, p.full_name AS reviewer')->join('user_profiles p', 'p.user_id = t.reviewed_by', 'left')->orderBy('t.rate')->orderBy('t.effective_from', 'DESC')->get()->getResultArray(),
            'treatments'  => $this->db->table('tax_treatments')->orderBy('sort_order')->get()->getResultArray(),
            'services'    => $this->db->table('services')->select('review_status, COUNT(*) n')->groupBy('review_status')->get()->getResultArray(),
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function saveRate($id = null)
    {
        $before = $id ? ($this->db->table('tax_rates')->where('id', (int) $id)->get()->getRowArray() ?? $this->notFound()) : null;
        $rate   = (string) $this->request->getPost('rate');
        $from   = ymd((string) $this->request->getPost('effective_from'));
        $to     = ymd((string) $this->request->getPost('effective_to'));
        if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            return redirect()->back()->with('error', 'GST rate must be between 0 and 100.');
        }
        if (! $from) {
            return redirect()->back()->with('error', 'Effective-from date is required.');
        }
        if ($to && $to < $from) {
            return redirect()->back()->with('error', 'Effective-to must be after effective-from.');
        }
        $review = $this->request->getPost('review_status') === 'accountant_reviewed' ? 'accountant_reviewed' : 'suggested';
        $data = [
            'name'           => trim((string) $this->request->getPost('name')) ?: ('GST ' . rtrim(rtrim($rate, '0'), '.') . '%'),
            'rate'           => number_format((float) $rate, 3, '.', ''),
            'cess_rate'      => number_format((float) ($this->request->getPost('cess_rate') ?: 0), 3, '.', ''),
            'effective_from' => $from,
            'effective_to'   => $to,
            'review_status'  => $review,
            'notes'          => $this->post('notes'),
            'is_active'      => $this->request->getPost('is_active') === null && $before !== null ? 0 : 1,
            'updated_by'     => $this->userId,
        ];
        if ($review === 'accountant_reviewed' && ($before['review_status'] ?? '') !== 'accountant_reviewed') {
            $data['reviewed_by'] = $this->userId;
            $data['reviewed_at'] = date('Y-m-d H:i:s');
        }
        if ($before === null) {
            $data['created_by'] = $this->userId;
            $this->db->table('tax_rates')->insert($data);
            $id = (int) $this->db->insertID();
        } else {
            $this->db->table('tax_rates')->where('id', $before['id'])->update($data);
        }
        service('audit')->log($before ? 'tax_rate.updated' : 'tax_rate.created', 'tax_rate', (int) $id, $before, $data, 'Tax rate ' . $data['name']);
        if ($review === 'accountant_reviewed') {
            service('settingsStore')->markSetupStep('tax');
        }

        return redirect()->back()->with('success', 'Tax rate saved.');
    }

    public function saveTreatment($code)
    {
        $t = $this->db->table('tax_treatments')->where('code', (string) $code)->get()->getRowArray() ?? $this->notFound();
        if ($t['code'] === 'regular' && ! $this->request->getPost('is_enabled')) {
            return redirect()->back()->with('error', 'The regular taxable treatment cannot be disabled.');
        }
        $data = ['is_enabled' => $this->request->getPost('is_enabled') ? 1 : 0, 'notes' => $this->post('notes')];
        $this->db->table('tax_treatments')->where('code', $t['code'])->update($data);
        service('audit')->log('tax_treatment.updated', 'tax_treatment', null, $t, $data, 'Treatment ' . $t['code'] . ($data['is_enabled'] ? ' enabled' : ' disabled'));

        return redirect()->back()->with('success', 'Treatment updated.');
    }
}
