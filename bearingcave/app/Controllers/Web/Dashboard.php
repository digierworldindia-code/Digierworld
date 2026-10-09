<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;

/**
 * Sends each user to the right workspace after sign-in.
 */
class Dashboard extends BaseController
{
    public function index()
    {
        if (session()->getTempdata('magicLogin')) {
            session()->removeTempdata('magicLogin');
            session()->set('magicLoginPasswordReset', true);

            return redirect()->to(site_url('account'))->with('warning', 'You signed in with a one-time link. Please set a new password now.');
        }
        $user = auth()->user();
        if ($user->can('admin.access')) {
            return redirect()->to(site_url('admin'));
        }
        $company = service('companyContext')->company();
        if ($company === null) {
            return view('web/no_company', ['title' => 'Account not linked']);
        }

        return redirect()->to(site_url($company['company_type'] . '/dashboard'));
    }
}
