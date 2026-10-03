<?php

namespace App\Services\Settings;

use App\Exceptions\ValidationException;
use DateTimeZone;

/**
 * Validation rules for every editable system setting.
 */
class SettingsValidator
{
    /** Select options for settings that accept a fixed list. */
    public const OPTIONS = [
        'documents.sequence_reset'  => ['MONTHLY' => 'Every month', 'YEARLY' => 'Every year', 'NEVER' => 'Never'],
        'regional.date_format'      => ['d-m-Y' => '31-12-2026', 'd/m/Y' => '31/12/2026', 'd.m.Y' => '31.12.2026', 'Y-m-d' => '2026-12-31', 'm/d/Y' => '12/31/2026'],
        'google.observation_detail' => ['ALL' => 'All readings', 'OOS_ONLY' => 'Out-of-specification readings only', 'NONE' => 'No reading detail (report rows only)'],
    ];

    private const RULES = [
        'company.name'                      => 'required|max_length[150]',
        'company.address'                   => 'permit_empty|max_length[500]',
        'documents.number_format'           => 'required|max_length[60]',
        'documents.sequence_padding'        => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[9]',
        'documents.sequence_reset'          => 'required|in_list[MONTHLY,YEARLY,NEVER]',
        'regional.timezone'                 => 'required|max_length[64]',
        'regional.date_format'              => 'required|in_list[d-m-Y,d/m/Y,d.m.Y,Y-m-d,m/d/Y]',
        'google.sync_enabled'               => 'permit_empty|in_list[0,1]',
        'google.spreadsheet_id'             => 'permit_empty|regex_match[/^[A-Za-z0-9_-]{20,100}$/]',
        'google.detail_spreadsheet_id'      => 'permit_empty|regex_match[/^[A-Za-z0-9_-]{20,100}$/]',
        'google.reports_sheet'              => 'required|max_length[90]|regex_match[/^[A-Za-z0-9 _-]+$/]',
        'google.observation_detail'         => 'required|in_list[ALL,OOS_ONLY,NONE]',
        'google.observations_sheet_prefix'  => 'required|max_length[40]|regex_match[/^[A-Za-z0-9 _-]+$/]',
        'google.capacity_alert_percent'     => 'required|integer|greater_than_equal_to[50]|less_than_equal_to[95]',
        'google.max_attempts'               => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[50]',
        'workflow.reauth_on_sign'           => 'permit_empty|in_list[0,1]',
        'workflow.distinct_signers'         => 'permit_empty|in_list[0,1]',
        'security.session_idle_minutes'     => 'required|integer|greater_than_equal_to[5]|less_than_equal_to[480]',
        'security.session_absolute_hours'   => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[24]',
        'security.max_failed_logins'        => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[20]',
        'security.lockout_minutes'          => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[1440]',
        'security.password_min_length'      => 'required|integer|greater_than_equal_to[8]|less_than_equal_to[64]',
        'security.password_history'         => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[24]',
        'security.password_expiry_days'     => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[365]',
        'gauge.block_expired_on_submit'     => 'permit_empty|in_list[0,1]',
        'gauge.due_warning_days'            => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[90]',
        'inspection.max_backdate_days'      => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[7]',
        'inspection.autosave_seconds'       => 'required|integer|greater_than_equal_to[5]|less_than_equal_to[300]',
    ];

    /**
     * @param array<string, mixed> $values key => value (checkbox keys absent = off)
     * @param list<string>         $keys   the keys of the group being saved
     *
     * @return array<string, string> normalised values
     */
    public function validate(array $values, array $keys): array
    {
        $data  = [];
        $rules = [];
        foreach ($keys as $key) {
            if (! isset(self::RULES[$key])) {
                continue; // FILE settings (logo) have their own form
            }
            $field          = str_replace('.', '__', $key);
            $raw            = $values[$field] ?? null;
            $data[$field]   = str_contains(self::RULES[$key], 'in_list[0,1]') ? ($raw === '1' ? '1' : '0') : trim((string) $raw);
            $rules[$field]  = ['label' => $key, 'rules' => self::RULES[$key]];
        }

        $validation = service('validation', null, false);
        $validation->setRules($rules);
        if (! $validation->run($data)) {
            $errors = [];
            foreach ($validation->getErrors() as $field => $message) {
                $errors[str_replace('__', '.', $field)] = $message;
            }

            throw new ValidationException($errors);
        }

        $out = [];
        foreach ($data as $field => $value) {
            $out[str_replace('__', '.', $field)] = $value;
        }

        $this->crossChecks($out);

        return $out;
    }

    /**
     * @param array<string, string> $values
     */
    private function crossChecks(array $values): void
    {
        if (isset($values['regional.timezone']) && ! in_array($values['regional.timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::single('regional.timezone', 'Unknown time zone (example: Asia/Kolkata).');
        }

        if (isset($values['documents.number_format'])) {
            $format = $values['documents.number_format'];
            $reset  = $values['documents.sequence_reset'] ?? (string) service('settings')->get('documents.sequence_reset', 'MONTHLY');
            if (! str_contains($format, '{PREFIX}') || ! str_contains($format, '{SEQ}')) {
                throw ValidationException::single('documents.number_format', 'The format must contain {PREFIX} and {SEQ}.');
            }
            if (in_array($reset, ['MONTHLY', 'YEARLY'], true) && ! str_contains($format, '{YYYY}')) {
                throw ValidationException::single('documents.number_format', 'A yearly or monthly reset needs {YYYY} in the format, otherwise numbers would repeat.');
            }
            if ($reset === 'MONTHLY' && ! str_contains($format, '{MM}')) {
                throw ValidationException::single('documents.number_format', 'A monthly reset needs {MM} in the format, otherwise numbers would repeat.');
            }
            if (preg_match('/[^A-Za-z0-9{}\/_.-]/', $format)) {
                throw ValidationException::single('documents.number_format', 'Use only letters, digits, the tokens and - _ . / characters.');
            }
        }
    }
}
