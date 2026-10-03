<?php

namespace App\Libraries;

use App\Enums\ReportStatus;
use App\Enums\WorkflowStage;
use App\Services\Access\AuthorizationService;
use App\Services\Auth\AuthService;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Builds the side navigation for the current user from their permissions.
 */
class Navigation
{
    private ?int $approvalCount = null;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuthService $auth,
        private readonly AuthorizationService $authz,
    ) {
    }

    /**
     * @return list<array{title: string, items: list<array{label: string, url: string, icon: string, active: bool, badge: int}>}>
     */
    public function sections(string $currentPath): array
    {
        $path     = '/' . trim($currentPath, '/');
        $sections = [];
        $add      = function (string $section, string $label, string $uri, string $icon, string|array $permissions, int $badge = 0) use (&$sections, $path): void {
            if (! $this->authz->can(...(array) $permissions)) {
                return;
            }
            $url    = '/' . trim($uri, '/');
            $active = $url === '/' ? $path === '/' : ($path === $url || str_starts_with($path, $url . '/'));
            $sections[$section][] = ['label' => $label, 'url' => site_url($uri), 'icon' => $icon, 'active' => $active, 'badge' => $badge];
        };

        $add('Inspection', 'Home', 'inspections/home', 'bi-house-door', 'inspection.create');
        $add('Inspection', 'Dashboard', 'dashboard', 'bi-speedometer2', 'dashboard.view');
        $add('Inspection', 'New inspection', 'inspections/new', 'bi-plus-square', 'inspection.create');
        $add('Inspection', 'Inspections', 'inspections', 'bi-clipboard-check', ['inspection.view_own', 'inspection.view_all']);
        $add('Inspection', 'Approvals', 'approvals', 'bi-pen', ['inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa'], $this->approvalCount());
        $add('Inspection', 'Reports', 'reports', 'bi-bar-chart-line', 'report.view');

        $add('Quality setup', 'Templates', 'templates', 'bi-ui-checks-grid', 'template.view');
        $add('Quality setup', 'Gauges', 'gauges', 'bi-rulers', 'gauge.view');
        $add('Quality setup', 'Parts', 'masters/parts', 'bi-box-seam', 'master.view');
        $add('Quality setup', 'Machines', 'masters/machines', 'bi-gear-wide-connected', 'master.view');
        $add('Quality setup', 'Parameter library', 'masters/parameters', 'bi-list-check', 'master.view');
        $add('Quality setup', 'Other masters', 'masters', 'bi-collection', 'master.view');

        $add('Administration', 'Users', 'admin/users', 'bi-people', 'user.manage');
        $add('Administration', 'Roles & permissions', 'admin/roles', 'bi-shield-lock', 'role.manage');
        $add('Administration', 'Settings', 'admin/settings', 'bi-sliders', 'setting.manage');
        $add('Administration', 'Audit trail', 'admin/audit', 'bi-journal-text', 'audit.view');
        $add('Administration', 'Login history', 'admin/login-logs', 'bi-box-arrow-in-right', 'loginlog.view');
        $add('Administration', 'Google Sheets sync', 'admin/sync', 'bi-cloud-arrow-up', 'sync.view');

        $out = [];
        foreach ($sections as $title => $items) {
            $out[] = ['title' => $title, 'items' => $items];
        }

        return $out;
    }

    /**
     * Reports waiting at a stage the user may sign (excluding their own submissions).
     */
    public function approvalCount(): int
    {
        if ($this->approvalCount !== null) {
            return $this->approvalCount;
        }

        $user = $this->auth->user();
        if ($user === null) {
            return $this->approvalCount = 0;
        }

        $statuses = [];
        foreach (WorkflowStage::cases() as $stage) {
            if ($this->authz->userCan($user, $stage->permission())) {
                $statuses[] = $stage->pendingStatus()->value;
            }
        }
        if ($statuses === []) {
            return $this->approvalCount = 0;
        }

        try {
            return $this->approvalCount = (int) $this->db->table('inspection_reports')
                ->whereIn('status', $statuses)
                ->where('submitted_by !=', $user['id'])
                ->countAllResults();
        } catch (Throwable) {
            return $this->approvalCount = 0;
        }
    }

    /** "03-10-2026 · Shift B" for the top bar. */
    public function plantLabel(): string
    {
        try {
            $current = service('productionCalendar')->current();

            return plant_date($current['date']) . ($current['shift'] !== null ? ' · Shift ' . $current['shift']['code'] : '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return list<string>
     */
    public static function pendingStatuses(): array
    {
        return ReportStatus::pendingValues();
    }
}
