<?php

namespace App\Controllers;

/** The caller's own in-app notifications only. */
class NotificationsController extends BaseController
{
    public function index()
    {
        [$page, $perPage, $offset] = $this->paging(25);
        $b     = $this->db->table('notifications')->where('user_id', $this->userId);
        $total = (clone $b)->countAllResults();
        $rows  = $b->orderBy('created_at', 'DESC')->limit($perPage, $offset)->get()->getResultArray();

        return $this->render('notifications/index', [
            'pageTitle' => 'Notifications', 'breadcrumbs' => [['Notifications']],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    public function feed()
    {
        $items = array_map(static fn (array $n) => [
            'title' => $n['title'],
            'body'  => $n['body'],
            'url'   => site_url('notifications/' . $n['id'] . '/open'),
            'read'  => $n['read_at'] !== null,
            'when'  => dmy($n['created_at'], true),
        ], service('notifier')->latest((int) $this->userId, 10));

        return $this->json(['items' => $items, 'unread' => service('notifier')->unreadCount((int) $this->userId)]);
    }

    public function open($id)
    {
        $n = $this->db->table('notifications')->where(['id' => $this->idOr404($id), 'user_id' => $this->userId])->get()->getRowArray();
        if ($n === null) {
            $this->notFound();
        }
        if ($n['read_at'] === null) {
            $this->db->table('notifications')->where('id', $n['id'])->update(['read_at' => date('Y-m-d H:i:s')]);
        }
        $link = (string) $n['link'];
        // only follow internal relative links
        if ($link === '' || preg_match('#^(https?:)?//#i', $link)) {
            return redirect()->to(site_url('notifications'));
        }

        return redirect()->to(site_url(ltrim($link, '/')));
    }

    public function readAll()
    {
        $this->db->table('notifications')->where(['user_id' => $this->userId, 'read_at' => null])->update(['read_at' => date('Y-m-d H:i:s')]);

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }
}
