<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Supplier\Imports as SupplierImports;
use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;

/**
 * Admin imports on behalf of any supplier (reuses the supplier import flow).
 */
class Imports extends SupplierImports
{
    protected string $base = 'admin/imports';
    protected string $area = 'admin';

    protected function scopeJobs($m)
    {
        return $m;
    }

    protected function targetCompanyId(): int
    {
        $id = (int) $this->request->getPost('company_id');
        $c  = model(CompanyModel::class)->find($id);
        if (! $c || $c['company_type'] !== 'supplier') {
            throw new BusinessRuleException('Choose the supplier to import for.');
        }

        return $id;
    }
}
