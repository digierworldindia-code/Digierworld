<?php

namespace App\Controllers;

/**
 * Agency service catalogue: default descriptions, SAC, unit, rate and tax.
 * SAC/rate defaults are editable data; only users with settings.tax.edit can
 * mark them "accountant reviewed".
 */
class ServicesController extends BaseController
{
    public function index()
    {
        $q = trim((string) $this->request->getGet('q'));
        $b = $this->db->table('services s')->select('s.*, t.rate AS tax_rate')->join('tax_rates t', 't.id = s.default_tax_rate_id', 'left');
        if ($q !== '') {
            $b->groupStart()->like('s.name', $q)->orLike('s.code', $q)->orLike('s.category', $q)->orLike('s.sac_code', $q)->groupEnd();
        }
        if ($this->request->getGet('inactive') === null) {
            $b->where('s.is_active', 1);
        }

        return $this->render('services/index', [
            'pageTitle' => 'Services catalogue', 'breadcrumbs' => [['Services catalogue']],
            'rows' => $b->orderBy('s.category')->orderBy('s.name')->get()->getResultArray(), 'q' => $q,
        ]);
    }

    /** JSON for the invoice line picker. */
    public function lookup()
    {
        $q    = trim((string) $this->request->getGet('q'));
        $date = ymd((string) $this->request->getGet('date')) ?? date('Y-m-d');
        $b    = $this->db->table('services s')->select('s.id, s.code, s.name, s.description, s.sac_code, s.unit, s.default_rate, s.default_tax_treatment, s.pos_rule, s.review_status, t.rate AS gst_rate, t.effective_from, t.effective_to')
            ->join('tax_rates t', 't.id = s.default_tax_rate_id', 'left')->where('s.is_active', 1);
        if ($q !== '') {
            $b->groupStart()->like('s.name', $q)->orLike('s.code', $q)->orLike('s.category', $q)->groupEnd();
        }
        $rows = $b->orderBy('s.name')->limit(30)->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['rate_effective'] = $r['gst_rate'] !== null && $r['effective_from'] <= $date && ($r['effective_to'] === null || $r['effective_to'] >= $date);
        }
        unset($r);

        return $this->json(['items' => $rows]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function edit($id)
    {
        return $this->form($this->db->table('services')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound());
    }

    private function form(?array $s)
    {
        return $this->render('services/form', [
            'pageTitle'   => $s ? 'Edit service' : 'New service',
            'breadcrumbs' => [['Services catalogue', 'services'], [$s ? $s['name'] : 'New']],
            's'           => $s,
            'rates'       => $this->db->table('tax_rates')->where('is_active', 1)->orderBy('rate')->get()->getResultArray(),
            'treatments'  => $this->db->table('tax_treatments')->where('is_enabled', 1)->orderBy('sort_order')->get()->getResultArray(),
            'canReview'   => $this->can('settings.tax.edit'),
        ]);
    }

    public function create()
    {
        return $this->save(null);
    }

    public function update($id)
    {
        return $this->save($this->db->table('services')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound());
    }

    private function save(?array $before)
    {
        $code = strtoupper(trim(preg_replace('/[^A-Z0-9_\-]+/i', '', (string) $this->request->getPost('code'))));
        $name = trim((string) $this->request->getPost('name'));
        $sac  = preg_replace('/\D/', '', (string) $this->request->getPost('sac_code'));
        $errors = [];
        if ($code === '') {
            $errors['code'] = 'Short code is required (e.g. SEO).';
        }
        if ($name === '') {
            $errors['name'] = 'Service name is required.';
        }
        if ($sac !== '' && (strlen($sac) < 4 || strlen($sac) > 8)) {
            $errors['sac_code'] = 'SAC/HSN should be 4–8 digits.';
        }
        if ($this->db->table('services')->where('code', $code)->where('id !=', $before['id'] ?? 0)->countAllResults() > 0) {
            $errors['code'] = 'This code is already used.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($errors);
        }
        $data = [
            'code' => $code, 'name' => $name, 'category' => $this->post('category'), 'description' => $this->post('description'),
            'sac_code' => $sac ?: null, 'unit' => $this->post('unit', 'Service'),
            'default_rate' => number_format((float) str_replace(',', '', (string) $this->post('default_rate', '0')), 2, '.', ''),
            'default_tax_rate_id' => $this->post('default_tax_rate_id') ? (int) $this->post('default_tax_rate_id') : null,
            'default_tax_treatment' => $this->db->table('tax_treatments')->where('code', $this->post('default_tax_treatment'))->countAllResults() ? $this->post('default_tax_treatment') : 'regular',
            'pos_rule' => $this->post('pos_rule') === 'review_required' ? 'review_required' : 'default',
            'is_recurring_type' => $this->request->getPost('is_recurring_type') ? 1 : 0,
            'is_active' => $before === null || $this->request->getPost('is_active') ? 1 : 0,
            'updated_by' => $this->userId,
        ];
        if ($this->can('settings.tax.edit')) {
            $data['review_status'] = $this->post('review_status') === 'accountant_reviewed' ? 'accountant_reviewed' : 'suggested';
        } elseif ($before !== null && ($before['sac_code'] !== $data['sac_code'] || (string) $before['default_tax_rate_id'] !== (string) $data['default_tax_rate_id'])) {
            $data['review_status'] = 'suggested'; // tax-relevant change by non-reviewer resets the review
        }
        if ($before === null) {
            $this->db->table('services')->insert($data + ['created_by' => $this->userId]);
            $id = (int) $this->db->insertID();
        } else {
            $id = (int) $before['id'];
            $this->db->table('services')->where('id', $id)->update($data);
        }
        service('audit')->log($before ? 'service.updated' : 'service.created', 'service', $id, $before, $data, 'Service ' . $code);

        return redirect()->to(site_url('services'))->with('success', 'Service saved.');
    }
}
