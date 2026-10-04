<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Paging;
use App\Services\Sync\SheetSyncWorker;

/**
 * Google Sheets Sync Monitor: queue state, failures, manual retry and backfill.
 */
class SyncController extends BaseController
{
    public function index(): string
    {
        $filters = array_map('strval', array_filter((array) $this->request->getGet(['status', 'job_type', 'q']), static fn ($v): bool => $v !== null));
        $paging  = Paging::fromRequest($this->request, 50);
        $queue   = service('sheetQueue');
        $settings = service('settings');

        return $this->render('admin/sync/index', [
            'title'       => 'Google Sheets sync',
            'summary'     => $queue->summary(),
            'jobs'        => $queue->list($filters, $paging),
            'filters'     => $filters,
            'paging'      => $paging,
            'heartbeat'   => SheetSyncWorker::readHeartbeat(),
            'credentials' => service('sheetsClient')->describeCredentials(),
            'enabled'     => $settings->bool('google.sync_enabled'),
            'spreadsheet' => $settings->string('google.spreadsheet_id'),
            'detail'      => $settings->string('google.detail_spreadsheet_id'),
            'configured'  => $queue->isConfigured(),
            'statuses'    => \App\Services\Sync\SheetSyncQueue::STATUSES,
        ]);
    }

    public function retry(int $id)
    {
        return $this->perform(fn () => service('sheetQueue')->retry($id, service('audit')), site_url('admin/sync'), 'Job queued for an immediate retry.');
    }

    public function retryFailed()
    {
        return $this->perform(fn (): int => service('sheetQueue')->retryFailed(service('audit')), site_url('admin/sync'), 'All failed jobs were queued again.');
    }

    public function backfill()
    {
        $from = (string) $this->input('from');
        $to   = (string) $this->input('to');

        return $this->perform(
            fn (): int => service('sheetQueue')->backfill($from, $to, service('audit')),
            site_url('admin/sync'),
            "Reports from {$from} to {$to} queued for synchronisation.",
        );
    }
}
