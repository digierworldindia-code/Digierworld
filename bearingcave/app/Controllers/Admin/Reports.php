<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Reports extends AdminController
{
    public function index(): string
    {
        return $this->page('reports', ['title' => 'Reports & analytics', 'reports' => ReportService::REPORTS]);
    }

    private function range(): array
    {
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-90 days')));
        $to   = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        if (! \DateTime::createFromFormat('Y-m-d', $from) || ! \DateTime::createFromFormat('Y-m-d', $to) || $from > $to) {
            return [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')];
        }

        return [$from, $to];
    }

    public function show(string $key): string
    {
        if (! isset(ReportService::REPORTS[$key])) {
            $this->notFound();
        }
        [$from, $to] = $this->range();

        return $this->page('report', ['title' => ReportService::REPORTS[$key][0], 'key' => $key, 'desc' => ReportService::REPORTS[$key][1], 'from' => $from, 'to' => $to,
            'data' => service('reports')->run($key, $from, $to), 'canExport' => $this->can('reports.export')]);
    }

    public function export(string $key)
    {
        if (! isset(ReportService::REPORTS[$key])) {
            $this->notFound();
        }
        [$from, $to] = $this->range();
        $data = service('reports')->run($key, $from, $to);
        service('audit')->log('report.exported', ['description' => "{$key} {$from}..{$to} (" . count($data['rows']) . ' rows)', 'severity' => 'warning']);
        $name = "bearingcave-{$key}-{$from}-{$to}";

        if ($this->request->getGet('format') === 'xlsx') {
            $ss = new Spreadsheet();
            $sh = $ss->getActiveSheet();
            $sh->fromArray($data['columns'], null, 'A1');
            $sh->fromArray(array_map('array_values', $data['rows']), null, 'A2');
            $sh->getStyle('1:1')->getFont()->setBold(true);
            $tmp = tempnam(sys_get_temp_dir(), 'rpt');
            (new Xlsx($ss))->save($tmp);
            $bin = (string) file_get_contents($tmp);
            @unlink($tmp);

            return $this->response->download($name . '.xlsx', $bin);
        }
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $data['columns']);
        foreach ($data['rows'] as $r) {
            // Neutralise spreadsheet formula injection in exported text.
            fputcsv($fh, array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($r)));
        }
        rewind($fh);

        return $this->response->download($name . '.csv', "\xEF\xBB\xBF" . stream_get_contents($fh));
    }
}
