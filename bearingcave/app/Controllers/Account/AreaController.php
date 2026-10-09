<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Controllers\BaseController;

/**
 * Base for controllers mounted under both /buyer and /supplier.
 */
abstract class AreaController extends BaseController
{
    protected function area(): string
    {
        return $this->company()['company_type'];
    }

    protected function cid(): int
    {
        return (int) $this->company()['id'];
    }

    protected function page(string $view, array $data = []): string
    {
        return view($view, $data + ['area' => $this->area(), 'company' => $this->company()]);
    }
}
