<?php

namespace App\Controllers\Reports;

use App\Controllers\BaseController;
use App\Exceptions\NotFoundException;
use App\Libraries\Paging;
use App\Services\Reports\CsvExporter;
use App\Services\Reports\ReportQueryService;

/**
 * Management reports with CSV export (export is audited).
 */
class ReportController extends BaseController
{
    private const INPUT = ['date', 'month', 'from', 'to', 'type', 'part', 'machine', 'shift', 'operator', 'status', 'days'];

    public function index(): string
    {
        return $this->render('reports/index', ['title' => 'Reports', 'catalogue' => ReportQueryService::catalogue()]);
    }

    public function show(string $slug): string
    {
        $def     = ReportQueryService::catalogue()[$slug] ?? throw new NotFoundException('Unknown report.');
        $service = service('reportQueries');
        $filters = $service->filters($slug, (array) $this->request->getGet(self::INPUT), service('productionCalendar')->current()['date']);
        $paging  = in_array($slug, ['daily', 'rejection', 'oos'], true) ? Paging::fromRequest($this->request, 50) : null;
        $masters = service('masterData');

        return $this->render('reports/show', [
            'title'   => $def['title'],
            'def'     => $def,
            'slug'    => $slug,
            'filters' => $filters,
            'result'  => $service->run($filters, $paging),
            'paging'  => $paging,
            'lookups' => [
                'type'    => $masters->lookup('report-types'),
                'part'    => $masters->lookup('parts'),
                'machine' => $masters->lookup('machines'),
                'shift'   => $masters->lookup('shifts'),
            ],
        ]);
    }

    public function export(string $slug)
    {
        $def     = ReportQueryService::catalogue()[$slug] ?? throw new NotFoundException('Unknown report.');
        $service = service('reportQueries');
        $filters = $service->filters($slug, (array) $this->request->getGet(self::INPUT), service('productionCalendar')->current()['date']);
        $result  = $service->run($filters, null);
        $csv     = CsvExporter::build($result['columns'], $result['rows'], $result['totals']);
        $name    = 'qms-' . $slug . '-' . ($filters['from'] ?? $filters['today'] ?? 'now') . (isset($filters['to']) && $filters['to'] !== ($filters['from'] ?? '') ? '-' . $filters['to'] : '') . '.csv';

        service('audit')->log('EXPORT', 'report', null, null, ['report' => $slug, 'filters' => array_diff_key($filters, ['label' => 0]), 'rows' => count($result['rows'])], $def['title']);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }
}
