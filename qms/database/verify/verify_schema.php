#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * QMS reference-schema verification
 * ---------------------------------
 * Loads schema/qms_schema.sql, schema/qms_reference_data.sql and
 * samples/qms_demo_templates.sql into a throw-away database on a
 * NON-PRODUCTION MySQL 8 server and asserts the integrity rules the
 * application relies on: constraints, triggers, gap-free numbering, template
 * resolution, the inspection lifecycle, revisions and (optionally) the
 * privileges of the dedicated MySQL accounts.
 *
 *   php verify_schema.php [--with-grants] [--keep]
 *
 * Connection (an administrative account on a scratch server):
 *   QMS_VERIFY_SOCKET   unix socket path            (default /var/run/mysqld/mysqld.sock)
 *   QMS_VERIFY_HOST     host, used instead of the socket when set
 *   QMS_VERIFY_PORT     port                         (default 3306)
 *   QMS_VERIFY_USER     user                         (default root)
 *   QMS_VERIFY_PASSWORD password                     (default empty)
 *
 * --with-grants  also creates the accounts from security/qms_db_users.sql
 *                (suffixed names, scoped to the scratch database), checks what
 *                each may and may not do, then drops them.
 * --keep         keep the scratch database for inspection.
 */

const ROOT = __DIR__ . '/..';

$opts       = getopt('', ['with-grants', 'keep']);
$withGrants = isset($opts['with-grants']);
$keep       = isset($opts['keep']);

$conn = [
    'socket'   => getenv('QMS_VERIFY_SOCKET') ?: '/var/run/mysqld/mysqld.sock',
    'host'     => getenv('QMS_VERIFY_HOST') ?: null,
    'port'     => (int) (getenv('QMS_VERIFY_PORT') ?: 3306),
    'user'     => getenv('QMS_VERIFY_USER') ?: 'root',
    'password' => getenv('QMS_VERIFY_PASSWORD') ?: '',
];

$db      = 'qms_verify_' . bin2hex(random_bytes(4));
$passed  = 0;
$failed  = [];
$created = [];

function pdo(array $conn, string $user, string $password, ?string $database): PDO
{
    $dsn = $conn['host'] !== null
        ? "mysql:host={$conn['host']};port={$conn['port']}"
        : "mysql:unix_socket={$conn['socket']}";
    if ($database !== null) {
        $dsn .= ";dbname={$database}";
    }
    $pdo = new PDO($dsn . ';charset=utf8mb4', $user, $password, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/** Pipes a .sql file through the mysql CLI (needed for DELIMITER handling). */
function loadSqlFile(array $conn, string $database, string $file): void
{
    $args = ['mysql', '--user=' . $conn['user'], '--database=' . $database, '--show-warnings'];
    $args[] = $conn['host'] !== null ? '--host=' . $conn['host'] : '--socket=' . $conn['socket'];
    if ($conn['host'] !== null) {
        $args[] = '--port=' . $conn['port'];
    }
    $env = getenv();
    if ($conn['password'] !== '') {
        $env['MYSQL_PWD'] = $conn['password'];
    }
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (! is_resource($proc)) {
        throw new RuntimeException('Cannot start the mysql client');
    }
    fwrite($pipes[0], (string) file_get_contents($file));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || stripos($out, 'warning') !== false) {
        throw new RuntimeException('Loading ' . basename($file) . " failed or warned:\n" . $out);
    }
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed[] = $label;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

/** Expects the statement to fail with an error whose code or message contains $expect. */
function rejects(PDO $pdo, string $label, string $sql, array $params, string $expect): void
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        check($label, false, 'statement succeeded');
    } catch (PDOException $e) {
        $info = ($e->errorInfo[1] ?? '') . ' ' . $e->getMessage();
        check($label, str_contains($info, $expect), $info);
    }
}

function q(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt;
}

function one(PDO $pdo, string $sql, array $params = []): mixed
{
    return q($pdo, $sql, $params)->fetchColumn();
}

function uuid(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Mirrors SpecEvaluator: inclusive limits, exact decimal comparison done by MySQL here. */
function evaluate(array $param, array $value, string $inspectionDate): string
{
    switch ($param['observation_type']) {
        case 'NUMERIC':
        case 'PERCENTAGE':
            $v = (float) $value['numeric'];
            if ($param['lsl'] !== null && $v < (float) $param['lsl']) {
                return 'FAIL';
            }
            if ($param['usl'] !== null && $v > (float) $param['usl']) {
                return 'FAIL';
            }

            return 'PASS';

        case 'OK_NOT_OK':
        case 'VISUAL':
            return $value['choice'] === 'OK' ? 'PASS' : 'FAIL';

        case 'GO_NO_GO':
            return $value['choice'] === 'GO' ? 'PASS' : 'FAIL';

        case 'DATE':
            return $param['date_rule'] === 'ON_OR_AFTER_INSPECTION_DATE' && $value['date'] < $inspectionDate ? 'FAIL' : 'PASS';

        default:
            return 'NOT_APPLICABLE';
    }
}

function allocateNumber(PDO $pdo, int $typeId, string $period): int
{
    q($pdo, 'INSERT INTO document_sequences (report_type_id, period_key, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)', [$typeId, $period]);

    return (int) one($pdo, 'SELECT LAST_INSERT_ID()');
}

function resolveTemplate(PDO $pdo, string $type, string $part, string $machine): array
{
    $sql = <<<'SQL'
        SELECT t.template_code,
               (EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)) * 2
             + (EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)) AS specificity
        FROM inspection_templates t
        JOIN report_types rt ON rt.id = t.report_type_id AND rt.code = ?
        WHERE t.status = 'PUBLISHED'
          AND (NOT EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)
               OR EXISTS (SELECT 1 FROM template_part_map pm JOIN parts p ON p.id = pm.part_id
                          WHERE pm.template_id = t.id AND p.part_number = ?))
          AND (NOT EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)
               OR EXISTS (SELECT 1 FROM template_machine_map mm JOIN machines m ON m.id = mm.machine_id
                          WHERE mm.template_id = t.id AND m.machine_code = ?))
        ORDER BY specificity DESC
        LIMIT 2
        SQL;

    return q($pdo, $sql, [$type, $part, $machine])->fetchAll(PDO::FETCH_ASSOC);
}

$admin = pdo($conn, $conn['user'], $conn['password'], null);

try {
    echo "Scratch database: {$db}\n";
    $admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

    echo "\n== Load\n";
    foreach (['schema/qms_schema.sql', 'schema/qms_reference_data.sql', 'samples/qms_demo_templates.sql'] as $f) {
        loadSqlFile($conn, $db, ROOT . '/' . $f);
        check("{$f} loads without errors or warnings", true);
    }

    $pdo = pdo($conn, $conn['user'], $conn['password'], $db);

    echo "\n== Structure\n";
    check('34 tables', (int) one($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === 34);
    check('all tables InnoDB / utf8mb4_0900_ai_ci', (int) one($pdo, "SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND (engine <> 'InnoDB' OR table_collation <> 'utf8mb4_0900_ai_ci')") === 0);
    check('27 integrity triggers', (int) one($pdo, 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()') === 27);
    $unindexedFk = (int) one($pdo, <<<'SQL'
        SELECT COUNT(*) FROM information_schema.key_column_usage k
        WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND k.ordinal_position = 1
          AND NOT EXISTS (SELECT 1 FROM information_schema.statistics s
                          WHERE s.table_schema = k.table_schema AND s.table_name = k.table_name
                            AND s.column_name = k.column_name AND s.seq_in_index = 1)
        SQL);
    check('every foreign key leads an index', $unindexedFk === 0, "{$unindexedFk} without index");

    echo "\n== Reference data and permission matrix\n";
    check('6 system roles', (int) one($pdo, 'SELECT COUNT(*) FROM roles WHERE is_system = 1') === 6);
    $permCount = (int) one($pdo, 'SELECT COUNT(*) FROM permissions');
    check('35 permissions', $permCount === 35, (string) $permCount);
    $perms = static fn (string $role): array => q($pdo, 'SELECT p.code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
        JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? ORDER BY p.code', [$role])->fetchAll(PDO::FETCH_COLUMN);
    check('SUPER_ADMIN holds every permission', count($perms('SUPER_ADMIN')) === $permCount);
    check('OPERATOR can only create/submit/view own/cancel own/print', $perms('OPERATOR') === [
        'inspection.cancel_own', 'inspection.create', 'inspection.print', 'inspection.submit', 'inspection.view_own']);
    check('VIEWER is read-only', array_filter($perms('VIEWER'), static fn ($p) => ! preg_match('/\.(view|view_all|print|export)$/', $p)) === []);
    check('only PRODUCTION_ENGINEER (and SUPER_ADMIN) can do production verification',
        q($pdo, "SELECT r.code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                 WHERE p.code = 'inspection.verify_production' ORDER BY r.code")->fetchAll(PDO::FETCH_COLUMN) === ['PRODUCTION_ENGINEER', 'SUPER_ADMIN']);
    check('3 report types with prefixes SCA/PPI/IPR', q($pdo, 'SELECT doc_prefix FROM report_types ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['SCA', 'PPI', 'IPR']);
    rejects($pdo, 'lower-case / invalid document prefix rejected', "UPDATE report_types SET doc_prefix = 'sc-a' WHERE code = 'SCA'", [], '3819');
    rejects($pdo, 'workflow with zero approval stages rejected',
        "UPDATE report_types SET requires_production_verification = 0, requires_quality_verification = 0, requires_qa_approval = 0 WHERE code = 'SCA'", [], '3819');
    check('demo templates: 14 SCA, 10 PPI, 10 IPR parameters', q($pdo, <<<'SQL'
        SELECT COUNT(*) FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id
        JOIN inspection_templates t ON t.id = s.template_id GROUP BY t.template_code ORDER BY t.template_code
        SQL)->fetchAll(PDO::FETCH_COLUMN) === [10, 10, 14]);

    // Users for the scenario (password hashes are throw-away)
    $roleId  = static fn (string $code): int => (int) one($pdo, 'SELECT id FROM roles WHERE code = ?', [$code]);
    $empId   = static fn (string $code): int => (int) one($pdo, 'SELECT id FROM employees WHERE employee_code = ?', [$code]);
    $mkUser  = static function (string $username, string $role, ?int $employee) use ($pdo, $roleId): int {
        q($pdo, 'INSERT INTO users (employee_id, role_id, username, password_hash, must_change_password, security_stamp)
                 VALUES (?, ?, ?, ?, 0, ?)', [$employee, $roleId($role), $username,
            password_hash('verify-only-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT), bin2hex(random_bytes(16))]);

        return (int) $pdo->lastInsertId();
    };
    $op = $mkUser('demo.operator', 'OPERATOR', $empId('E1001'));
    $pe = $mkUser('demo.pe', 'PRODUCTION_ENGINEER', $empId('E3001'));
    $qe = $mkUser('demo.qe', 'QUALITY_ENGINEER', $empId('E2001'));
    $qa = $mkUser('demo.qa', 'QA_ADMIN', $empId('E4001'));
    rejects($pdo, 'one login per employee', 'INSERT INTO users (employee_id, role_id, username, password_hash, security_stamp) VALUES (?, ?, ?, ?, ?)',
        [$empId('E1001'), $roleId('OPERATOR'), 'dup.login', 'x', str_repeat('a', 32)], '1062');

    echo "\n== Template management\n";
    q($pdo, "UPDATE inspection_templates SET status = 'PUBLISHED', published_at = UTC_TIMESTAMP(), published_by = ? WHERE status = 'DRAFT'", [$qa]);
    check('demo templates published', (int) one($pdo, "SELECT COUNT(*) FROM inspection_templates WHERE status = 'PUBLISHED'") === 3);
    $tpl = static fn (string $code, int $v = 1): int => (int) one($pdo, 'SELECT id FROM inspection_templates WHERE template_code = ? AND version = ?', [$code, $v]);
    $scaSection = (int) one($pdo, 'SELECT id FROM template_sections WHERE template_id = ? ORDER BY sort_order LIMIT 1', [$tpl('SCA-STD')]);
    rejects($pdo, 'published template: parameter LSL/USL cannot change', 'UPDATE template_parameters SET usl = usl + 1 WHERE section_id = ?', [$scaSection], 'QMS-LOCK');
    rejects($pdo, 'published template: header cannot change', "UPDATE inspection_templates SET name = 'changed' WHERE id = ?", [$tpl('SCA-STD')], 'QMS-LOCK');
    rejects($pdo, 'published template: no new sections', "INSERT INTO template_sections (template_id, title) VALUES (?, 'Extra')", [$tpl('SCA-STD')], 'QMS-LOCK');
    rejects($pdo, 'published template: cannot be deleted', 'DELETE FROM inspection_templates WHERE id = ?', [$tpl('SCA-STD')], 'QMS-LOCK');

    // Versioning on a scratch template code
    $ppiType = (int) one($pdo, "SELECT id FROM report_types WHERE code = 'PPI'");
    $newTpl  = static function (int $version, ?int $clonedFrom) use ($pdo, $ppiType): int {
        q($pdo, "INSERT INTO inspection_templates (report_type_id, template_code, version, name, format_doc_no, format_made_date, cloned_from_id)
                 VALUES (?, 'VER-TEST', ?, 'Version test', 'QA/F/99', '2026-01-01', ?)", [$ppiType, $version, $clonedFrom]);

        return (int) $pdo->lastInsertId();
    };
    $v1 = $newTpl(1, null);
    q($pdo, "INSERT INTO template_sections (template_id, title) VALUES (?, 'S1')", [$v1]);
    $s1    = (int) $pdo->lastInsertId();
    $param = (int) one($pdo, "SELECT id FROM inspection_parameters WHERE code = 'THROAT_DIA'");
    rejects($pdo, 'LSL greater than USL rejected', "INSERT INTO template_parameters (section_id, parameter_id, name, observation_type, lsl, usl) VALUES (?, ?, 'x', 'NUMERIC', 6.6, 6.4)", [$s1, $param], '3819');
    rejects($pdo, 'LSL on an OK/NOT OK parameter rejected', "INSERT INTO template_parameters (section_id, parameter_id, name, observation_type, lsl) VALUES (?, ?, 'x', 'OK_NOT_OK', 1)", [$s1, $param], '3819');
    rejects($pdo, 'gauge required without gauge type rejected', "INSERT INTO template_parameters (section_id, parameter_id, name, observation_type, gauge_required) VALUES (?, ?, 'x', 'NUMERIC', 1)", [$s1, $param], '3819');
    rejects($pdo, 'percentage limits above 100 rejected', "INSERT INTO template_parameters (section_id, parameter_id, name, observation_type, usl) VALUES (?, ?, 'x', 'PERCENTAGE', 120)", [$s1, $param], '3819');
    q($pdo, "INSERT INTO template_parameters (section_id, parameter_id, name, observation_type, lsl, usl) VALUES (?, ?, 'Throat Diameter', 'NUMERIC', 6.4, 6.6)", [$s1, $param]);
    q($pdo, "UPDATE inspection_templates SET status = 'PUBLISHED', published_at = UTC_TIMESTAMP(), published_by = ? WHERE id = ?", [$qa, $v1]);
    $v2 = $newTpl(2, $v1);
    rejects($pdo, 'only one PUBLISHED version per template code', "UPDATE inspection_templates SET status = 'PUBLISHED', published_at = UTC_TIMESTAMP(), published_by = ? WHERE id = ?", [$qa, $v2], '1062');
    q($pdo, "UPDATE inspection_templates SET status = 'RETIRED', retired_at = UTC_TIMESTAMP() WHERE id = ?", [$v1]);
    q($pdo, "UPDATE inspection_templates SET status = 'PUBLISHED', published_at = UTC_TIMESTAMP(), published_by = ? WHERE id = ?", [$qa, $v2]);
    check('retire v1 then publish v2', one($pdo, 'SELECT status FROM inspection_templates WHERE id = ?', [$v2]) === 'PUBLISHED');
    rejects($pdo, 'retired version cannot be re-published', "UPDATE inspection_templates SET status = 'PUBLISHED', retired_at = NULL WHERE id = ?", [$v1], 'QMS-LOCK');

    echo "\n== Template resolution (part + machine + report type)\n";
    $r = resolveTemplate($pdo, 'SCA', 'BF-1001', 'CNC-01');
    check('SCA on CNC-01 -> SCA-STD (machine mapping)', count($r) === 1 && $r[0]['template_code'] === 'SCA-STD');
    check('SCA on VMC-01 -> no template (not mapped)', resolveTemplate($pdo, 'SCA', 'BF-1001', 'VMC-01') === []);
    // VER-TEST v2 is a published PPI template without mappings, i.e. a generic fallback
    $r = resolveTemplate($pdo, 'PPI', 'BF-1001', 'VMC-01');
    check('PPI for BF-1001 -> part-mapped PPI-BF1001 beats the generic template',
        $r[0]['template_code'] === 'PPI-BF1001' && (count($r) === 1 || $r[1]['specificity'] < $r[0]['specificity']), json_encode($r));
    q($pdo, "INSERT INTO parts (part_number, part_name) VALUES ('BF-2002', 'Unmapped part')");
    $r = resolveTemplate($pdo, 'PPI', 'BF-2002', 'VMC-01');
    check('unmapped part falls back to the generic template', count($r) === 1 && $r[0]['template_code'] === 'VER-TEST', json_encode($r));
    q($pdo, "INSERT INTO inspection_templates (report_type_id, template_code, name, format_doc_no, format_made_date, status, published_at, published_by)
             VALUES (?, 'VER-TEST-2', 'Second generic', 'QA/F/98', '2026-01-01', 'PUBLISHED', UTC_TIMESTAMP(), ?)", [$ppiType, $qa]);
    $r = resolveTemplate($pdo, 'PPI', 'BF-2002', 'VMC-01');
    check('two equally specific templates are detected as ambiguous', count($r) === 2 && $r[0]['specificity'] === $r[1]['specificity']);
    q($pdo, "UPDATE inspection_templates SET status = 'RETIRED', retired_at = UTC_TIMESTAMP() WHERE template_code = 'VER-TEST-2'");

    echo "\n== Setup Change Approval lifecycle\n";
    $scaType = (int) one($pdo, "SELECT id FROM report_types WHERE code = 'SCA'");
    $iprType = (int) one($pdo, "SELECT id FROM report_types WHERE code = 'IPR'");
    $part    = (int) one($pdo, "SELECT id FROM parts WHERE part_number = 'BF-1001'");
    $cnc01   = (int) one($pdo, "SELECT id FROM machines WHERE machine_code = 'CNC-01'");
    $shiftA  = (int) one($pdo, "SELECT id FROM shifts WHERE code = 'A'");
    $shiftB  = (int) one($pdo, "SELECT id FROM shifts WHERE code = 'B'");
    $date    = '2026-10-03';
    $uuid    = uuid();

    $insertReport = 'INSERT INTO inspection_reports (client_uuid, report_type_id, template_id, part_id, machine_id, shift_id, inspection_date,
                     received_at, finish_at, operator_employee_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    q($pdo, $insertReport, [$uuid, $scaType, $tpl('SCA-STD'), $part, $cnc01, $shiftA, $date, '2026-10-03 02:30:00', '2026-10-03 03:05:00', $empId('E1001'), $op]);
    $sca = (int) $pdo->lastInsertId();
    rejects($pdo, 'same tablet request (client_uuid) cannot create a second report', $insertReport,
        [$uuid, $scaType, $tpl('SCA-STD'), $part, $cnc01, $shiftA, $date, null, null, null, $op], '1062');
    rejects($pdo, 'template must belong to the report type (composite FK)', $insertReport,
        [uuid(), $iprType, $tpl('SCA-STD'), $part, $cnc01, null, $date, null, null, null, $op], '1452');
    rejects($pdo, 'finish time before received time rejected', $insertReport,
        [uuid(), $scaType, $tpl('SCA-STD'), $part, $cnc01, $shiftA, $date, '2026-10-03 03:00:00', '2026-10-03 02:00:00', null, $op], '3819');

    q($pdo, 'INSERT INTO inspection_rounds (report_id, round_no, created_by) VALUES (?, 1, ?)', [$sca, $op]);
    $scaRound = (int) $pdo->lastInsertId();

    $gaugeFor = ['PRESSURE_GAUGE' => 'PG-01', 'REFRACTOMETER' => 'REF-01', 'WEIGH_SCALE' => 'WS-01'];
    $params   = q($pdo, <<<'SQL'
        SELECT tp.*, gt.code AS gauge_type_code, ip.code AS parameter_code FROM template_parameters tp
        JOIN template_sections s ON s.id = tp.section_id
        JOIN inspection_parameters ip ON ip.id = tp.parameter_id
        LEFT JOIN gauge_types gt ON gt.id = tp.gauge_type_id
        WHERE s.template_id = ? ORDER BY s.sort_order, tp.sort_order
        SQL, [$tpl('SCA-STD')])->fetchAll(PDO::FETCH_ASSOC);
    $oos = 0;
    foreach ($params as $p) {
        $gauge = null;
        $valid = null;
        $due   = null;
        if ((int) $p['gauge_required'] === 1) {
            $g     = q($pdo, 'SELECT id, calibration_due_date, status FROM gauges WHERE gauge_code = ?', [$gaugeFor[$p['gauge_type_code']]])->fetch(PDO::FETCH_ASSOC);
            $gauge = (int) $g['id'];
            $due   = $g['calibration_due_date'];
            $valid = (int) ($g['status'] === 'ACTIVE' && $due >= $date);
        }
        q($pdo, 'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id, lsl, usl, gauge_id, gauge_cal_due_date, gauge_cal_valid, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$sca, $scaRound, $p['id'], $p['lsl'], $p['usl'], $gauge, $due, $valid, $op]);
        $obs     = (int) $pdo->lastInsertId();
        $results = [];
        for ($n = 1; $n <= (int) $p['observation_count']; $n++) {
            $value = ['numeric' => null, 'choice' => null, 'date' => null];
            switch ($p['observation_type']) {
                case 'NUMERIC':
                case 'PERCENTAGE':
                    // Average weight after machining, 2nd observation, deliberately out of spec (USL 340)
                    $value['numeric'] = $p['parameter_code'] === 'AVG_WT_MACHINED' && $n === 2 ? '345.0' : $p['lsl'];
                    break;
                case 'DATE':
                    $value['date'] = '2026-12-31';
                    break;
                default:
                    $value['choice'] = $p['observation_type'] === 'GO_NO_GO' ? 'GO' : 'OK';
            }
            $res       = evaluate($p, $value, $date);
            $results[] = $res;
            $oos += $res === 'FAIL' ? 1 : 0;
            q($pdo, 'INSERT INTO inspection_readings (observation_id, reading_no, value_numeric, value_choice, value_date, result, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$obs, $n, $value['numeric'], $value['choice'], $value['date'], $res, $op]);
        }
        q($pdo, 'UPDATE inspection_observations SET result = ? WHERE id = ?', [in_array('FAIL', $results, true) ? 'FAIL' : 'PASS', $obs]);
    }
    check('boundary reading equal to LSL is PASS (inclusive limits)', (int) one($pdo, <<<'SQL'
        SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
        WHERE o.report_id = ? AND rd.value_numeric = o.lsl AND rd.result = 'PASS'
        SQL, [$sca]) > 0);
    check('one out-of-spec reading recorded', $oos === 1);
    $anyObs = (int) one($pdo, 'SELECT id FROM inspection_observations WHERE report_id = ? LIMIT 1', [$sca]);
    rejects($pdo, 'a reading holds exactly one typed value', "INSERT INTO inspection_readings (observation_id, reading_no, value_numeric, value_choice, result) VALUES (?, 9, 1, 'OK', 'PASS')", [$anyObs], '3819');
    rejects($pdo, 'a result without a value is rejected', "INSERT INTO inspection_readings (observation_id, reading_no, result) VALUES (?, 9, 'PASS')", [$anyObs], '3819');
    rejects($pdo, 'at most 10 readings per observation', "INSERT INTO inspection_readings (observation_id, reading_no, value_choice, result) VALUES (?, 11, 'OK', 'PASS')", [$anyObs], '3819');
    rejects($pdo, 'one observation per parameter per round', 'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id) VALUES (?, ?, ?)',
        [$sca, $scaRound, $params[0]['id']], '1062');

    // Gap-free numbering
    $n1 = allocateNumber($pdo, $scaType, '2099-01');
    $n2 = allocateNumber($pdo, $scaType, '2099-01');
    $pdo->beginTransaction();
    allocateNumber($pdo, $scaType, '2099-01');
    $pdo->rollBack();
    $n3 = allocateNumber($pdo, $scaType, '2099-01');
    check('sequence 1, 2 and a rolled-back allocation does not leave a gap (next is 3)', [$n1, $n2, $n3] === [1, 2, 3]);
    check('new period starts at 1', allocateNumber($pdo, $scaType, '2099-02') === 1);

    // Submit (one transaction): number, round signature, header, signature row, outbox job
    $pdo->beginTransaction();
    $seq      = allocateNumber($pdo, $scaType, '2026-10');
    $reportNo = sprintf('SCA-2026-10-%06d', $seq);
    q($pdo, "UPDATE inspection_rounds SET status = 'SIGNED', operator_user_id = ?, operator_signed_at = UTC_TIMESTAMP() WHERE id = ?", [$op, $scaRound]);
    q($pdo, "UPDATE inspection_reports SET report_no = ?, status = 'PENDING_PRODUCTION', overall_result = 'FAIL', oos_count = ?,
             submitted_at = UTC_TIMESTAMP(), submitted_by = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = 0", [$reportNo, $oos, $op, $sca]);
    $sign = static function (int $report, string $stage, string $action, string $from, string $to, int $user, string $role, ?string $remarks = null) use ($pdo, $roleId): void {
        q($pdo, 'INSERT INTO inspection_approvals (report_id, stage, action, from_status, to_status, user_id, role_id, remarks, reauthenticated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$report, $stage, $action, $from, $to, $user, $roleId($role), $remarks, (int) ($stage !== 'SUBMISSION')]);
    };
    $sign($sca, 'SUBMISSION', 'SUBMITTED', 'DRAFT', 'PENDING_PRODUCTION', $op, 'OPERATOR');
    q($pdo, "INSERT INTO google_sheet_sync_logs (report_id, job_type, sheet_name) VALUES (?, 'REPORT_UPSERT', 'Reports')", [$sca]);
    $pdo->commit();
    check('report number format SCA-YYYY-MM-NNNNNN', (bool) preg_match('/^SCA-2026-10-\d{6}$/', (string) one($pdo, 'SELECT report_no FROM inspection_reports WHERE id = ?', [$sca])));

    rejects($pdo, 'submitted: readings are read-only', "UPDATE inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id SET rd.value_numeric = 330 WHERE o.report_id = ? AND rd.value_numeric = 345", [$sca], 'QMS-LOCK');
    rejects($pdo, 'submitted: readings cannot be deleted', 'DELETE rd FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ?', [$sca], 'QMS-LOCK');
    rejects($pdo, 'submitted: no new observations', 'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id) VALUES (?, ?, ?)', [$sca, $scaRound, $params[1]['id']], 'QMS-LOCK');
    rejects($pdo, 'submitted: header data is read-only', "UPDATE inspection_reports SET remarks = 'edited later' WHERE id = ?", [$sca], 'QMS-LOCK');
    rejects($pdo, 'submitted: round is read-only', "UPDATE inspection_rounds SET remarks = 'edited later' WHERE id = ?", [$scaRound], 'QMS-LOCK');

    // Workflow stages
    q($pdo, "UPDATE inspection_reports SET status = 'PENDING_QUALITY' WHERE id = ?", [$sca]);
    $sign($sca, 'PRODUCTION_VERIFICATION', 'APPROVED', 'PENDING_PRODUCTION', 'PENDING_QUALITY', $pe, 'PRODUCTION_ENGINEER');
    q($pdo, "UPDATE inspection_reports SET status = 'PENDING_QA' WHERE id = ?", [$sca]);
    $sign($sca, 'QUALITY_VERIFICATION', 'APPROVED', 'PENDING_QUALITY', 'PENDING_QA', $qe, 'QUALITY_ENGINEER');
    rejects($pdo, 'returning a report requires remarks', "INSERT INTO inspection_approvals (report_id, stage, action, from_status, to_status, user_id, role_id) VALUES (?, 'QA_APPROVAL', 'RETURNED', 'PENDING_QA', 'RETURNED', ?, ?)",
        [$sca, $qa, $roleId('QA_ADMIN')], '3819');
    rejects($pdo, 'QA approval needs approved_at / approved_by', "UPDATE inspection_reports SET status = 'QA_APPROVED' WHERE id = ?", [$sca], '3819');
    q($pdo, "UPDATE inspection_reports SET status = 'QA_APPROVED', approved_at = UTC_TIMESTAMP(), approved_by = ? WHERE id = ?", [$qa, $sca]);
    $sign($sca, 'QA_APPROVAL', 'APPROVED', 'PENDING_QA', 'QA_APPROVED', $qa, 'QA_ADMIN');
    check('signature history has 4 entries', (int) one($pdo, 'SELECT COUNT(*) FROM inspection_approvals WHERE report_id = ?', [$sca]) === 4);
    rejects($pdo, 'signatures cannot be edited', "UPDATE inspection_approvals SET remarks = 'x' WHERE report_id = ?", [$sca], 'QMS-LOCK');
    rejects($pdo, 'signatures cannot be deleted', 'DELETE FROM inspection_approvals WHERE report_id = ?', [$sca], 'QMS-LOCK');
    rejects($pdo, 'approved: cannot go back to DRAFT', "UPDATE inspection_reports SET status = 'DRAFT' WHERE id = ?", [$sca], 'QMS-LOCK');
    rejects($pdo, 'approved: not even a no-op touch', 'UPDATE inspection_reports SET lock_version = lock_version + 1 WHERE id = ?', [$sca], 'QMS-LOCK');
    rejects($pdo, 'reports can never be deleted', 'DELETE FROM inspection_reports WHERE id = ?', [$sca], 'QMS-LOCK');

    echo "\n== Revision of an approved report\n";
    $insertRevision = "INSERT INTO inspection_reports (client_uuid, report_no, revision_no, parent_report_id, revision_reason, is_current, report_type_id, template_id,
                       part_id, machine_id, shift_id, inspection_date, received_at, finish_at, operator_employee_id, created_by)
                       SELECT ?, report_no, revision_no + 1, id, ?, 0, report_type_id, template_id, part_id, machine_id, shift_id, inspection_date,
                              received_at, finish_at, operator_employee_id, ? FROM inspection_reports WHERE id = ?";
    rejects($pdo, 'a revision needs a reason', $insertRevision, [uuid(), null, $qa, $sca], '3819');
    q($pdo, $insertRevision, [uuid(), 'Weighing scale WS-01 found mis-zeroed; re-weighed sample', $qa, $sca]);
    $rev = (int) $pdo->lastInsertId();
    rejects($pdo, 'only one revision 1 per report number', $insertRevision, [uuid(), 'duplicate', $qa, $sca], '1062');
    q($pdo, 'INSERT INTO inspection_rounds (report_id, round_no, created_by) VALUES (?, 1, ?)', [$rev, $qa]);
    $revRound = (int) $pdo->lastInsertId();
    q($pdo, 'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id, lsl, usl, gauge_id, gauge_cal_due_date, gauge_cal_valid, result, remarks, created_by)
             SELECT ?, ?, template_parameter_id, lsl, usl, gauge_id, gauge_cal_due_date, gauge_cal_valid, result, remarks, ? FROM inspection_observations WHERE report_id = ?', [$rev, $revRound, $qa, $sca]);
    q($pdo, 'INSERT INTO inspection_readings (observation_id, reading_no, value_numeric, value_choice, value_text, value_date, value_time, result, created_by)
             SELECT n.id, rd.reading_no, rd.value_numeric, rd.value_choice, rd.value_text, rd.value_date, rd.value_time, rd.result, ?
             FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id
             JOIN inspection_observations n ON n.report_id = ? AND n.template_parameter_id = o.template_parameter_id
             WHERE o.report_id = ?', [$qa, $rev, $sca]);
    check('revision copied every reading', (int) one($pdo, 'SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ?', [$rev])
        === (int) one($pdo, 'SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ?', [$sca]));
    q($pdo, "UPDATE inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id SET rd.value_numeric = 335, rd.result = 'PASS' WHERE o.report_id = ? AND rd.value_numeric = 345", [$rev]);
    q($pdo, "UPDATE inspection_observations SET result = 'PASS' WHERE report_id = ? AND result = 'FAIL'", [$rev]);
    q($pdo, "UPDATE inspection_rounds SET status = 'SIGNED', operator_user_id = ?, operator_signed_at = UTC_TIMESTAMP() WHERE id = ?", [$qa, $revRound]);
    q($pdo, "UPDATE inspection_reports SET status = 'PENDING_PRODUCTION', overall_result = 'PASS', oos_count = 0, submitted_at = UTC_TIMESTAMP(), submitted_by = ? WHERE id = ?", [$qa, $rev]);
    q($pdo, "UPDATE inspection_reports SET status = 'PENDING_QUALITY' WHERE id = ?", [$rev]);
    q($pdo, "UPDATE inspection_reports SET status = 'PENDING_QA' WHERE id = ?", [$rev]);
    check('original stays the current record while the revision is in review',
        (int) one($pdo, 'SELECT is_current FROM inspection_reports WHERE id = ?', [$sca]) === 1);
    $pdo->beginTransaction();
    q($pdo, "UPDATE inspection_reports SET status = 'QA_APPROVED', approved_at = UTC_TIMESTAMP(), approved_by = ?, is_current = 1 WHERE id = ?", [$qe, $rev]);
    q($pdo, "UPDATE inspection_reports SET status = 'SUPERSEDED', is_current = 0 WHERE id = ?", [$sca]);
    $pdo->commit();
    check('exactly one current record per report number', (int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports WHERE report_no = ? AND is_current = 1', [$reportNo]) === 1);
    check('original revision kept intact (still holds the 345 g reading)', (int) one($pdo, 'SELECT COUNT(*) FROM inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id WHERE o.report_id = ? AND rd.value_numeric = 345', [$sca]) === 1);
    rejects($pdo, 'superseded report is final', "UPDATE inspection_reports SET status = 'QA_APPROVED', is_current = 1 WHERE id = ?", [$sca], 'QMS-LOCK');

    echo "\n== In-Process Inspection (shifts x inspection times)\n";
    q($pdo, $insertReport, [uuid(), $iprType, $tpl('IPR-BF1001'), $part, $cnc01, null, $date, null, null, null, $op]);
    $ipr = (int) $pdo->lastInsertId();
    foreach ([[1, $shiftA, '07:00:00'], [2, $shiftA, '09:00:00'], [3, $shiftB, '15:00:00']] as [$no, $shift, $time]) {
        q($pdo, 'INSERT INTO inspection_rounds (report_id, round_no, shift_id, inspection_time, lot_status, accepted_qty, rejected_qty, rework_qty, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$ipr, $no, $shift, $time, $no === 3 ? 'REWORK' : 'ACCEPTED', 400, $no === 3 ? 6 : 0, $no === 3 ? 12 : 0, $op]);
    }
    $iprRound1 = (int) one($pdo, 'SELECT id FROM inspection_rounds WHERE report_id = ? AND round_no = 1', [$ipr]);
    rejects($pdo, 'round numbers are unique per report', 'INSERT INTO inspection_rounds (report_id, round_no) VALUES (?, 1)', [$ipr], '1062');
    rejects($pdo, 'a signed round needs the operator signature', "UPDATE inspection_rounds SET status = 'SIGNED' WHERE id = ?", [$iprRound1], '3819');
    q($pdo, "UPDATE inspection_rounds SET status = 'SIGNED', operator_user_id = ?, operator_signed_at = UTC_TIMESTAMP() WHERE id = ?", [$op, $iprRound1]);
    rejects($pdo, 'operator cannot also verify the round as quality engineer', "UPDATE inspection_rounds SET status = 'VERIFIED', qe_user_id = ?, qe_verified_at = UTC_TIMESTAMP() WHERE id = ?", [$op, $iprRound1], '3819');
    q($pdo, "UPDATE inspection_rounds SET status = 'VERIFIED', qe_user_id = ?, qe_verified_at = UTC_TIMESTAMP() WHERE id = ?", [$qe, $iprRound1]);
    check('round verified by QE', one($pdo, 'SELECT status FROM inspection_rounds WHERE id = ?', [$iprRound1]) === 'VERIFIED');
    $iprParam = (int) one($pdo, 'SELECT tp.id FROM template_parameters tp JOIN template_sections s ON s.id = tp.section_id WHERE s.template_id = ? ORDER BY tp.sort_order LIMIT 1', [$tpl('IPR-BF1001')]);
    q($pdo, "INSERT INTO inspection_observations (report_id, round_id, template_parameter_id, result) VALUES (?, ?, ?, 'PASS')", [$ipr, $iprRound1, $iprParam]);
    rejects($pdo, 'observation cannot point at a round of another report', 'INSERT INTO inspection_observations (report_id, round_id, template_parameter_id) VALUES (?, ?, ?)', [$ipr, $scaRound, $iprParam], '1452');

    echo "\n== Audit trail, login log, calibration history\n";
    q($pdo, "INSERT INTO audit_logs (user_id, employee_id, username, action, module, record_id, record_ref, previous_value, new_value, ip_address, user_agent)
             VALUES (?, ?, 'demo.qa', 'APPROVE', 'inspection', ?, ?, JSON_OBJECT('status', 'PENDING_QA'), JSON_OBJECT('status', 'QA_APPROVED'), '10.0.0.5', 'verify')",
        [$qa, $empId('E4001'), $rev, $reportNo]);
    rejects($pdo, 'audit log cannot be edited', "UPDATE audit_logs SET action = 'X'", [], 'QMS-LOCK');
    rejects($pdo, 'audit log cannot be deleted', 'DELETE FROM audit_logs', [], 'QMS-LOCK');
    q($pdo, "INSERT INTO login_logs (user_id, username_attempted, event, ip_address) VALUES (?, 'demo.qa', 'LOGIN_SUCCESS', '10.0.0.5')", [$qa]);
    rejects($pdo, 'login log cannot be edited', "UPDATE login_logs SET event = 'LOGOUT'", [], 'QMS-LOCK');
    rejects($pdo, 'login log cannot be deleted', 'DELETE FROM login_logs', [], 'QMS-LOCK');
    $dvc002 = (int) one($pdo, "SELECT id FROM gauges WHERE gauge_code = 'DVC-002'");
    rejects($pdo, 'calibration due date must be after calibration date', "INSERT INTO gauge_calibrations (gauge_id, calibration_date, due_date, result, created_by) VALUES (?, '2026-10-03', '2026-10-03', 'ACCEPTED', ?)", [$dvc002, $qa], '3819');
    q($pdo, "INSERT INTO gauge_calibrations (gauge_id, calibration_date, due_date, result, certificate_no, created_by) VALUES (?, '2026-10-03', '2027-10-02', 'ACCEPTED', 'CAL/26/0042', ?)", [$dvc002, $qa]);
    rejects($pdo, 'calibration history cannot be edited', "UPDATE gauge_calibrations SET due_date = '2030-01-01'", [], 'QMS-LOCK');
    rejects($pdo, 'calibration history cannot be deleted', 'DELETE FROM gauge_calibrations', [], 'QMS-LOCK');

    echo "\n== Google Sheets outbox\n";
    $job   = (int) one($pdo, 'SELECT id FROM google_sheet_sync_logs WHERE report_id = ?', [$sca]);
    $claim = 'UPDATE google_sheet_sync_logs SET sync_status = \'PROCESSING\', locked_at = UTC_TIMESTAMP(), locked_by = \'verify:1\',
              attempt_count = attempt_count + 1, last_attempt_at = UTC_TIMESTAMP()
              WHERE id = ? AND sync_status = \'PENDING_SYNC\' AND next_attempt_at <= UTC_TIMESTAMP()';
    check('a worker can claim a pending job', q($pdo, $claim, [$job])->rowCount() === 1);
    check('a second worker cannot claim the same job', q($pdo, $claim, [$job])->rowCount() === 0);
    rejects($pdo, 'SYNCED requires synced_at', "UPDATE google_sheet_sync_logs SET sync_status = 'SYNCED' WHERE id = ?", [$job], '3819');
    q($pdo, "UPDATE google_sheet_sync_logs SET sync_status = 'PENDING_SYNC', locked_at = NULL, locked_by = NULL, error_message = 'HTTP 503 from sheets.googleapis.com',
             next_attempt_at = UTC_TIMESTAMP() + INTERVAL 2 MINUTE WHERE id = ?", [$job]);
    check('failed attempt goes back to PENDING_SYNC with back-off', q($pdo, $claim, [$job])->rowCount() === 0);
    rejects($pdo, 'sync job must reference an existing report', "INSERT INTO google_sheet_sync_logs (report_id, job_type, sheet_name) VALUES (999999, 'REPORT_UPSERT', 'Reports')", [], '1452');

    echo "\n== Dashboard and report queries\n";
    $kpi = q($pdo, <<<'SQL'
        SELECT COUNT(*) AS total,
               COALESCE(SUM(status NOT IN ('DRAFT','RETURNED') AND overall_result = 'PASS'), 0) AS passed,
               COALESCE(SUM(status NOT IN ('DRAFT','RETURNED') AND overall_result = 'FAIL'), 0) AS failed,
               COALESCE(SUM(status IN ('PENDING_PRODUCTION','PENDING_QUALITY','PENDING_QA')), 0) AS pending_approval,
               COALESCE(SUM(oos_count > 0), 0) AS out_of_spec
        FROM inspection_reports
        WHERE inspection_date = ? AND is_current = 1 AND status <> 'CANCELLED'
        SQL, [$date])->fetch(PDO::FETCH_ASSOC);
    check('KPI counts current revisions only (rev 1 + IPR draft = 2; superseded original excluded)', (int) $kpi['total'] === 2, json_encode($kpi));
    check('KPI passed = 1 (the corrected revision)', (int) $kpi['passed'] === 1, json_encode($kpi));
    $pendingSync = (int) one($pdo, "SELECT COUNT(DISTINCT report_id) FROM google_sheet_sync_logs WHERE sync_status IN ('PENDING_SYNC','PROCESSING','FAILED')");
    check('pending Google sync count', $pendingSync === 1);
    $oosRows = q($pdo, <<<'SQL'
        SELECT r.report_no, r.revision_no, tp.name, o.lsl, o.usl, rd.reading_no, rd.value_numeric, g.gauge_code
        FROM inspection_readings rd
        JOIN inspection_observations o ON o.id = rd.observation_id
        JOIN inspection_reports r ON r.id = o.report_id
        JOIN template_parameters tp ON tp.id = o.template_parameter_id
        LEFT JOIN gauges g ON g.id = o.gauge_id
        WHERE rd.result = 'FAIL' AND r.inspection_date BETWEEN ? AND ? AND r.status <> 'CANCELLED'
        ORDER BY r.inspection_date, r.report_no, r.revision_no
        SQL, ['2026-10-01', '2026-10-31'])->fetchAll(PDO::FETCH_ASSOC);
    check('out-of-specification report finds the 345 g reading on revision 0', count($oosRows) === 1 && (int) $oosRows[0]['revision_no'] === 0, json_encode($oosRows));
    $rejection = q($pdo, 'SELECT SUM(ro.rejected_qty) rej, SUM(ro.rework_qty) rw FROM inspection_rounds ro JOIN inspection_reports r ON r.id = ro.report_id WHERE r.inspection_date = ?', [$date])->fetch(PDO::FETCH_ASSOC);
    check('rejection report sums rejected / rework quantities', (int) $rejection['rej'] === 6 && (int) $rejection['rw'] === 12);
    $due = q($pdo, "SELECT gauge_code FROM gauges WHERE status = 'ACTIVE' AND calibration_due_date < ? ORDER BY calibration_due_date", [$date])->fetchAll(PDO::FETCH_COLUMN);
    check('calibration due report lists the expired gauge DVC-002', $due === ['DVC-002'], json_encode($due));
    $plan = q($pdo, "EXPLAIN SELECT id FROM inspection_reports WHERE machine_id = ? AND inspection_date BETWEEN '2026-10-01' AND '2026-10-31'", [$cnc01])->fetch(PDO::FETCH_ASSOC);
    check('machine-wise report uses idx_inspection_reports_machine_date', ($plan['key'] ?? '') === 'idx_inspection_reports_machine_date', (string) ($plan['key'] ?? 'none'));

    if ($withGrants) {
        echo "\n== Dedicated MySQL accounts (security/qms_db_users.sql)\n";
        $suffix = '_' . substr($db, -8);
        $names  = ['qms_app' => 'qms_app' . $suffix, 'qms_migrator' => 'qms_mig' . $suffix, 'qms_backup' => 'qms_bak' . $suffix];
        $sql    = (string) file_get_contents(ROOT . '/security/qms_db_users.sql');
        $sql    = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql    = preg_replace('/^CREATE DATABASE.*$/m', '', $sql);
        $sql    = str_replace(' qms.', " `{$db}`.", $sql);
        $sql    = strtr($sql, array_combine(array_map(static fn ($k) => "'{$k}'", array_keys($names)), array_map(static fn ($v) => "'{$v}'", $names)));
        $secret = [];
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $res = $admin->query($stmt);
            if (str_starts_with($stmt, 'CREATE USER')) {
                $row                 = $res->fetch(PDO::FETCH_NUM);
                $secret[$row[0]]     = $row[2];
                $created[]           = $row[0];
            }
        }
        check('accounts created with generated passwords', count($secret) === 3);

        $app = pdo($conn, $names['qms_app'], $secret[$names['qms_app']], $db);
        check('app: can read and append audit entries', (bool) q($app, "INSERT INTO audit_logs (action, module) VALUES ('LOGIN', 'auth')") && (int) one($app, 'SELECT COUNT(*) FROM audit_logs') >= 2);
        rejects($app, 'app: cannot UPDATE audit_logs (no privilege)', "UPDATE audit_logs SET action = 'X'", [], '1142');
        rejects($app, 'app: cannot DELETE audit_logs (no privilege)', 'DELETE FROM audit_logs', [], '1142');
        rejects($app, 'app: cannot TRUNCATE audit_logs', 'TRUNCATE TABLE audit_logs', [], '1142');
        rejects($app, 'app: cannot DELETE inspection reports', 'DELETE FROM inspection_reports WHERE id = ?', [$ipr], '1142');
        rejects($app, 'app: cannot edit calibration history', "UPDATE gauge_calibrations SET certificate_no = 'X'", [], '1142');
        rejects($app, 'app: cannot run DDL (CREATE TABLE)', 'CREATE TABLE hack (id INT)', [], '1142');
        rejects($app, 'app: cannot run DDL (DROP TABLE)', 'DROP TABLE audit_logs', [], '1142');
        rejects($app, 'app: cannot DELETE master data (machines are retired, not deleted)', 'DELETE FROM machines WHERE id = ?', [$cnc01], '1142');
        rejects($app, 'app: cannot UPDATE approval signatures (no privilege)', "UPDATE inspection_approvals SET remarks = 'x'", [], '1142');
        rejects($app, 'app: cannot UPDATE login_logs (no privilege)', "UPDATE login_logs SET event = 'LOGOUT'", [], '1142');
        rejects($app, 'app: cannot change permissions catalogue', "INSERT INTO permissions (code, module, description) VALUES ('x', 'x', 'x')", [], '1142');
        rejects($app, 'app: triggers still guard approved data it may UPDATE', "UPDATE inspection_readings rd JOIN inspection_observations o ON o.id = rd.observation_id SET rd.value_numeric = 1 WHERE o.report_id = ?", [$rev], 'QMS-LOCK');
        q($app, "UPDATE inspection_rounds SET remarks = 'ok while draft' WHERE id = ?", [$iprRound1]);
        check('app: can edit draft inspection rows', one($app, 'SELECT remarks FROM inspection_rounds WHERE id = ?', [$iprRound1]) === 'ok while draft');
        rejects($app, 'app: no access to other schemas', 'SELECT COUNT(*) FROM mysql.user', [], '1142');

        $bak = pdo($conn, $names['qms_backup'], $secret[$names['qms_backup']], $db);
        check('backup: can read', (int) one($bak, 'SELECT COUNT(*) FROM inspection_reports') >= 3);
        rejects($bak, 'backup: cannot write', "INSERT INTO audit_logs (action, module) VALUES ('X', 'X')", [], '1142');

        // With binary logging on (needed for point-in-time recovery) MySQL only lets an
        // account without SUPER create triggers when log_bin_trust_function_creators = 1.
        // That is a documented deployment prerequisite; verify both sides of it.
        $mig       = pdo($conn, $names['qms_migrator'], $secret[$names['qms_migrator']], $db);
        $binlog    = (int) one($admin, 'SELECT @@log_bin');
        $trustWas  = (int) one($admin, 'SELECT @@GLOBAL.log_bin_trust_function_creators');
        $probe     = 'CREATE TRIGGER trg_zz_probe BEFORE INSERT ON zz_definer_probe FOR EACH ROW SET NEW.id = NEW.id + 1';
        $mig->exec('CREATE TABLE zz_definer_probe (id INT)');
        try {
            if ($binlog === 1 && $trustWas === 0) {
                rejects($mig, 'migrator without log_bin_trust_function_creators cannot create triggers (prerequisite documented)', $probe, [], '1419');
                $admin->exec('SET GLOBAL log_bin_trust_function_creators = 1');
            }
            $mig->exec($probe);
            check('migrator creates triggers once log_bin_trust_function_creators = 1', true);
            $admin->exec("ALTER USER '{$names['qms_migrator']}'@'localhost' ACCOUNT LOCK");
            q($pdo, 'INSERT INTO zz_definer_probe VALUES (1)');
            check('triggers keep working while the migrator (their DEFINER) is locked', (int) one($pdo, 'SELECT id FROM zz_definer_probe') === 2);
        } finally {
            $admin->exec('SET GLOBAL log_bin_trust_function_creators = ' . $trustWas);
            $pdo->exec('DROP TABLE zz_definer_probe');
        }
    }
} catch (Throwable $e) {
    $failed[] = 'unexpected error';
    echo "\n  [ERROR] " . $e->getMessage() . "\n";
} finally {
    foreach ($created as $user) {
        $admin->exec("DROP USER IF EXISTS '{$user}'@'localhost'");
    }
    if (! $keep) {
        $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
    }
}

printf("\n%d passed, %d failed%s\n", $passed, count($failed), $keep ? " (database {$db} kept)" : '');
exit($failed === [] ? 0 : 1);
