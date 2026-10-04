<?php

namespace Tests\Database;

use App\Libraries\GoogleSheets\FakeSheetsClient;
use App\Config\Services;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * Transactional outbox + worker for Google Sheets, against an in-memory fake
 * (docs/architecture/08-google-sheets-integration.md).
 *
 * @internal
 */
final class SheetSyncTest extends DatabaseTestCase
{
    private const SHEET = 'SPREADSHEET-MAIN';

    private FakeSheetsClient $fake;

    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSettings([
            'workflow.reauth_on_sign' => '0', 'google.sync_enabled' => '1', 'google.spreadsheet_id' => self::SHEET,
            'google.observation_detail' => 'ALL', 'google.max_attempts' => '3',
        ]);
        $this->useClient(new FakeSheetsClient());
        $this->u = [
            'op' => $this->createUser('OPERATOR', 'E1001'),
            'pe' => $this->createUser('PRODUCTION_ENGINEER', 'E3001'),
            'qe' => $this->createUser('QUALITY_ENGINEER', 'E2001'),
            'qa' => $this->createUser('QA_ADMIN', 'E4001'),
        ];
    }

    private function useClient(FakeSheetsClient $client): void
    {
        $this->fake = $client;
        Services::injectMock('sheetsClient', $client);
        Services::resetSingle('sheetWorker');
    }

    /**
     * @return array{processed: int, synced: int, failed: int, skipped: string|null}
     */
    private function runWorker(): array
    {
        return service('sheetWorker')->run(50, null, false);
    }

    private function approved(?string $chuck = null): int
    {
        $w  = service('workflow');
        $id = $this->start('SCA', $this->u['op']);
        $this->fill($id, $this->u['op'], $chuck === null ? [] : ['Machine Chuck Pressure' => $chuck]);
        $w->submit($id, 'DRAFT', null, $this->u['op']);
        $w->act($id, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $w->act($id, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe']);
        $w->act($id, 'approve', '', '', 'PENDING_QA', null, $this->u['qa']);

        return $id;
    }

    /**
     * @return list<list<mixed>>
     */
    private function tab(string $prefix): array
    {
        foreach ($this->fake->data[self::SHEET] ?? [] as $title => $rows) {
            if (str_starts_with($title, $prefix)) {
                return $rows;
            }
        }

        return [];
    }

    public function testEventsAreQueuedInTheSameTransactionAndCoalesced(): void
    {
        $id   = $this->approved();
        $jobs = $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->get()->getResultArray();

        $types = array_values(array_unique(array_column($jobs, 'job_type')));
        sort($types);
        $this->assertSame(['OBSERVATIONS_APPEND', 'REPORT_UPSERT'], $types);
        $this->assertSame(1, count(array_filter($jobs, static fn (array $j): bool => $j['job_type'] === 'REPORT_UPSERT')), 'four workflow events, one waiting upsert');
        $this->assertSame(0, $this->fake->calls, 'Google is never called inside the business transaction');
    }

    public function testReportRowIsUpsertedAndObservationsAppendedOnce(): void
    {
        $id     = $this->approved('26.0');
        $report = service('inspections')->report($id);
        $result = $this->runWorker();

        $this->assertSame(2, $result['synced']);
        $reports = $this->tab('Reports');
        $this->assertSame('Sync Key', $reports[0][0]);
        $this->assertCount(2, $reports, 'header + one row');
        $this->assertSame($report['report_no'] . '|R0', $reports[1][0]);
        $this->assertSame('Approved', $reports[1][11]);
        $this->assertSame('FAIL', $reports[1][12]);
        $this->assertSame(1, $reports[1][13], 'OOS readings');

        $readings = $this->db->table('inspection_readings rd')->join('inspection_observations o', 'o.id = rd.observation_id')
            ->where('o.report_id', $id)->where('rd.result IS NOT NULL')->countAllResults();
        $observations = $this->tab('Observations_');
        $this->assertCount($readings + 1, $observations);

        // A revision: the original row is updated in place, the revision gets its own row.
        $w   = service('workflow');
        $rev = $w->revise($id, 'Typing error', null, $this->u['qa'])['report_id'];
        $this->fill($rev, $this->u['qa'], ['Machine Chuck Pressure' => '24.0']);
        $w->submit($rev, 'DRAFT', null, $this->u['qa']);
        $w->act($rev, 'approve', '', '', 'PENDING_PRODUCTION', null, $this->u['pe']);
        $w->act($rev, 'approve', '', '', 'PENDING_QUALITY', null, $this->u['qe']);
        $w->act($rev, 'approve', '', '', 'PENDING_QA', null, $this->admin);
        $this->runWorker();

        $reports = $this->tab('Reports');
        $this->assertCount(3, $reports);
        $byKey = array_column(array_slice($reports, 1), null, 0);
        $this->assertSame('Superseded', $byKey[$report['report_no'] . '|R0'][11]);
        $this->assertSame('Approved', $byKey[$report['report_no'] . '|R1'][11]);
        $this->assertSame('PASS', $byKey[$report['report_no'] . '|R1'][12]);
        $this->assertCount(2 * $readings + 1, $this->tab('Observations_'));
        $this->assertSame(0, $this->db->table('google_sheet_sync_logs')->where('sync_status !=', 'SYNCED')->countAllResults());
    }

    public function testFailuresBackOffThenFailThenCanBeRetried(): void
    {
        $id = $this->approved();
        for ($i = 0; $i < 6; $i++) {
            $this->fake->failNext(new RuntimeException('Backend Error', 503));
        }

        $this->runWorker();
        $job = $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('job_type', 'REPORT_UPSERT')->get()->getRowArray();
        $this->assertSame('PENDING_SYNC', $job['sync_status']);
        $this->assertSame(1, (int) $job['attempt_count']);
        $this->assertStringContainsString('503', (string) $job['error_message']);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $job['next_attempt_at'], 'retried later, not immediately');
        $this->assertSame(0, $this->runWorker()['processed'], 'nothing is due yet');

        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $this->db->table('google_sheet_sync_logs')->where('id >', 0)->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
            $this->runWorker();
        }
        $job = $this->db->table('google_sheet_sync_logs')->where('id', $job['id'])->get()->getRowArray();
        $this->assertSame('FAILED', $job['sync_status']);
        $this->assertSame(3, (int) $job['attempt_count']);

        $this->assertSame(2, $this->db->table('google_sheet_sync_logs')->where('sync_status', 'FAILED')->countAllResults());

        // Manual retry from the Sync Monitor; the data was never lost.
        service('sheetQueue')->retry((int) $job['id'], service('audit'));
        $this->assertSame('PENDING_SYNC', $this->db->table('google_sheet_sync_logs')->where('id', $job['id'])->get()->getRow('sync_status'));
        $this->assertSame(2, service('sheetQueue')->retryFailed(service('audit')) + 1);
        $this->runWorker();
        $this->assertSame(0, $this->db->table('google_sheet_sync_logs')->where('sync_status !=', 'SYNCED')->countAllResults());
        $this->assertCount(2, $this->tab('Reports'));
    }

    public function testAmbiguousFailureDoesNotDuplicateObservationRows(): void
    {
        // The append reaches Google but the response is lost (timeout after write).
        $client = new class () extends FakeSheetsClient {
            public bool $dropResponse = true;

            public function appendRows(string $spreadsheetId, string $sheet, array $rows): string
            {
                $range = parent::appendRows($spreadsheetId, $sheet, $rows);
                if ($this->dropResponse && str_starts_with($sheet, 'Observations_')) {
                    $this->dropResponse = false;

                    throw new RuntimeException('cURL error 28: Operation timed out', 28);
                }

                return $range;
            }
        };
        $this->useClient($client);
        $id = $this->approved();

        $this->runWorker();
        $this->assertSame('PENDING_SYNC', $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('job_type', 'OBSERVATIONS_APPEND')->get()->getRow('sync_status'));
        $first = count($this->tab('Observations_'));

        $this->db->table('google_sheet_sync_logs')->where('id >', 0)->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->runWorker();
        $this->assertCount($first, $this->tab('Observations_'), 'retry recognised the rows already written');
        $this->assertSame('SYNCED', $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('job_type', 'OBSERVATIONS_APPEND')->get()->getRow('sync_status'));
    }

    public function testCapacityGuardPostponesDetailJobsWithoutCountingAttempts(): void
    {
        $client = new class () extends FakeSheetsClient {
            public function sheets(string $spreadsheetId): array
            {
                $tabs = parent::sheets($spreadsheetId);
                foreach ($tabs as $title => &$tab) {
                    if (str_starts_with($title, 'Observations_')) {
                        $tab['rows'] = 360000; // 360 000 x 26 = 9.36 M cells
                    }
                }

                return $tabs;
            }
        };
        $this->useClient($client);
        $id = $this->approved();
        $this->runWorker();

        $job = $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('job_type', 'OBSERVATIONS_APPEND')->get()->getRowArray();
        $this->assertSame('PENDING_SYNC', $job['sync_status']);
        $this->assertSame(0, (int) $job['attempt_count']);
        $this->assertStringContainsString('full', (string) $job['error_message']);
        $this->assertSame('SYNCED', $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('job_type', 'REPORT_UPSERT')->get()->getRow('sync_status'));
    }

    public function testPausedSyncKeepsJobsAndUnconfiguredSyncQueuesNothing(): void
    {
        $this->setSettings(['google.sync_enabled' => '0']);
        $id = $this->approved();
        $this->assertNotNull($this->runWorker()['skipped']);
        $this->assertSame(2, $this->db->table('google_sheet_sync_logs')->where('report_id', $id)->where('sync_status', 'PENDING_SYNC')->countAllResults());

        $this->setSettings(['google.spreadsheet_id' => '']);
        $other = $this->approved();
        $this->assertSame(0, $this->db->table('google_sheet_sync_logs')->where('report_id', $other)->countAllResults());

        // Backfill queues it later, once configured.
        $this->setSettings(['google.spreadsheet_id' => self::SHEET, 'google.sync_enabled' => '1']);
        service('sheetQueue')->backfill($this->today(), $this->today(), service('audit'));
        $this->assertSame(2, $this->db->table('google_sheet_sync_logs')->where('report_id', $other)->countAllResults());
        $this->runWorker();
        $this->assertCount(3, $this->tab('Reports'));
    }
}
