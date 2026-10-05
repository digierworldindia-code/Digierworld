<?php
/**
 * Pure functions: decimals, PASS / FAIL, validation, numbering, CSV, sanitising.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/spec.php';
require_once QMS_ROOT . '/includes/numbering.php';
require_once QMS_ROOT . '/includes/reports.php';
require_once QMS_ROOT . '/includes/sheets_worker.php';

function test_decimal_comparison_is_exact(): void
{
    t_same(0, dec_compare('6.450', '6.45'));
    t_same(-1, dec_compare('6.399999', '6.4'));
    t_same(1, dec_compare('-0.5', '-0.51'));
    t_same('6.500000', dec_normalise('6.5'));
    t_same('-0.010000', dec_normalise('-0.01'));
    t_same(3, dec_places('1.250'));
    t_same(0, dec_places('12'));
    t_ok(! dec_is('1e3') && ! dec_is('1.2345678') && ! dec_is('abc') && dec_is('+12.5'), 'dec_is accepts only plain decimals');
}

function test_numeric_limits_are_inclusive(): void
{
    $p = ['observation_type' => 'NUMERIC', 'lsl' => '6.400000', 'usl' => '6.600000', 'decimal_places' => 3, 'date_rule' => 'NONE'];
    t_same('PASS', spec_evaluate($p, '6.40', '2026-10-05')['result'], 'LSL itself passes');
    t_same('PASS', spec_evaluate($p, '6.600', '2026-10-05')['result'], 'USL itself passes');
    t_same('FAIL', spec_evaluate($p, '6.399', '2026-10-05')['result'], 'just below LSL fails');
    t_same('FAIL', spec_evaluate($p, '6.601', '2026-10-05')['result'], 'just above USL fails');
    t_same('6.500000', spec_evaluate($p, '6,5', '2026-10-05')['value_numeric'], 'comma decimal accepted');
    t_throws(static fn () => spec_evaluate($p, '6.4001', '2026-10-05'), ValidationError::class, 422, 'at most 3 decimals');
    t_throws(static fn () => spec_evaluate($p, '6.4.1', '2026-10-05'), ValidationError::class, 422, 'Enter a number');
    t_same(null, spec_evaluate($p, '  ', '2026-10-05')['result'], 'empty value = no reading');
}

function test_one_sided_and_missing_limits(): void
{
    $min = ['observation_type' => 'NUMERIC', 'lsl' => '5', 'usl' => null, 'decimal_places' => 1];
    t_same('PASS', spec_evaluate($min, '99', '2026-10-05')['result']);
    t_same('FAIL', spec_evaluate($min, '4.9', '2026-10-05')['result']);
    $none = ['observation_type' => 'NUMERIC', 'lsl' => null, 'usl' => null, 'decimal_places' => 1];
    t_same('NOT_APPLICABLE', spec_evaluate($none, '3.2', '2026-10-05')['result'], 'no limits = record only');
    $pct = ['observation_type' => 'PERCENTAGE', 'lsl' => '5', 'usl' => '8', 'decimal_places' => 1];
    t_throws(static fn () => spec_evaluate($pct, '100.1', '2026-10-05'), ValidationError::class, 422, 'between 0 and 100');
}

function test_choices_dates_times_and_text(): void
{
    t_same('FAIL', spec_evaluate(['observation_type' => 'OK_NOT_OK'], 'NOT_OK', '2026-10-05')['result']);
    t_same('PASS', spec_evaluate(['observation_type' => 'VISUAL'], 'OK', '2026-10-05')['result']);
    t_same('FAIL', spec_evaluate(['observation_type' => 'GO_NO_GO'], 'NO_GO', '2026-10-05')['result']);
    t_throws(static fn () => spec_evaluate(['observation_type' => 'GO_NO_GO'], 'OK', '2026-10-05'), ValidationError::class);
    $pm = ['observation_type' => 'DATE', 'date_rule' => 'ON_OR_AFTER_INSPECTION_DATE'];
    t_same('PASS', spec_evaluate($pm, '2026-10-05', '2026-10-05')['result'], 'same day passes');
    t_same('FAIL', spec_evaluate($pm, '2026-10-04', '2026-10-05')['result'], 'PM overdue fails');
    t_throws(static fn () => spec_evaluate($pm, '2026-02-30', '2026-10-05'), ValidationError::class);
    t_same('08:30:00', spec_evaluate(['observation_type' => 'TIME'], '08:30', '2026-10-05')['value_time']);
    t_same('abc', spec_evaluate(['observation_type' => 'TEXT'], "a\x07bc", '2026-10-05')['value_text'], 'control characters removed');
}

function test_observation_and_report_results(): void
{
    t_same('FAIL', spec_aggregate([1 => 'PASS', 2 => 'FAIL'], 2, true));
    t_same('INCOMPLETE', spec_aggregate([1 => 'PASS', 2 => null], 2, true));
    t_same('PASS', spec_aggregate([1 => 'PASS', 2 => null], 2, false), 'optional with one reading');
    t_same('NOT_APPLICABLE', spec_aggregate([1 => null], 1, false));
    t_same('NOT_APPLICABLE', spec_aggregate([1 => 'NOT_APPLICABLE'], 1, true));
    t_same('FAIL', spec_overall(['PASS', 'INCOMPLETE', 'FAIL']));
    t_same('INCOMPLETE', spec_overall(['PASS', 'INCOMPLETE']));
    t_same('PASS', spec_overall(['PASS', 'NOT_APPLICABLE']));
}

function test_validator_rules(): void
{
    $rules = [
        'code'  => ['label' => 'Code', 'rules' => 'required|max_length[5]|regex_match[/^(AB|CD)[0-9]+$/]'],
        'qty'   => ['label' => 'Qty', 'rules' => 'permit_empty|is_natural_no_zero|less_than_equal_to[100]'],
        'start' => ['label' => 'Start', 'rules' => 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d$/]'],
        'end'   => ['label' => 'End', 'rules' => 'required|differs[start]'],
        'day'   => ['label' => 'Day', 'rules' => 'permit_empty|valid_date[Y-m-d]'],
        'kind'  => ['label' => 'Kind', 'rules' => 'required|in_list[A, B]'],
    ];
    t_same([], validate(['code' => 'AB12', 'qty' => '', 'start' => '08:00', 'end' => '16:00', 'day' => '2026-02-28', 'kind' => 'B'], $rules));
    $errors = validate(['code' => 'XY1', 'qty' => '0', 'start' => '24:00', 'end' => '24:00', 'day' => '2026-02-30', 'kind' => 'C'], $rules);
    t_same(['code', 'qty', 'start', 'end', 'day', 'kind'], array_keys($errors));
    t_same('The End field must differ from the Start field.', $errors['end']);
    t_same('The Qty field is invalid.', validate(['qty' => ['1']], ['qty' => ['label' => 'Qty', 'rules' => 'permit_empty|integer']])['qty']);
    t_throws(static fn () => validate_or_fail([], ['x' => 'required']), ValidationError::class, 422);
}

function test_report_numbers_are_formatted_and_gap_free(): void
{
    t_same('SCA-2026-10-000042', number_format_no('SCA', '2026-10-05', 42));
    t_same('2026-10', number_period_key('2026-10-31'));
    $type  = db_row("SELECT * FROM report_types WHERE code = 'SCA'");
    $first = db_transaction(static fn () => number_allocate($type, '2026-11-02'));
    $next  = db_transaction(static fn () => number_allocate($type, '2026-11-30'));
    t_same('SCA-2026-11-000001', $first);
    t_same('SCA-2026-11-000002', $next);
    t_same('SCA-2026-12-000001', db_transaction(static fn () => number_allocate($type, '2026-12-01')), 'monthly reset');
    // A rolled-back submission gives its number back.
    t_throws(static fn () => db_transaction(static function () use ($type): void {
        number_allocate($type, '2026-11-15');
        throw new RuntimeException('rollback');
    }), RuntimeException::class);
    t_same('SCA-2026-11-000003', db_transaction(static fn () => number_allocate($type, '2026-11-16')));
}

function test_csv_cells_cannot_become_formulas(): void
{
    t_same("'=HYPERLINK(\"x\")", csv_neutralise('=HYPERLINK("x")'));
    t_same("'@SUM(A1)", csv_neutralise('@SUM(A1)'));
    t_same("'+1+1", csv_neutralise('+1+1'));
    t_same('-12.5', csv_neutralise('-12.5'), 'negative numbers stay numbers');
    $csv = csv_build(['a' => ['label' => 'A', 'type' => 'text'], 'b' => ['label' => 'B', 'type' => 'result']], [['a' => '=cmd', 'b' => 'FAIL']]);
    t_ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=cmd,\"OUT OF SPEC\""), 'BOM, neutralised cell and result text');
}

function test_google_errors_are_sanitised(): void
{
    $e = new RuntimeException('Bearer ya29.a0AfH6SMBxyz failed; "access_token":"secret123" -----BEGIN PRIVATE KEY-----abc-----END PRIVATE KEY-----', 401);
    $m = gs_sanitize($e);
    t_ok(! str_contains($m, 'ya29.a0') && ! str_contains($m, 'secret123') && ! str_contains($m, 'abc-----'), 'no token or key in: ' . $m);
    t_ok(str_starts_with($m, 'HTTP 401: '), 'status prefix');
    t_same('403 PERMISSION_DENIED: The caller does not have permission',
        gs_sanitize(new RuntimeException('{"error":{"code":403,"status":"PERMISSION_DENIED","message":"The caller does not have permission"}}', 403)));
}

function test_sync_backoff_grows_and_is_capped(): void
{
    t_same(60, sheet_backoff_seconds(1, 0.0));
    t_same(480, sheet_backoff_seconds(4, 0.0));
    t_same(21600, sheet_backoff_seconds(20, 0.0), 'capped at 6 hours');
    t_same(30, sheet_backoff_seconds(1, -0.9), 'at least 30 s');
}

function test_escaping_and_urls(): void
{
    t_same('&lt;script&gt;&quot;x&quot;&#039;', e('<script>"x"\''));
    t_same('', e(null));
    t_same('http://localhost/gauge.php?id=5', url('gauge.php?id=5'));
    t_same('6.450', qms_decimal('6.450000', 3));
    t_same('-0.10', qms_decimal('-0.1', 2));
    t_same('6.40 – 6.60 mm', spec_limits('6.4', '6.6', 2, 'mm'));
    t_same('≥ 5.0', spec_limits('5', null, 1));
    t_ok(is_uuid_v4(uuid_v4()) && ! is_uuid_v4('123'), 'UUID v4');
}
