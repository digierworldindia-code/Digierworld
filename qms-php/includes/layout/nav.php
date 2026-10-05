<?php
/**
 * Side menu: only the pages the user may open.
 */
defined('QMS') || exit;

/**
 * @return list<array{title: string, items: list<array{label: string, url: string, icon: string, active: bool, badge: int}>}>
 */
function nav_sections(): array
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $folder = basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $type   = get('type');
    $groups = [
        'inspections.php' => ['inspection.php', 'inspection_edit.php', 'inspection_print.php'],
        'templates.php'   => ['template.php', 'template_new.php', 'template_preview.php'],
        'gauges.php'      => ['gauge.php', 'gauge_edit.php'],
        'admin/users.php' => ['admin/user_edit.php'],
        'admin/roles.php' => ['admin/role_edit.php'],
        'reports.php'     => ['report.php'],
        'masters.php'     => ['master_edit.php'],
    ];
    $current = ($folder === 'admin' ? 'admin/' : '') . $script;

    $items = [
        ['Inspection', 'Home', 'home.php', 'bi-house-door', ['inspection.create']],
        ['Inspection', 'Dashboard', 'dashboard.php', 'bi-speedometer2', ['dashboard.view']],
        ['Inspection', 'New inspection', 'inspection_new.php', 'bi-plus-square', ['inspection.create']],
        ['Inspection', 'Inspections', 'inspections.php', 'bi-clipboard-check', ['inspection.view_own', 'inspection.view_all']],
        ['Inspection', 'Approvals', 'approvals.php', 'bi-pen', ['inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa']],
        ['Inspection', 'Reports', 'reports.php', 'bi-bar-chart-line', ['report.view']],
        ['Quality setup', 'Templates', 'templates.php', 'bi-ui-checks-grid', ['template.view']],
        ['Quality setup', 'Gauges', 'gauges.php', 'bi-rulers', ['gauge.view']],
        ['Quality setup', 'Parts', 'masters.php?type=parts', 'bi-box-seam', ['master.view']],
        ['Quality setup', 'Machines', 'masters.php?type=machines', 'bi-gear-wide-connected', ['master.view']],
        ['Quality setup', 'Parameter library', 'masters.php?type=parameters', 'bi-list-check', ['master.view']],
        ['Quality setup', 'Other masters', 'masters.php', 'bi-collection', ['master.view']],
        ['Administration', 'Users', 'admin/users.php', 'bi-people', ['user.manage']],
        ['Administration', 'Roles & permissions', 'admin/roles.php', 'bi-shield-lock', ['role.manage']],
        ['Administration', 'Settings', 'admin/settings.php', 'bi-sliders', ['setting.manage']],
        ['Administration', 'Audit trail', 'admin/audit.php', 'bi-journal-text', ['audit.view']],
        ['Administration', 'Login history', 'admin/login_history.php', 'bi-box-arrow-in-right', ['loginlog.view']],
        ['Administration', 'Google Sheets sync', 'admin/sync.php', 'bi-cloud-arrow-up', ['sync.view']],
    ];

    $sections = [];
    foreach ($items as [$section, $label, $page, $icon, $permissions]) {
        if (! can(...$permissions)) {
            continue;
        }
        [$file, $query] = array_pad(explode('?', $page, 2), 2, '');
        $pageType = $query !== '' ? (string) substr($query, 5) : '';
        if ($file === 'masters.php') {
            $isMasterPage = in_array($current, ['masters.php', 'master_edit.php'], true);
            $active = $isMasterPage && ($pageType !== '' ? $type === $pageType : ! in_array($type, ['parts', 'machines', 'parameters'], true));
        } else {
            $active = $current === $file || in_array($current, $groups[$file] ?? [], true);
        }
        $sections[$section][] = [
            'label'  => $label,
            'url'    => url($page),
            'icon'   => $icon,
            'active' => $active,
            'badge'  => $page === 'approvals.php' ? approval_count() : 0,
        ];
    }

    $out = [];
    foreach ($sections as $title => $list) {
        $out[] = ['title' => $title, 'items' => $list];
    }

    return $out;
}

/** Reports waiting at a stage the user may sign (excluding their own submissions). */
function approval_count(): int
{
    static $count = null;
    if ($count !== null) {
        return $count;
    }
    $user = current_user();
    if ($user === null) {
        return $count = 0;
    }
    $statuses = [];
    foreach (WORKFLOW_STAGES as $stage) {
        if (user_can($user, $stage['permission'])) {
            $statuses[] = $stage['pending'];
        }
    }
    if ($statuses === []) {
        return $count = 0;
    }

    return $count = (int) db_value('SELECT COUNT(*) FROM inspection_reports WHERE status IN (' . db_in($statuses) . ') AND submitted_by <> ?',
        [...$statuses, $user['id']]);
}

/** "03-10-2026 · Shift B" for the top bar. */
function plant_label(): string
{
    try {
        $current = production_current();

        return plant_date($current['date']) . ($current['shift'] !== null ? ' · Shift ' . $current['shift']['code'] : '');
    } catch (Throwable) {
        return '';
    }
}
