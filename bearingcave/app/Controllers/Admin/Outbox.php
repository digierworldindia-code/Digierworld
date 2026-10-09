<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\EmailOutboxModel;

class Outbox extends AdminController
{
    public function index(): string
    {
        $m = model(EmailOutboxModel::class);
        if ($s = $this->request->getGet('status')) {
            $m->where('status', $s);
        }
        $m->orderBy('id', 'DESC');

        return $this->page('outbox', ['title' => 'Email outbox', 'rows' => $m->paginate(30), 'pager' => $m->pager, 'enabled' => (bool) policy('email.delivery_enabled', false),
            'counts' => array_column(db_connect()->table('email_outbox')->select('status, COUNT(*) n')->groupBy('status')->get()->getResultArray(), 'n', 'status'),
            'sms' => policy('notifications.sms_provider', 'none'), 'whatsapp' => policy('notifications.whatsapp_provider', 'none')]);
    }

    public function retry(int $id)
    {
        $row = model(EmailOutboxModel::class)->find($id) ?? $this->notFound();
        if (! policy('email.delivery_enabled', false)) {
            return redirect()->back()->with('error', 'Email delivery is disabled in Platform settings. Configure SMTP first.');
        }
        if ($row['status'] === 'disabled') {
            model(EmailOutboxModel::class)->update($id, ['status' => 'queued']);
        }
        $ok = service('notifications')->deliver($id);

        return redirect()->back()->with($ok ? 'success' : 'error', $ok ? 'The mail server accepted the message.' : 'Delivery failed — see the error column.');
    }
}
