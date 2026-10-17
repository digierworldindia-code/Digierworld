<?php

namespace App\Controllers;

/** Unauthenticated liveness probe for uptime monitors (no internal details). */
class HealthController extends BaseController
{
    public function index()
    {
        try {
            $this->db->query('SELECT 1');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }

        return $this->response->setStatusCode($ok ? 200 : 503)->setJSON(['status' => $ok ? 'ok' : 'degraded']);
    }
}
