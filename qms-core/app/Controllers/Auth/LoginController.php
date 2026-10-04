<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class LoginController extends BaseController
{
    public function show(): string
    {
        return view('auth/login', ['title' => 'Sign in']);
    }

    public function attempt()
    {
        $rules = [
            'username' => 'required|max_length[50]',
            'password' => 'required|max_length[200]',
        ];
        if (! $this->validateData($this->request->getPost(['username', 'password']), $rules)) {
            return redirect()->back()->withInput()->with('error', 'Enter your username and password.');
        }

        $result = service('auth')->attempt((string) $this->request->getPost('username'), (string) $this->request->getPost('password'));
        if (! $result['ok']) {
            return redirect()->back()->withInput()->with('error', $result['message']);
        }

        $intended = (string) session()->get('qms_intended');
        session()->remove('qms_intended');

        // Only same-site relative paths are honoured (no open redirect).
        if ($intended !== '' && str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return redirect()->to(site_url(ltrim($intended, '/')));
        }

        return redirect()->to(site_url('/'));
    }

    public function logout()
    {
        service('auth')->logout();

        return redirect()->to(site_url('login'))->with('success', 'You have been logged out.');
    }
}
