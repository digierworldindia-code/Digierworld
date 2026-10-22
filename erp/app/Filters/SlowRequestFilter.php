<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Logs requests slower than app.slowRequestMs (default 3000 ms) with the
 * route, time, memory and number of SQL queries, so the pages behind
 * gateway timeouts (504) can be found in writable/logs. No query text,
 * parameters or form data are logged.
 */
class SlowRequestFilter implements FilterInterface
{
    /** Counted by the DBQuery event (app/Config/Events.php). */
    public static int $queries = 0;

    public static float $queryMs = 0.0;

    public static function countQuery(\CodeIgniter\Database\Query $query): void
    {
        self::$queries++;
        self::$queryMs += (float) $query->getDuration(6) * 1000;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (! is_float($start)) {
            return null;
        }
        $ms        = (int) round((microtime(true) - $start) * 1000);
        $threshold = (int) env('app.slowRequestMs', 3000);
        if ($threshold > 0 && $ms >= $threshold) {
            log_message('warning', 'SLOW REQUEST {ms} ms {method} {path} status {status}, {queries} SQL queries taking {qms} ms, peak memory {mem} MB', [
                'ms' => $ms, 'method' => $request->getMethod(), 'path' => '/' . ltrim($request->getUri()->getPath(), '/'),
                'status' => $response->getStatusCode(), 'queries' => self::$queries, 'qms' => (int) round(self::$queryMs), 'mem' => round(memory_get_peak_usage(true) / 1048576, 1),
            ]);
        }

        return null;
    }
}
