<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\ContactLeadModel;

class Leads extends AdminController
{
    public function index(): string
    {
        $m = model(ContactLeadModel::class);
        if ($s = $this->request->getGet('status')) {
            $m->where('status', $s);
        }
        $m->orderBy('id', 'DESC');

        return $this->page('leads', ['title' => 'Contact leads', 'rows' => $m->paginate(30), 'pager' => $m->pager]);
    }

    public function update(int $id)
    {
        model(ContactLeadModel::class)->find($id) ?? $this->notFound();
        $st = (string) $this->request->getPost('status');
        if (in_array($st, ['new', 'contacted', 'closed'], true)) {
            model(ContactLeadModel::class)->update($id, ['status' => $st]);
        }

        return redirect()->back()->with('success', 'Lead updated.');
    }
}
