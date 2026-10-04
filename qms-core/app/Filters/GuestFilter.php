<?php

declare(strict_types=1);

namespace App\Filters;

use App\Core\FilterInterface;
use App\Core\Request;
use App\Core\Response;

/** Pages for anonymous users only (login). */
class GuestFilter implements FilterInterface
{
    public function before(Request $request, ?array $arguments = null): ?Response
    {
        if (service('auth')->user() !== null) {
            return redirect()->to(site_url('/'));
        }

        return null;
    }

    public function after(Request $request, Response $response, ?array $arguments = null): ?Response
    {
        return null;
    }
}
