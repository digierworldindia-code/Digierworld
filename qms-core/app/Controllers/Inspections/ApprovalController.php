<?php

namespace App\Controllers\Inspections;

use App\Controllers\BaseController;
use App\Libraries\Paging;

/**
 * Approval inbox: reports waiting for a stage the user may sign.
 */
class ApprovalController extends BaseController
{
    public function index(): string
    {
        $filters = ['type' => (string) $this->request->getGet('type'), 'q' => (string) $this->request->getGet('q')];
        $paging  = Paging::fromRequest($this->request, 30);

        return $this->render('approvals/index', [
            'title'   => 'Approvals',
            'rows'    => service('workflow')->inbox($this->currentUser(), $filters, $paging),
            'filters' => $filters,
            'paging'  => $paging,
            'types'   => service('masterData')->lookup('report-types'),
        ]);
    }
}
