<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;

/**
 * Business registration (user + company + owner membership).
 */
class Register extends BaseController
{
    public function choose()
    {
        if (auth()->loggedIn()) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('web/register_choose', ['title' => 'Create a business account', 'canonical' => site_url('register'), 'authWidth' => '760px']);
    }

    public function form(string $type)
    {
        if (auth()->loggedIn()) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('web/register', ['title' => $type === 'supplier' ? 'Register as a supplier' : 'Register as a buyer', 'type' => $type, 'robots' => 'index,follow', 'canonical' => site_url('register/' . $type), 'authWidth' => '620px']);
    }

    public function store(string $type)
    {
        if ((string) $this->request->getPost('website') !== '') {
            return redirect()->to(site_url('register'));
        }
        $rules = [
            'legal_name'   => 'required|min_length[2]|max_length[191]',
            'country_code' => 'required|exact_length[2]|is_not_unique[countries.iso2]',
            'city'         => 'permit_empty|max_length[100]',
            'phone'        => 'required|max_length[40]',
            'job_title'    => 'permit_empty|max_length[100]',
            'email'        => 'required|valid_email|max_length[191]|is_unique[auth_identities.secret]',
            'password'     => 'required|strong_password[]',
            'password_confirm' => 'required|matches[password]',
            'terms'        => 'required|in_list[1]',
        ];
        if ($type === 'supplier') {
            $rules['supplier_type'] = 'required|in_list[manufacturer,oem_supplier,aftermarket_supplier,distributor,trader,stockist,other]';
        }
        $messages = [
            'email' => ['is_unique' => 'An account with this email already exists. Please sign in instead.'],
            'terms' => ['required' => 'Please accept the terms to continue.', 'in_list' => 'Please accept the terms to continue.'],
        ];
        if ($r = $this->invalid($rules, $messages)) {
            return $r;
        }

        try {
            $result = service('companies')->register($type, $this->request->getPost(array_keys($rules)));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
        auth()->login($result['user']);

        return redirect()->to(site_url($type . '/dashboard'))->with('success', 'Welcome to BearingCave! Your free account is ready. Complete your company profile to get started.');
    }
}
