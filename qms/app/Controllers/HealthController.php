<?php

namespace App\Controllers;

use Throwable;

/**
 * Liveness check for load balancers / uptime monitors. Reveals nothing beyond up/down.
 */
class HealthController extends BaseController
{
    public function index()
    {
        try {
            db_connect()->query('SELECT 1');
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }

        return $this->response
            ->setStatusCode($ok ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $ok ? 'ok' : 'unavailable']);
    }
}
