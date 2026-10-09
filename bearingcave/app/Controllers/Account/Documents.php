<?php

declare(strict_types=1);

namespace App\Controllers\Account;

class Documents extends AreaController
{
    public function index(): string
    {
        $rows = db_connect()->table('documents')->where('company_id', $this->cid())->where('deleted_at', null)->orderBy('id', 'DESC')->get()->getResultArray();

        return $this->page('account/documents', ['title' => 'Documents', 'documents' => $rows]);
    }
}
