<?php

namespace App\Controllers\Admin;

use CodeIgniter\HTTP\RedirectResponse;

/** In-app notifications for the signed-in user (and unaddressed staff notices). */
class Notifications extends AdminController
{
    public function index(): string
    {
        $builder = db_connect()->table('notifications')
            ->groupStart()->where('user_id', $this->ctx->userId())->orWhere('user_id', null)->groupEnd()
            ->where('dealer_id', null)
            ->orderBy('created_at', 'DESC');

        return $this->render('admin/notifications', 'Notifications', 'admin/notifications', ['list' => $this->paginate($builder, 50)]);
    }

    public function read(string $id): RedirectResponse
    {
        return $this->act(function () use ($id): void {
            db_connect()->table('notifications')->where('id', $id)->where('read_at', null)
                ->groupStart()->where('user_id', $this->ctx->userId())->orWhere('user_id', null)->groupEnd()
                ->update(['read_at' => utc_now()]);
        }, 'Marked as read.', site_url('admin/notifications'));
    }
}
