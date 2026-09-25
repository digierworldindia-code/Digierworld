<?php

namespace App\Controllers\Admin;

use App\Libraries\Audit;
use App\Services\ReportService;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Reports and their exports.
 *
 * A report is a definition in ReportService — the screen, the CSV and the
 * Excel file all come from the same one, so an export always matches what was
 * on screen. Exporting is a separate permission and is recorded in the audit
 * trail with the filters used.
 */
class Reports extends AdminController
{
    private const PAGE = 100;

    public function index(): string
    {
        return $this->render('admin/reports/index', 'Reports', 'admin/reports', ['catalogue' => ReportService::catalogue()]);
    }

    public function show(string $key): string
    {
        $definition = ReportService::definition($key);
        $filters    = $this->filters();
        $page       = max(1, (int) $this->request->getGet('page'));
        $result     = ReportService::instance()->run($key, $filters, self::PAGE, ($page - 1) * self::PAGE);

        $db = db_connect();

        return $this->render('admin/reports/show', $definition['title'], 'admin/reports', [
            'key'        => $key,
            'definition' => $definition,
            'result'     => $result,
            'filters'    => $filters,
            'page'       => ['total' => $result['total'], 'page' => $page, 'pages' => max(1, (int) ceil($result['total'] / self::PAGE)), 'per' => self::PAGE],
            'dealers'    => in_array('dealer', $definition['filters'], true)
                ? $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray() : [],
            'products'   => in_array('product', $definition['filters'], true)
                ? $db->table('products')->select('id, name')->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray() : [],
            'statuses'   => $this->statusesFor($key),
        ]);
    }

    public function export(string $key, string $format): ResponseInterface
    {
        $definition = ReportService::definition($key);
        $filters    = $this->filters();
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $this->notFound();
        }

        $name = 'polyfix-' . $key . '-' . local_time(utc_now(), 'Y-m-d') . '.' . $format;
        $path = tempnam(WRITEPATH . 'cache', 'export');

        $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_values($definition['columns'])));

        $rows = 0;
        foreach (ReportService::instance()->each($key, $filters) as $row) {
            $writer->addRow(Row::fromValues(array_map(
                static fn ($value) => $value === null ? '' : (is_numeric($value) && ! is_string($value) ? $value : (string) $value),
                array_values($row),
            )));
            $rows++;
        }
        $writer->close();

        Audit::instance()->record('REPORT_EXPORTED', 'report', $key, null, [
            'format' => $format, 'rows' => $rows, 'filters' => array_filter($filters, static fn ($v) => $v !== null),
        ]);

        return $this->response->download($name, file_get_contents($path), true)
            ->setContentType($format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** @return array<string, ?string> */
    private function filters(): array
    {
        $filters = [];
        foreach (ReportService::FILTERS as $name) {
            $filters[$name] = $this->filter($name, 40);
        }

        return $filters;
    }

    /** @return list<string> */
    private function statusesFor(string $key): array
    {
        return match ($key) {
            'inventory'  => ['MANUFACTURED', 'IN_DISPATCH', 'DISPATCHED', 'DEALER_RECEIVED', 'SOLD', 'CLAIM_OPEN', 'REPLACED', 'RETURNED', 'SCRAPPED'],
            'dispatches' => ['DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'],
            'claims'     => ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'REPLACED', 'CLOSED', 'WITHDRAWN'],
            default      => [],
        };
    }
}
