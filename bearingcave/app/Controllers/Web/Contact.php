<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\ContactLeadModel;

class Contact extends BaseController
{
    public function index(): string
    {
        return view('web/contact', ['title' => 'Contact BearingCave', 'canonical' => site_url('contact'), 'metaDescription' => 'Talk to the BearingCave team about buying, selling, membership, inspection or logistics.']);
    }

    public function send()
    {
        if ((string) $this->request->getPost('website') !== '') {
            return redirect()->to(site_url('contact'))->with('success', 'Thank you — we will be in touch.'); // honeypot
        }
        if ($r = $this->invalid([
            'name' => 'required|max_length[120]', 'email' => 'required|valid_email|max_length[191]', 'company_name' => 'permit_empty|max_length[191]',
            'phone' => 'permit_empty|max_length[40]', 'country_code' => 'permit_empty|exact_length[2]',
            'enquiry_type' => 'required|in_list[general,buyer,supplier,membership,inspection,logistics,partnership]', 'message' => 'required|min_length[10]|max_length[4000]',
        ])) {
            return $r;
        }
        $id = model(ContactLeadModel::class)->insert([
            'name' => $this->request->getPost('name'), 'company_name' => $this->request->getPost('company_name'), 'email' => $this->request->getPost('email'),
            'phone' => $this->request->getPost('phone'), 'country_code' => $this->request->getPost('country_code') ?: null,
            'enquiry_type' => $this->request->getPost('enquiry_type'), 'message' => $this->request->getPost('message'), 'status' => 'new', 'ip_address' => $this->request->getIPAddress(),
        ]);
        service('notifications')->notifyStaff(['support_executive'], 'lead.new', 'New contact enquiry', (string) $this->request->getPost('name') . ' — ' . $this->request->getPost('enquiry_type'), 'admin/leads');

        return redirect()->to(site_url('contact'))->with('success', 'Thank you. Your message has been recorded (reference #' . $id . ') and our team will respond.');
    }
}
