<?php

namespace App\Services\Admin;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use ZipArchive;

/**
 * Database (and optional private-files) backups written to
 * writable/backups. Pure PHP — works on shared hosting without mysqldump.
 * Encrypted columns stay encrypted; the encryption key lives in .env and
 * must be backed up separately (without it bank/CIF fields cannot be read).
 */
class BackupService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function dir(): string
    {
        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
    }

    /** @return list<array{name: string, size: int, time: int}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . 'dgr-*.{sql.gz,zip}', GLOB_BRACE) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);

        return $out;
    }

    public function path(string $name): string
    {
        if (! preg_match('/^dgr-\d{8}-\d{6}(-files)?\.(sql\.gz|zip)$/', $name) || ! is_file(self::dir() . $name)) {
            throw new RuntimeException('Backup not found.');
        }

        return self::dir() . $name;
    }

    /** @return list<string> created file names */
    public function create(bool $withFiles = false, int $keep = 14): array
    {
        if (! is_dir(self::dir()) && ! mkdir(self::dir(), 0750, true)) {
            throw new RuntimeException('writable/backups is not writable.');
        }
        $stamp = date('Ymd-His');
        $names = [$this->dumpDatabase('dgr-' . $stamp . '.sql.gz')];
        if ($withFiles) {
            $names[] = $this->zipUploads('dgr-' . $stamp . '-files.zip');
        }
        $this->prune(max(1, $keep));

        return $names;
    }

    private function dumpDatabase(string $name): string
    {
        $tmp = self::dir() . $name . '.part';
        $gz  = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Cannot write the backup file.');
        }
        $w = static function (string $s) use ($gz): void {
            gzwrite($gz, $s);
        };
        $ver = (string) ($this->db->query('SELECT VERSION() v')->getRow()->v ?? '');
        $w("-- DGR Office database backup\n-- Database: {$this->db->getDatabase()} · Server: {$ver} · Created: " . date('d-m-Y H:i:s') . " IST\n");
        $w("-- Restore: gunzip -c {$name} | mysql -u USER -p DATABASE   (into an EMPTY database)\n");
        $w("-- Encrypted columns need the same encryption.key from .env to be readable.\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");
        $tables = array_map(static fn ($r) => array_values($r)[0], $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->getResultArray());
        $conn   = $this->db->connID;
        $counts = [];
        // session time zone to UTC so DATETIME/TIMESTAMP values are dumped unchanged; one consistent
        // snapshot so rows written by other users during the backup cannot leave it half-updated
        $this->db->query("SET time_zone = '+00:00'");
        $this->db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            foreach ($tables as $t) {
                $create = $this->db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->getRowArray()['Create Table'];
                $w("DROP TABLE IF EXISTS `{$t}`;\n{$create};\n");
                // one streamed (unbuffered) read per table, written in batches of 500 rows: memory stays
                // flat and big tables are not re-scanned the way LIMIT/OFFSET paging would
                $res  = $conn->query('SELECT * FROM `' . str_replace('`', '``', $t) . '`', MYSQLI_USE_RESULT);
                $rows = 0;
                $vals = [];
                while ($row = $res->fetch_row()) {
                    $rows++;
                    $vals[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string((string) $v) . "'", $row)) . ')';
                    if (count($vals) === 500) {
                        $w("INSERT INTO `{$t}` VALUES\n" . implode(",\n", $vals) . ";\n");
                        $vals = [];
                    }
                }
                $res->free();
                if ($vals !== []) {
                    $w("INSERT INTO `{$t}` VALUES\n" . implode(",\n", $vals) . ";\n");
                }
                $counts[$t] = $rows;
                $w("\n");
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            gzclose($gz);
            @unlink($tmp);

            throw $e;
        } finally {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone(config('App')->appTimezone)))->format('P');
            $this->db->query("SET time_zone = '" . $offset . "'");
        }
        $w('-- rows: ' . json_encode($counts) . "\n");
        $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- end of backup\n");
        gzclose($gz);
        rename($tmp, self::dir() . $name);
        @chmod(self::dir() . $name, 0640);

        return $name;
    }

    /**
     * Reads a database backup back from start to end and checks it: the gzip
     * stream is intact, the file ends with its end marker, every table has
     * its CREATE TABLE, and each table has exactly as many rows as were read
     * from the database.
     *
     * @return array{tables: int, rows: int}
     */
    public function verify(string $name): array
    {
        $gz = gzopen($this->path($name), 'rb');
        if ($gz === false) {
            throw new RuntimeException('Cannot open backup ' . $name . '.');
        }
        $created  = [];
        $found    = [];
        $expected = null;
        $ended    = false;
        $current  = null;
        while (($line = gzgets($gz)) !== false) {
            if (str_starts_with($line, 'CREATE TABLE `')) {
                $created[] = substr($line, 14, strpos($line, '`', 14) - 14);
            } elseif (str_starts_with($line, 'INSERT INTO `')) {
                $current = substr($line, 13, strpos($line, '`', 13) - 13);
            } elseif ($current !== null && str_starts_with($line, '(')) {
                $found[$current] = ($found[$current] ?? 0) + 1;
            } elseif (str_starts_with($line, '-- rows: ')) {
                $expected = json_decode(substr($line, 9), true);
            } elseif (str_starts_with($line, '-- end of backup')) {
                $ended = true;
            }
        }
        $intact = gzeof($gz);
        gzclose($gz);
        if (! $intact || ! $ended || ! is_array($expected)) {
            throw new RuntimeException('Backup ' . $name . ' is incomplete (it does not end with its end marker).');
        }
        if (array_diff(array_keys($expected), $created) !== []) {
            throw new RuntimeException('Backup ' . $name . ' is missing table definitions: ' . implode(', ', array_diff(array_keys($expected), $created)) . '.');
        }
        foreach ($expected as $table => $rows) {
            if ((int) ($found[$table] ?? 0) !== (int) $rows) {
                throw new RuntimeException("Backup {$name}: table {$table} has " . (int) ($found[$table] ?? 0) . " rows, expected {$rows}.");
            }
        }

        return ['tables' => count($expected), 'rows' => (int) array_sum($expected)];
    }

    private function zipUploads(string $name): string
    {
        $zip = new ZipArchive();
        if ($zip->open(self::dir() . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the files archive.');
        }
        $root = realpath(rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads');
        if ($root !== false) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), 'uploads/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/'));
                }
            }
        }
        $zip->close();
        @chmod(self::dir() . $name, 0640);

        return $name;
    }

    private function prune(int $keep): void
    {
        $byKind = ['sql' => [], 'files' => []];
        foreach ($this->list() as $b) {
            $byKind[str_ends_with($b['name'], '.zip') ? 'files' : 'sql'][] = $b['name'];
        }
        foreach ($byKind as $names) {
            foreach (array_slice($names, $keep) as $old) {
                @unlink(self::dir() . $old);
            }
        }
    }
}
