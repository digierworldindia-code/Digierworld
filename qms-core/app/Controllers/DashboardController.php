<?php

namespace App\Controllers;

/**
 * Management dashboard: KPI cards, pass / fail trend, workload and alerts.
 */
class DashboardController extends BaseController
{
    public function index(): string
    {
        $today   = service('productionCalendar')->current()['date'];
        $service = service('dashboard');
        $filters = $service->filters((array) $this->request->getGet(['period', 'date', 'month', 'type', 'part', 'machine', 'shift', 'operator', 'status']), $today);
        $masters = service('masterData');

        return $this->render('dashboard/index', [
            'title'       => 'Dashboard',
            'filters'     => $filters,
            'today'       => $today,
            'cards'       => $service->cards($filters),
            'syncPending' => service('sheetQueue')->pendingReportCount(),
            'syncOn'      => service('sheetQueue')->isConfigured(),
            'trend'       => $service->trend($filters),
            'byType'      => $service->byType($filters),
            'topOos'      => $service->topOos($filters),
            'machines'    => $service->machines($filters),
            'overdue'     => $service->overdue(),
            'gauges'      => $service->gaugeAlerts($today),
            'lookups'     => [
                'type'     => $masters->lookup('report-types'),
                'part'     => $masters->lookup('parts'),
                'machine'  => $masters->lookup('machines'),
                'shift'    => $masters->lookup('shifts'),
                'operator' => $masters->lookup('employees'),
            ],
        ]);
    }
}
