<?php

namespace Tests\Support;

use App\Database\SqlScript;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Integration tests against the dedicated test database (group "tests").
 *
 * The schema is migrated once per test class; before every test all tables are
 * emptied and refilled with reference data, the demo master data and the three
 * demo templates (published), plus a Super Admin. The suite refuses to run when
 * the connection does not point to a database whose name contains "test".
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';
    protected $DBGroup     = 'tests';

    /** @var array<string, mixed> */
    protected array $admin = [];

    private static int $sequence = 0;

    protected function setUp(): void
    {
        // PHP keeps $_SESSION and the shared services for the whole process: start every
        // test like a fresh request (no login, no cached settings or permissions).
        $_SESSION = [];
        \Config\Services::reset(true);
        parent::setUp();

        $database = (string) $this->db->getDatabase();
        if (! str_contains($database, 'test')) {
            $this->fail("Refusing to run database tests against '{$database}'.");
        }
        $this->resetData();
    }

    protected function resetData(): void
    {
        $db = $this->db;
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->getResultArray() as $row) {
            $table = (string) array_values($row)[0];
            if ($table !== 'migrations') {
                $db->query('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            }
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        SqlScript::run($db, ROOTPATH . 'database/schema/qms_reference_data.sql');
        $this->admin = $this->createUser('SUPER_ADMIN', null, 'admin');
        SqlScript::run($db, ROOTPATH . 'database/samples/qms_demo_templates.sql');
        $db->table('inspection_templates')->where('status', 'DRAFT')->update([
            'status' => 'PUBLISHED', 'published_at' => gmdate('Y-m-d H:i:s'), 'published_by' => $this->admin['id'],
        ]);
        // Demo gauge DVC-002 is deliberately expired; everything else is in calibration.
        service('settings')->refresh();
    }

    /**
     * Creates a login for a role (and optionally links an employee) and returns it in
     * the same shape as AuthService::user().
     *
     * @return array<string, mixed>
     */
    protected function createUser(string $roleCode, ?string $employeeCode = null, ?string $username = null, string $password = 'Correct-Horse-91'): array
    {
        $role       = $this->db->table('roles')->where('code', $roleCode)->get(1)->getRowArray();
        $employeeId = $employeeCode === null ? null
            : (int) $this->db->table('employees')->where('employee_code', $employeeCode)->get(1)->getRow('id');
        $username ??= strtolower($roleCode) . '_' . (++self::$sequence);

        $this->db->table('users')->insert([
            'employee_id'          => $employeeId,
            'role_id'              => $role['id'],
            'username'             => $username,
            'password_hash'        => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'status'               => 'ACTIVE',
            'must_change_password' => 0,
            'security_stamp'       => bin2hex(random_bytes(16)),
            'created_at'           => gmdate('Y-m-d H:i:s'),
            'password_changed_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        return $this->userRow((int) $this->db->insertID());
    }

    /**
     * @return array<string, mixed>
     */
    protected function userRow(int $id): array
    {
        $row = $this->db->table('users u')
            ->select('u.id, u.username, u.email, u.status, u.must_change_password, u.security_stamp, u.employee_id, u.role_id,
                      u.password_changed_at, r.code AS role_code, r.name AS role_name, r.is_super_admin,
                      e.employee_code, e.full_name, e.designation')
            ->join('roles r', 'r.id = u.role_id')
            ->join('employees e', 'e.id = u.employee_id', 'left')
            ->where('u.id', $id)->get(1)->getRowArray();
        $row['id']           = (int) $row['id'];
        $row['employee_id']  = $row['employee_id'] === null ? null : (int) $row['employee_id'];
        $row['display_name'] = $row['full_name'] ?: $row['username'];

        return $row;
    }

    /**
     * Logs the user in for code that reads the session (password re-entry, audit actor).
     *
     * @param array<string, mixed> $user
     */
    protected function actingAs(array $user): void
    {
        $session = session();
        $session->set([
            'qms_uid'       => $user['id'],
            'qms_stamp'     => $user['security_stamp'],
            'qms_login_at'  => time(),
            'qms_last_seen' => time(),
        ]);
        $this->resetUserServices();
    }

    /**
     * Rebuilds the shared services that remember the logged-in user, as a new
     * HTTP request (a new PHP process) would.
     */
    protected function resetUserServices(): void
    {
        foreach (['auth', 'authorization', 'navigation', 'inspections', 'workflow', 'printBuilder', 'sheetRowMapper', 'userAdmin', 'roleAdmin', 'masterData', 'gauges', 'templates'] as $service) {
            \Config\Services::resetSingle($service);
        }
    }

    /**
     * @param array<string, scalar> $values setting_key => value
     */
    protected function setSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->db->table('system_settings')->where('setting_key', $key)->update(['setting_value' => (string) $value]);
        }
        service('settings')->refresh();
    }

    protected function id(string $table, string $column, string $value): int
    {
        return (int) $this->db->table($table)->where($column, $value)->get(1)->getRow('id');
    }

    protected function today(): string
    {
        return service('productionCalendar')->current()['date'];
    }

    /**
     * Starts an inspection for the demo part BF-1001.
     *
     * @param array<string, mixed> $user
     */
    protected function start(string $typeCode, array $user, string $machine = 'CNC-01'): int
    {
        $type   = $this->db->table('report_types')->where('code', $typeCode)->get(1)->getRowArray();
        $shifts = service('productionCalendar')->shifts();

        return service('inspections')->start([
            'client_uuid'     => '',
            'report_type_id'  => $type['id'],
            'part_id'         => $this->id('parts', 'part_number', 'BF-1001'),
            'machine_id'      => $this->id('machines', 'machine_code', $machine),
            'inspection_date' => $this->today(),
            'shift_id'        => $type['header_shift'] === 'HIDDEN' ? null : $shifts[0]['id'],
        ], $user);
    }

    /**
     * Saves passing values for every reading of a report (or of one round), gauges
     * that are in calibration, and the required header fields.
     *
     * @param array<string, mixed>  $user
     * @param array<string, string> $override parameter name => raw value for reading 1
     *
     * @return array<string, mixed> saveDraft() result
     */
    protected function fill(int $reportId, array $user, array $override = [], ?int $roundId = null): array
    {
        $service = service('inspections');
        $report  = $service->report($reportId);
        $params  = $service->templateParameters((int) $report['template_id']);
        $cells   = [];
        $obsList = [];

        foreach ($service->rounds($reportId) as $round) {
            if ($roundId !== null && (int) $round['id'] !== $roundId) {
                continue;
            }
            foreach ($round['observations'] as $paramId => $obs) {
                $param = $params[$paramId];
                for ($n = 1; $n <= (int) $param['observation_count']; $n++) {
                    $value = $n === 1 && isset($override[$param['name']]) ? $override[$param['name']] : $this->passingValue($param, $obs, (string) $report['inspection_date']);
                    $cells[] = ['observation_id' => (int) $obs['id'], 'reading_no' => $n, 'value' => $value];
                }
                if ($param['gauge_type_id'] !== null) {
                    $gauge = $this->db->table('gauges')->where('gauge_type_id', $param['gauge_type_id'])->where('status', 'ACTIVE')
                        ->where('calibration_due_date >=', $report['inspection_date'])->orderBy('id')->get(1)->getRowArray();
                    $obsList[] = ['observation_id' => (int) $obs['id'], 'gauge_id' => $gauge['id'] ?? null];
                }
            }
        }

        $header = [];
        if ($report['header_operator'] !== 'HIDDEN') {
            $header['operator_employee_id'] = $this->id('employees', 'employee_code', 'E1001');
        }
        if ($report['header_setter'] !== 'HIDDEN') {
            $header['setter_employee_id'] = $this->id('employees', 'employee_code', 'E1002');
        }
        if ($report['header_timing'] !== 'HIDDEN') {
            $header['received_at'] = '07:00';
            $header['finish_at']   = '07:40';
        }
        if ($report['header_shift'] !== 'HIDDEN') {
            $header['shift_id'] = service('productionCalendar')->shifts()[0]['id'];
        }

        $payload = ['lock_version' => (int) $report['lock_version'], 'cells' => $cells, 'observations' => $obsList];
        if ($header !== []) {
            $payload['header'] = $header;
        }

        return $service->saveDraft($reportId, $payload, $user);
    }

    /**
     * @param array<string, mixed> $param
     * @param array<string, mixed> $obs
     */
    protected function passingValue(array $param, array $obs, string $date): string
    {
        return match ($param['observation_type']) {
            'NUMERIC', 'PERCENTAGE' => $this->midpoint($obs['lsl'], $obs['usl'], (int) $param['decimal_places']),
            'OK_NOT_OK', 'VISUAL'   => 'OK',
            'GO_NO_GO'              => 'GO',
            'DATE'                  => (new \DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d'),
            'TIME'                  => '10:30',
            default                 => 'Checked',
        };
    }

    private function midpoint(?string $lsl, ?string $usl, int $places): string
    {
        $lo = $lsl === null ? null : (float) $lsl;
        $hi = $usl === null ? null : (float) $usl;
        $v  = match (true) {
            $lo !== null && $hi !== null => ($lo + $hi) / 2,
            $lo !== null                 => $lo + 1,
            $hi !== null                 => max(0, $hi - 1),
            default                      => 5,
        };

        return number_format($v, $places, '.', '');
    }
}
