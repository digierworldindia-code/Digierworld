<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Services\Notify\Notifier;

/**
 * Integration status. Credentials (SMTP password, Google service-account
 * key, AI API key) live in .env / private files on the server — never in
 * the database, the code or this screen.
 */
class IntegrationsController extends BaseController
{
    public function index()
    {
        $email = config('Email');

        return $this->render('settings/integrations', [
            'pageTitle' => 'Integrations', 'breadcrumbs' => [['Settings'], ['Integrations']],
            'smtp' => [
                'configured' => Notifier::mailConfigured(),
                'protocol'   => $email->protocol,
                'host'       => $email->SMTPHost,
                'port'       => $email->SMTPPort,
                'from'       => $email->fromEmail,
                'user'       => $email->SMTPUser !== '' ? mask_tail($email->SMTPUser, 4) : '',
                'crypto'     => $email->SMTPCrypto,
                'lastTest'   => $this->db->table('audit_logs')->select('occurred_at, summary')->where('action', 'integration.smtp_test')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray(),
            ],
            'myEmail' => (string) (auth()->user()->email ?? ''),
        ]);
    }

    public function save($integration)
    {
        // Nothing on this screen stores credentials; later modules add their own non-secret settings here.
        return redirect()->to(site_url('settings/integrations'))->with('error', 'Credentials are configured in the server .env file (see docs/06-DEPLOYMENT.md), not through the browser.');
    }

    public function test($integration)
    {
        if ($integration !== 'smtp') {
            $this->notFound();
        }
        $to = (string) (auth()->user()->email ?? '');
        if (! Notifier::mailConfigured()) {
            return redirect()->back()->with('error', 'SMTP is not configured. Set email.* values in .env and restart PHP.');
        }
        $ok = service('notifier')->email($to, 'DGR Office test email', '<p>This is a test email from DGR Office sent at ' . esc(date('d-m-Y h:i A')) . '.</p><p>If you received it, SMTP is working.</p>');
        service('audit')->log('integration.smtp_test', 'integration', null, null, ['ok' => $ok], 'SMTP test to ' . mask_tail($to, 6) . ': ' . ($ok ? 'sent' : 'failed'));

        return redirect()->back()->with($ok ? 'success' : 'error', $ok ? 'Test email sent to ' . $to . '. Check the inbox (and spam).' : 'Sending failed. The server log has the SMTP error; check host, port, encryption and password in .env.');
    }
}
