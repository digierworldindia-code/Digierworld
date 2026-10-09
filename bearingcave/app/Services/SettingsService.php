<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSettingModel;

/**
 * Single source of configurable business policy (fees, percentages, rules).
 *
 * Controllers and services must read policy from here instead of hard-coding
 * values. Each setting carries an approval_status so that client decisions
 * that are still open are visibly flagged "Pending Approval".
 */
class SettingsService
{
    /** @var array<string,array>|null */
    private ?array $cache = null;

    public function __construct(private PlatformSettingModel $model = new PlatformSettingModel())
    {
    }

    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->model->findAll() as $row) {
                $this->cache[$row['setting_key']] = $row;
            }
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->load()[$key] ?? null;
        if ($row === null || $row['setting_value'] === null) {
            return $default;
        }

        return $this->cast($row['setting_value'], $row['value_type']);
    }

    public function row(string $key): ?array
    {
        return $this->load()[$key] ?? null;
    }

    public function isApproved(string $key): bool
    {
        return ($this->row($key)['approval_status'] ?? 'pending_approval') === 'approved';
    }

    /**
     * @return array<string,list<array>>
     */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->load() as $row) {
            $out[$row['setting_group']][] = $row;
        }
        ksort($out);

        return $out;
    }

    public function set(string $key, mixed $value, ?int $userId = null, ?string $approvalStatus = null): void
    {
        $row = $this->row($key);
        if ($row === null) {
            throw new \InvalidArgumentException("Unknown setting {$key}");
        }

        $stored = match ($row['value_type']) {
            'bool'  => $value ? '1' : '0',
            'json'  => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };

        if ($row['value_type'] === 'json' && json_decode($stored) === null && $stored !== 'null') {
            throw new \InvalidArgumentException("Setting {$key} must be valid JSON.");
        }

        $data = ['setting_value' => $stored, 'updated_by' => $userId];
        if ($approvalStatus !== null) {
            $data['approval_status'] = $approvalStatus;
        }
        $this->model->update($row['id'], $data);

        service('audit')->log('settings.updated', [
            'entity_type' => 'platform_setting',
            'entity_id'   => (int) $row['id'],
            'old_values'  => ['value' => $row['setting_value'], 'approval_status' => $row['approval_status']],
            'new_values'  => $data,
            'description' => "Setting {$key} updated",
        ]);
        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int'     => (int) $value,
            'decimal' => (float) $value,
            'bool'    => $value === '1' || $value === 'true',
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }
}
