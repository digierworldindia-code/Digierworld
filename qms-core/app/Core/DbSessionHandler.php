<?php

declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * PHP session storage in the ci_sessions table.
 *
 * - Sessions survive PHP-FPM restarts and work with several web servers.
 * - One request per session at a time (MySQL GET_LOCK), so parallel autosave
 *   requests from a tablet cannot overwrite each other's session data.
 * - Strict mode: an unknown session id sent by a browser is never adopted
 *   (protects against session fixation).
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, SessionIdInterface
{
    private ?string $lock = null;

    private string $fingerprint = '';

    private bool $rowExists = false;

    public function __construct(
        private readonly Database $db,
        private readonly string $ip,
        private readonly int $lockTimeout = 30,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();

        return true;
    }

    public function read(string $id): string|false
    {
        if (! $this->acquireLock($id)) {
            return false;
        }

        $data            = $this->db->query('SELECT data FROM ci_sessions WHERE id = ?', [$id])->getRow('data');
        $this->rowExists = $data !== null;
        $data            = (string) ($data ?? '');
        $this->fingerprint = md5($data);

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        if ($this->lock === null && ! $this->acquireLock($id)) {
            return false;
        }

        if ($this->rowExists) {
            if (md5($data) === $this->fingerprint) {
                $this->db->query('UPDATE ci_sessions SET timestamp = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            } else {
                $this->db->query('UPDATE ci_sessions SET data = ?, ip_address = ?, timestamp = UTC_TIMESTAMP() WHERE id = ?', [$data, $this->ip, $id]);
            }
        } else {
            try {
                $this->db->query('INSERT INTO ci_sessions (id, ip_address, timestamp, data) VALUES (?, ?, UTC_TIMESTAMP(), ?)', [$id, $this->ip, $data]);
            } catch (DatabaseException $e) {
                if ($e->getCode() !== 1062) {
                    throw $e;
                }
                $this->db->query('UPDATE ci_sessions SET data = ?, ip_address = ?, timestamp = UTC_TIMESTAMP() WHERE id = ?', [$data, $this->ip, $id]);
            }
            $this->rowExists = true;
        }
        $this->fingerprint = md5($data);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->query('DELETE FROM ci_sessions WHERE id = ?', [$id]);
        $this->rowExists   = false;
        $this->fingerprint = '';

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $this->db->query('DELETE FROM ci_sessions WHERE timestamp < UTC_TIMESTAMP() - INTERVAL ? SECOND', [$max_lifetime]);

        return $this->db->affectedRows();
    }

    public function create_sid(): string
    {
        // 256 bits from the CSPRNG; the new id is locked before use.
        $id = bin2hex(random_bytes(32));
        $this->releaseLock();
        $this->acquireLock($id);
        $this->rowExists   = false;
        $this->fingerprint = '';

        return $id;
    }

    public function validateId(string $id): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $id)) {
            return false;
        }

        return $this->db->query('SELECT 1 AS ok FROM ci_sessions WHERE id = ?', [$id])->getRow('ok') !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->query('UPDATE ci_sessions SET timestamp = UTC_TIMESTAMP() WHERE id = ?', [$id]);

        return true;
    }

    private function acquireLock(string $id): bool
    {
        $name = 'qms_session_' . md5($id);
        if ($this->lock === $name) {
            return true;
        }
        $this->releaseLock();

        $ok = (int) $this->db->query('SELECT GET_LOCK(?, ?) AS l', [$name, $this->lockTimeout])->getRow('l');
        if ($ok !== 1) {
            log_message('error', 'Session lock timeout for session {id}', ['id' => substr($id, 0, 8)]);

            return false;
        }
        $this->lock = $name;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            try {
                $this->db->query('SELECT RELEASE_LOCK(?) AS r', [$this->lock]);
            } catch (\Throwable) {
                // connection already gone at shutdown
            }
            $this->lock = null;
        }
    }
}
