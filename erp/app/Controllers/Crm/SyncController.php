<?php

namespace App\Controllers\Crm;

use App\Libraries\SessionLock;
use App\Services\Sync\SheetsRowBuilder;
use Throwable;

/** Google Sheets sync dashboard and settings (one-way MySQL → Sheets). */
class SyncController extends CrmBase
{
    public function index()
    {
        $svc    = service('sheetsSync');
        $status = $svc->status();
        $client = null;
        $identity = null;
        if ($status['enabled']) {
            $client   = $svc->client();
            $identity = $client?->identity();
        }

        return $this->render('crm/sync', [
            'pageTitle' => 'Google Sheets sync', 'breadcrumbs' => [['CRM'], ['Google Sheets sync']],
            'status' => $status, 'settings' => $svc->settings(), 'identity' => $identity, 'clientError' => $client === null ? $svc->clientError() : null,
            'credPath' => $svc->credentialsPath(), 'canManage' => $this->can('crm.sheets.manage'),
            'columns' => SheetsRowBuilder::COLUMNS,
            'jobs' => $this->db->table('google_sync_jobs')->whereIn('status', ['failed', 'dead', 'pending', 'processing'])->orderBy("FIELD(status,'dead','failed','processing','pending')", '', false)->orderBy('updated_at', 'DESC')->limit(50)->get()->getResultArray(),
            'runs' => $this->db->table('google_sync_runs')->orderBy('id', 'DESC')->limit(15)->get()->getResultArray(),
        ]);
    }

    public function save()
    {
        $in  = $this->request->getPost();
        $sid = trim((string) ($in['spreadsheet_id'] ?? ''));
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $sid, $m)) {
            $sid = $m[1]; // pasted the full URL
        }
        if ($sid !== '' && ! preg_match('/^[A-Za-z0-9_-]{20,120}$/', $sid)) {
            return redirect()->back()->withInput()->with('error', 'That does not look like a Google spreadsheet ID (the long code in the sheet URL).');
        }
        $path = trim((string) ($in['credentials_path'] ?? ''));
        if ($path !== '') {
            if (str_contains($path, '..') || str_starts_with(ltrim($path, '/'), 'public')) {
                return redirect()->back()->withInput()->with('error', 'Keep the key file outside the public folder, e.g. writable/credentials/sheets.service-account.json.');
            }
            $full = str_starts_with($path, '/') ? $path : ROOTPATH . $path;
            if (! is_file($full) || ! is_readable($full)) {
                return redirect()->back()->withInput()->with('error', 'The key file was not found on the server at ' . $path . '. Upload it by SFTP first (see docs/05-GOOGLE-SHEETS.md).');
            }
        }
        $names = [];
        $map   = [];
        foreach (array_keys(SheetsRowBuilder::COLUMNS) as $ws) {
            $names[$ws] = mb_substr(trim((string) ($in['ws'][$ws] ?? '')), 0, 60) ?: ucwords(str_replace('_', ' ', $ws));
            $map[$ws]   = array_values(array_intersect(array_keys(SheetsRowBuilder::COLUMNS[$ws]), (array) ($in['cols'][$ws] ?? [])));
        }
        $data = [
            'enabled' => ! empty($in['enabled']) ? 1 : 0, 'spreadsheet_id' => $sid ?: null, 'credentials_path' => $path ?: null,
            'worksheets' => json_encode($names), 'field_map' => json_encode($map), 'frequency_minutes' => max(5, min(1440, (int) ($in['frequency_minutes'] ?? 5))),
            'direction' => 'db_to_sheets', 'updated_by' => $this->userId,
        ];
        $before = $this->db->table('google_sync_settings')->where('id', 1)->get()->getRowArray();
        $this->db->table('google_sync_settings')->where('id', 1)->update($data);
        service('audit')->log('sheets.settings_updated', 'integration', 1, array_intersect_key((array) $before, $data), $data, 'Google Sheets sync settings updated');

        return redirect()->to(site_url('crm/sync'))->with('success', 'Settings saved.' . (! $before['enabled'] && $data['enabled'] ? ' Use "Queue all records" to send existing data.' : ''));
    }

    public function test()
    {
        SessionLock::release(); // several Google calls; must not block the user's other pages
        $svc = service('sheetsSync');
        $client = $svc->client();
        if ($client === null) {
            return redirect()->back()->with('error', 'Not connected: ' . $svc->clientError());
        }
        $s = $svc->settings();
        try {
            foreach (array_keys(SheetsRowBuilder::COLUMNS) as $ws) {
                $cols = SheetsRowBuilder::columns($ws, $s['field_map'][$ws] ?? null);
                $client->ensureSheet((string) $s['worksheets'][$ws], SheetsRowBuilder::headers($ws, $cols));
            }
        } catch (Throwable $e) {
            service('audit')->log('sheets.test_failed', 'integration', 1, null, null, 'Sheets connection test failed');

            return redirect()->back()->with('error', 'Google returned an error: ' . mb_substr(strip_tags($e->getMessage()), 0, 300) . ' — check the spreadsheet is shared with ' . $client->identity() . ' as Editor.');
        }
        service('audit')->log('sheets.test_ok', 'integration', 1, null, null, 'Sheets connection test passed');

        return redirect()->back()->with('success', 'Connected as ' . $client->identity() . '. All worksheet tabs and header rows are in place.');
    }

    public function run()
    {
        $r = $this->processWithinBudget();

        return redirect()->back()->with($r['failed'] > 0 || str_starts_with($r['message'], 'Not connected') ? 'error' : 'success', 'Sync: ' . $r['message']);
    }

    public function retry()
    {
        $n = service('sheetsSync')->retryFailed();
        $r = $this->processWithinBudget();

        return redirect()->back()->with($r['failed'] > 0 ? 'error' : 'success', $n . ' failed job(s) re-queued. ' . $r['message']);
    }

    /**
     * "Sync now" works for at most app.webWorkSeconds (default 20) and
     * without holding the session lock, so neither this page nor the user's
     * other tabs run into a gateway timeout; cron continues with the rest.
     *
     * @return array{processed: int, succeeded: int, failed: int, remaining: int, message: string}
     */
    private function processWithinBudget(): array
    {
        SessionLock::release();
        try {
            return service('sheetsSync')->process(300, 'manual', (float) max(5, (int) env('app.webWorkSeconds', 20)));
        } finally {
            SessionLock::reopen();
        }
    }

    public function queueAll()
    {
        $n = service('sheetsSync')->queueAll();
        service('audit')->log('sheets.queue_all', 'integration', 1, null, ['jobs' => $n], 'All CRM records queued for Sheets sync');

        return redirect()->back()->with('success', $n . ' records queued. They sync on the next run (or press Sync now).');
    }
}
