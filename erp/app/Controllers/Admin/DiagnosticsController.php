<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\Diagnostics;

class DiagnosticsController extends BaseController
{
    public function index()
    {
        $checks = (new Diagnostics($this->db))->run();
        $grouped = [];
        foreach ($checks as $c) {
            $grouped[$c[0]][] = $c;
        }
        $counts = array_count_values(array_column($checks, 2));

        return $this->render('admin/diagnostics', [
            'pageTitle' => 'Diagnostics', 'breadcrumbs' => [['Diagnostics']],
            'grouped' => $grouped, 'counts' => $counts,
        ]);
    }
}
