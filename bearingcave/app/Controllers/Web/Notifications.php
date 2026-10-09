<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\NotificationModel;

class Notifications extends BaseController
{
    public function index(): string
    {
        $m = model(NotificationModel::class)->where('user_id', $this->userId())->orderBy('id', 'DESC');

        return view('account/notifications', [
            'title' => 'Notifications', 'area' => $this->area(),
            'items' => $m->paginate(25), 'pager' => $m->pager,
        ]);
    }

    public function count()
    {
        return $this->response->setJSON(['unread' => service('notifications')->unreadCount($this->userId())]);
    }

    public function read(int $id)
    {
        $m = model(NotificationModel::class);
        $n = $m->where('user_id', $this->userId())->find($id);
        if (! $n) {
            $this->notFound();
        }
        $m->update($id, ['read_at' => date('Y-m-d H:i:s')]);

        return $n['link'] ? redirect()->to(site_url($n['link'])) : redirect()->back();
    }

    public function readAll()
    {
        model(NotificationModel::class)->where('user_id', $this->userId())->where('read_at', null)->set(['read_at' => date('Y-m-d H:i:s')])->update();

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }

    private function area(): string
    {
        if (auth()->user()->can('admin.access')) {
            return 'admin';
        }

        return service('companyContext')->company()['company_type'] ?? 'buyer';
    }
}
