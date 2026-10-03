<?php

namespace App\Services\Settings;

use App\Exceptions\ValidationException;
use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;

/**
 * Typed access to system_settings (company, numbering, regional, Google,
 * workflow, security, gauge and inspection settings). Values are cached for the
 * duration of the request; every change is audited.
 */
class SettingsService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->rows()[$key] ?? null;
        if ($row === null) {
            return $default;
        }

        return $this->cast($row['setting_value'], $row['value_type']) ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return $value === null ? $default : (string) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : (bool) $value;
    }

    /**
     * Settings of one group, ordered as seeded.
     *
     * @return list<array<string, mixed>>
     */
    public function group(string $group): array
    {
        return array_values(array_filter($this->rows(), static fn (array $r): bool => $r['setting_group'] === $group));
    }

    /**
     * Updates several settings of one group in a single audited change.
     * Unknown keys and keys of other groups are rejected.
     *
     * @param array<string, mixed> $values key => new raw value
     */
    public function update(string $group, array $values, ?int $userId, AuditService $audit, string $now): void
    {
        $rows    = $this->rows();
        $before  = [];
        $after   = [];
        $updates = [];

        foreach ($values as $key => $value) {
            $row = $rows[$key] ?? null;
            if ($row === null || $row['setting_group'] !== $group) {
                throw ValidationException::single($key, 'Unknown setting.');
            }
            $normalised = $this->normalise($value, $row['value_type']);
            if ($normalised !== $row['setting_value']) {
                $before[$key]  = $row['setting_value'];
                $after[$key]   = $normalised;
                $updates[$key] = $normalised;
            }
        }

        foreach ($updates as $key => $value) {
            $this->db->table('system_settings')
                ->where('setting_key', $key)
                ->update(['setting_value' => $value, 'updated_at' => $now, 'updated_by' => $userId]);
        }

        if ($updates !== []) {
            $audit->log('UPDATE', 'settings', null, $before, $after, $group);
        }

        $this->rows = null;
    }

    /** Clears the request cache (after writes performed elsewhere). */
    public function refresh(): void
    {
        $this->rows = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->db->table('system_settings')->orderBy('id')->get()->getResultArray() as $row) {
                $this->rows[$row['setting_key']] = $row;
            }
        }

        return $this->rows;
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'INTEGER' => is_numeric($value) ? (int) $value : null,
            'BOOLEAN' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'JSON'    => json_decode($value, true),
            default   => $value,
        };
    }

    private function normalise(mixed $value, string $type): string
    {
        return match ($type) {
            'BOOLEAN' => in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'INTEGER' => (string) (int) $value,
            'JSON'    => is_string($value) ? $value : (string) json_encode($value),
            default   => trim((string) $value),
        };
    }
}
