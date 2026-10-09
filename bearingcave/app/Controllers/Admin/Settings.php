<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

/**
 * Platform settings = configurable business policy, with per-setting
 * "approved" / "pending approval" status tracking client decisions.
 */
class Settings extends AdminController
{
    public function index(): string
    {
        $groups = service('settings_store')->grouped();
        unset($groups['seo']);

        return $this->page('settings', ['title' => 'Platform settings', 'groups' => $groups, 'action' => 'admin/settings']);
    }

    public function seo(): string
    {
        return $this->page('settings', ['title' => 'SEO settings', 'groups' => ['seo' => service('settings_store')->grouped()['seo'] ?? []], 'action' => 'admin/seo']);
    }

    public function update()
    {
        return $this->save(false);
    }

    public function updateSeo()
    {
        return $this->save(true);
    }

    private function save(bool $seoOnly)
    {
        $store    = service('settings_store');
        $values   = (array) $this->request->getPost('s');
        $approval = (array) $this->request->getPost('approval');
        $errors   = [];
        $changed  = 0;
        foreach ($values as $key => $value) {
            $row = $store->row((string) $key);
            if (! $row || ($seoOnly !== ($row['setting_group'] === 'seo'))) {
                continue;
            }
            $value = is_array($value) ? '' : trim((string) $value);
            $err   = match ($row['value_type']) {
                'int'     => $value !== '' && ! preg_match('/^-?\d+$/', $value) ? 'must be a whole number' : null,
                'decimal' => $value !== '' && ! is_numeric($value) ? 'must be a number' : null,
                'json'    => json_decode($value) === null && $value !== 'null' ? 'must be valid JSON' : null,
                default   => null,
            };
            if ($err) {
                $errors[] = $row['label'] . ' ' . $err;

                continue;
            }
            $newApproval = isset($approval[$key]) && in_array($approval[$key], ['approved', 'pending_approval'], true) ? $approval[$key] : $row['approval_status'];
            $stored      = $row['value_type'] === 'bool' ? ($value === '1' ? '1' : '0') : $value;
            if ($stored !== (string) $row['setting_value'] || $newApproval !== $row['approval_status']) {
                $store->set((string) $key, $row['value_type'] === 'bool' ? $value === '1' : $value, $this->userId(), $newApproval);
                $changed++;
            }
        }
        if ($errors) {
            return redirect()->back()->withInput()->with('error', implode('; ', $errors));
        }

        return redirect()->back()->with('success', $changed ? "{$changed} setting(s) saved." : 'No changes.');
    }
}
