<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Database\Config as DbConfig;
use Tests\Support\BCTestCase;

/**
 * TEST 11: Simultaneous orders attempt to reserve the same inventory →
 * the database prevents duplicate reservation.
 */
final class T11ConcurrencyTest extends BCTestCase
{
    public function testParallelProcessesCannotOverReserve(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }
        $s   = $this->verifiedSupplier();
        $p   = $this->product($s['company'], [], 10);
        $pid = (int) $p['id'];
        $dir = WRITEPATH . 'uploads/test-fixtures';
        @mkdir($dir, 0750, true);
        $start = microtime(true) + 1.0; // barrier so both workers fire together

        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $child = pcntl_fork();
            if ($child === 0) {
                // Child: brand-new DB connection (never reuse the parent socket).
                // Fresh connection, fresh models (Factories cache) and fresh services:
                // nothing may touch the socket inherited from the parent process.
                $ref = new \ReflectionProperty(DbConfig::class, 'instances');
                $ref->setValue(null, []);
                \CodeIgniter\Config\Factories::reset();
                \Config\Services::reset(true);
                while (microtime(true) < $start) {
                    usleep(200);
                }
                try {
                    service('inventory', false)->reserve($pid, 4, 'concurrency_test', $i);
                    $result = 'ok';
                } catch (\Throwable $e) {
                    $result = 'fail:' . get_class($e) . ($e instanceof \CodeIgniter\Database\Exceptions\DatabaseException ? ' ' . $e->getMessage() : '');
                }
                file_put_contents("{$dir}/worker-{$i}.txt", $result);
                posix_kill(posix_getpid(), SIGKILL); // exit without running parent shutdown handlers
            }
            $children[] = $child;
        }
        foreach ($children as $c) {
            pcntl_waitpid($c, $status);
        }
        $results = [];
        for ($i = 0; $i < 6; $i++) {
            $results[] = (string) @file_get_contents("{$dir}/worker-{$i}.txt");
            @unlink("{$dir}/worker-{$i}.txt");
        }
        $ok = count(array_filter($results, static fn ($r) => $r === 'ok'));
        $this->assertSame(2, $ok, 'only two reservations of 4 fit into 10 units: ' . implode(',', $results));
        $this->assertSame(4, count(array_filter($results, static fn ($r) => str_contains($r, 'InsufficientStockException'))));
        $row = db_connect()->table('products')->where('id', $pid)->get()->getRowArray();
        $this->assertSame(8, (int) $row['stock_reserved']);
        $this->assertSame(10, (int) $row['stock_on_hand']);
    }

    public function testRowLockMakesSecondTransactionReevaluate(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], [], 10);
        $cfg = config('Database')->tests;
        $a = new \mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], (int) $cfg['port']);
        $b = new \mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], (int) $cfg['port']);
        $sql = 'UPDATE products SET stock_reserved = stock_reserved + 7 WHERE id = ' . (int) $p['id'] . ' AND (stock_on_hand - stock_reserved) >= 7';

        $a->begin_transaction();
        $a->query($sql);
        $this->assertSame(1, $a->affected_rows);
        $b->begin_transaction();
        $b->query($sql, MYSQLI_ASYNC); // blocks on A's row lock
        usleep(300000);
        $a->commit();
        $links = $errors = $rejects = [$b];
        mysqli_poll($links, $errors, $rejects, 5);
        $b->reap_async_query();
        $this->assertSame(0, $b->affected_rows, 'second writer re-evaluates the predicate after the lock is released');
        $b->commit();
        $a->close();
        $b->close();
    }
}
