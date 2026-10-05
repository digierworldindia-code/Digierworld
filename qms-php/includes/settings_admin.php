<?php
/**
 * Validation of the settings edited in Administration → Settings.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/validate.php';
require_once QMS_ROOT . '/includes/uploads.php';

const SETTINGS_GROUPS = [
    'company'    => 'Company',
    'documents'  => 'Document numbering',
    'regional'   => 'Regional',
    'workflow'   => 'Approval workflow',
    'security'   => 'Security',
    'gauge'      => 'Gauges',
    'inspection' => 'Inspection',
    'google'     => 'Google Sheets',
];

/** Select options for settings with a fixed list of values. */
const SETTINGS_OPTIONS = [
    'documents.sequence_reset'  => ['MONTHLY' => 'Every month', 'YEARLY' => 'Every year', 'NEVER' => 'Never'],
    'regional.date_format'      => ['d-m-Y' => '31-12-2026', 'd/m/Y' => '31/12/2026', 'd.m.Y' => '31.12.2026', 'Y-m-d' => '2026-12-31', 'm/d/Y' => '12/31/2026'],
    'google.observation_detail' => ['ALL' => 'All readings', 'OOS_ONLY' => 'Out-of-specification readings only', 'NONE' => 'No reading detail (report rows only)'],
];

const SETTINGS_RULES = [
    'company.name'                     => 'required|max_length[150]',
    'company.address'                  => 'permit_empty|max_length[500]',
    'documents.number_format'          => 'required|max_length[60]',
    'documents.sequence_padding'       => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[9]',
    'documents.sequence_reset'         => 'required|in_list[MONTHLY,YEARLY,NEVER]',
    'regional.timezone'                => 'required|max_length[64]',
    'regional.date_format'             => 'required|in_list[d-m-Y,d/m/Y,d.m.Y,Y-m-d,m/d/Y]',
    'google.sync_enabled'              => 'permit_empty|in_list[0,1]',
    'google.spreadsheet_id'            => 'permit_empty|regex_match[/^[A-Za-z0-9_-]{20,100}$/]',
    'google.detail_spreadsheet_id'     => 'permit_empty|regex_match[/^[A-Za-z0-9_-]{20,100}$/]',
    'google.reports_sheet'             => 'required|max_length[90]|regex_match[/^[A-Za-z0-9 _-]+$/]',
    'google.observation_detail'        => 'required|in_list[ALL,OOS_ONLY,NONE]',
    'google.observations_sheet_prefix' => 'required|max_length[40]|regex_match[/^[A-Za-z0-9 _-]+$/]',
    'google.capacity_alert_percent'    => 'required|integer|greater_than_equal_to[50]|less_than_equal_to[95]',
    'google.max_attempts'              => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[50]',
    'workflow.reauth_on_sign'          => 'permit_empty|in_list[0,1]',
    'workflow.distinct_signers'        => 'permit_empty|in_list[0,1]',
    'security.session_idle_minutes'    => 'required|integer|greater_than_equal_to[5]|less_than_equal_to[480]',
    'security.session_absolute_hours'  => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[24]',
    'security.max_failed_logins'       => 'required|integer|greater_than_equal_to[3]|less_than_equal_to[20]',
    'security.lockout_minutes'         => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[1440]',
    'security.password_min_length'     => 'required|integer|greater_than_equal_to[8]|less_than_equal_to[64]',
    'security.password_history'        => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[24]',
    'security.password_expiry_days'    => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[365]',
    'gauge.block_expired_on_submit'    => 'permit_empty|in_list[0,1]',
    'gauge.due_warning_days'           => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[90]',
    'inspection.max_backdate_days'     => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[7]',
    'inspection.autosave_seconds'      => 'required|integer|greater_than_equal_to[5]|less_than_equal_to[300]',
];

/** Form field name of a setting key (dots are not allowed in PHP form names). */
function settings_field(string $key): string
{
    return str_replace('.', '__', $key);
}

/**
 * Validates the posted values of one group (unticked checkboxes = off).
 *
 * @return array<string, string> key => value
 */
function settings_validate(string $group, array $post): array
{
    $data  = [];
    $rules = [];
    foreach (settings_group($group) as $row) {
        $key = $row['setting_key'];
        if (! isset(SETTINGS_RULES[$key])) {
            continue; // files (logo) have their own form
        }
        $raw          = $post[settings_field($key)] ?? null;
        $data[$key]   = str_contains(SETTINGS_RULES[$key], 'in_list[0,1]') ? ($raw === '1' ? '1' : '0') : (is_string($raw) ? trim($raw) : '');
        $rules[$key]  = ['label' => (string) ($row['label'] ?? $key), 'rules' => SETTINGS_RULES[$key]];
    }
    validate_or_fail($data, $rules);

    if (isset($data['regional.timezone']) && ! in_array($data['regional.timezone'], DateTimeZone::listIdentifiers(), true)) {
        fail_field('regional.timezone', 'Unknown time zone (example: Asia/Kolkata).');
    }
    if (isset($data['documents.number_format'])) {
        $format = $data['documents.number_format'];
        $reset  = $data['documents.sequence_reset'] ?? setting_str('documents.sequence_reset', 'MONTHLY');
        if (! str_contains($format, '{PREFIX}') || ! str_contains($format, '{SEQ}')) {
            fail_field('documents.number_format', 'The format must contain {PREFIX} and {SEQ}.');
        }
        if (in_array($reset, ['MONTHLY', 'YEARLY'], true) && ! str_contains($format, '{YYYY}')) {
            fail_field('documents.number_format', 'A yearly or monthly reset needs {YYYY} in the format, otherwise numbers would repeat.');
        }
        if ($reset === 'MONTHLY' && ! str_contains($format, '{MM}')) {
            fail_field('documents.number_format', 'A monthly reset needs {MM} in the format, otherwise numbers would repeat.');
        }
        if (preg_match('/[^A-Za-z0-9{}\/_.-]/', $format)) {
            fail_field('documents.number_format', 'Use only letters, digits, the tokens and - _ . / characters.');
        }
    }

    return $data;
}

/** Report type: number prefix and which approval stages it needs. */
function settings_save_report_type(int $id, array $post, array $actor): void
{
    $before = db_row('SELECT * FROM report_types WHERE id = ?', [$id]) ?? fail_field('report_type', 'Unknown report type.');
    $prefix = strtoupper(trim(is_string($post['doc_prefix'] ?? null) ? $post['doc_prefix'] : ''));
    if (! preg_match('/^[A-Z0-9]{2,10}$/', $prefix)) {
        fail_field('doc_prefix', 'Prefix: 2-10 capital letters or digits.');
    }
    $after = ['doc_prefix' => $prefix];
    foreach (['requires_production_verification', 'requires_quality_verification', 'requires_qa_approval'] as $column) {
        $after[$column] = ($post[$column] ?? '') === '1' ? 1 : 0;
    }
    if ($after['requires_production_verification'] + $after['requires_quality_verification'] + $after['requires_qa_approval'] === 0) {
        fail_field('stages', 'Keep at least one approval stage switched on.');
    }
    db_transaction(static function () use ($id, $before, $after, $actor): void {
        db_update('report_types', $after + ['updated_at' => now_str(), 'updated_by' => $actor['id']], 'id = ?', [$id]);
        audit_log('UPDATE', 'report_type', $id, array_intersect_key($before, $after), $after, (string) $before['code']);
    });
}

/** New company logo (re-encoded PNG). */
function settings_save_logo(?array $file, array $actor): void
{
    $old  = setting_str('company.logo');
    $name = upload_store_logo(upload_accept($file, 'logo', UPLOAD_LOGO_MAX_KB, ['image/png', 'image/jpeg']));
    try {
        db_transaction(static function () use ($name, $old, $actor): void {
            db_update('system_settings', ['setting_value' => $name, 'updated_at' => now_str(), 'updated_by' => $actor['id']], 'setting_key = ?', ['company.logo']);
            audit_log('UPDATE', 'settings', null, ['company.logo' => $old], ['company.logo' => $name], 'company');
        });
    } catch (Throwable $e) {
        upload_delete('logo', $name);

        throw $e;
    }
    settings_refresh();
    if ($old !== '') {
        upload_delete('logo', $old);
    }
}
