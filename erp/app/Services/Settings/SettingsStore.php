<?php

namespace App\Services\Settings;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * App-level key/value settings and integration settings.
 * Integration secrets are encrypted with the app encryption key and are
 * never returned to views — only a "configured / not configured" flag.
 */
class SettingsStore
{
    /** @var array<string, mixed>|null */
    private ?array $app = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->app === null) {
            $this->app = [];
            foreach ($this->db->table('app_settings')->get()->getResultArray() as $row) {
                $this->app[$row['setting_key']] = $this->cast($row['setting_value'], $row['value_type']);
            }
        }

        return $this->app[$key] ?? $default;
    }

    public function set(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'json'  => json_encode($value, JSON_UNESCAPED_UNICODE),
            'bool'  => $value ? '1' : '0',
            default => $value === null ? null : (string) $value,
        };
        $userId = auth('session')->loggedIn() ? (int) auth('session')->id() : null;
        $this->db->query(
            'INSERT INTO app_settings (setting_key, setting_value, value_type, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_by = VALUES(updated_by)',
            [$key, $stored, $type, $userId]
        );
        $this->app = null;
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int'   => $value === null ? null : (int) $value,
            'bool'  => $value === '1',
            'json'  => $value === null ? null : json_decode($value, true),
            default => $value,
        };
    }

    public function integration(string $integration, string $key, mixed $default = null): mixed
    {
        $row = $this->db->table('integration_settings')
            ->where(['integration' => $integration, 'setting_key' => $key])->get()->getRowArray();
        if ($row === null || $row['setting_value'] === null) {
            return $default;
        }
        if ((int) $row['is_secret'] === 1) {
            try {
                return service('encrypter')->decrypt(base64_decode($row['setting_value'], true) ?: '');
            } catch (Throwable) {
                log_message('error', "Unable to decrypt integration secret {$integration}.{$key} (encryption key changed?)");

                return $default;
            }
        }

        return $row['setting_value'];
    }

    public function setIntegration(string $integration, string $key, ?string $value, bool $secret = false): void
    {
        $stored = $value;
        if ($secret && $value !== null && $value !== '') {
            $stored = base64_encode(service('encrypter')->encrypt($value));
        }
        $userId = auth('session')->loggedIn() ? (int) auth('session')->id() : null;
        $this->db->query(
            'INSERT INTO integration_settings (integration, setting_key, setting_value, is_secret, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)',
            [$integration, $key, $stored, $secret ? 1 : 0, $userId]
        );
    }

    public function hasIntegrationSecret(string $integration, string $key): bool
    {
        $row = $this->db->table('integration_settings')
            ->where(['integration' => $integration, 'setting_key' => $key])->get()->getRowArray();

        return $row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '';
    }

    /** Mark a Billing Setup Wizard step as completed. */
    public function markSetupStep(string $step): void
    {
        $row   = $this->db->table('company_settings')->select('setup_steps')->where('id', 1)->get()->getRowArray();
        $steps = json_decode((string) ($row['setup_steps'] ?? '{}'), true) ?: [];
        if (empty($steps[$step])) {
            $steps[$step] = true;
            $this->db->table('company_settings')->where('id', 1)->update(['setup_steps' => json_encode($steps)]);
        }
    }

    /** Encrypt an arbitrary sensitive value (bank numbers, CIF) for column storage. */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return base64_encode(service('encrypter')->encrypt($plain));
    }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        try {
            return service('encrypter')->decrypt(base64_decode($cipher, true) ?: '');
        } catch (Throwable) {
            return null;
        }
    }
}
