<?php
/**
 * Server-side PASS / FAIL decision. The browser shows the same verdict while
 * typing, but only the result computed here is stored.
 *
 * - NUMERIC / PERCENTAGE: LSL <= value <= USL, limits inclusive, exact decimal
 *   comparison; one missing limit = one-sided check; no limits = N/A.
 * - OK / NOT OK and VISUAL: OK passes. GO / NO GO: GO passes.
 * - DATE with rule ON_OR_AFTER_INSPECTION_DATE: value >= inspection date.
 * - TEXT, TIME (and DATE without rule): recorded, NOT_APPLICABLE.
 */
defined('QMS') || exit;

require_once QMS_ROOT . '/includes/decimal.php';

/**
 * Validates one raw reading and returns the typed columns plus the result.
 * LSL / USL come from $param (the observation snapshot), never from the browser.
 *
 * @return array{value_numeric: ?string, value_choice: ?string, value_text: ?string, value_date: ?string, value_time: ?string, result: ?string}
 */
function spec_evaluate(array $param, ?string $raw, string $inspectionDate): array
{
    $out = ['value_numeric' => null, 'value_choice' => null, 'value_text' => null, 'value_date' => null, 'value_time' => null, 'result' => null];
    $raw = $raw === null ? '' : trim($raw);
    if ($raw === '') {
        return $out;
    }
    $bad = static function (string $message): never {
        throw new ValidationError(['value' => $message], $message);
    };

    switch ((string) $param['observation_type']) {
        case 'NUMERIC':
        case 'PERCENTAGE':
            $value = str_contains($raw, '.') ? $raw : str_replace(',', '.', $raw);
            if (! dec_is($value)) {
                $bad('Enter a number, for example 6.45.');
            }
            $places = (int) ($param['decimal_places'] ?? 3);
            if (dec_places($value) > $places) {
                $bad($places === 0 ? 'Enter a whole number.' : "Use at most {$places} decimals.");
            }
            if ($param['observation_type'] === 'PERCENTAGE' && (dec_compare($value, '0') < 0 || dec_compare($value, '100') > 0)) {
                $bad('A percentage must be between 0 and 100.');
            }
            $out['value_numeric'] = dec_normalise($value);
            $out['result']        = spec_numeric_result($value, $param['lsl'] ?? null, $param['usl'] ?? null);
            break;

        case 'OK_NOT_OK':
        case 'VISUAL':
            if (! in_array($raw, ['OK', 'NOT_OK'], true)) {
                $bad('Choose OK or NOT OK.');
            }
            $out['value_choice'] = $raw;
            $out['result']       = $raw === 'OK' ? 'PASS' : 'FAIL';
            break;

        case 'GO_NO_GO':
            if (! in_array($raw, ['GO', 'NO_GO'], true)) {
                $bad('Choose GO or NO GO.');
            }
            $out['value_choice'] = $raw;
            $out['result']       = $raw === 'GO' ? 'PASS' : 'FAIL';
            break;

        case 'DATE':
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date === false || $date->format('Y-m-d') !== $raw) {
                $bad('Enter a valid date.');
            }
            $out['value_date'] = $raw;
            $out['result']     = ($param['date_rule'] ?? 'NONE') === 'ON_OR_AFTER_INSPECTION_DATE'
                ? ($raw >= $inspectionDate ? 'PASS' : 'FAIL')
                : 'NOT_APPLICABLE';
            break;

        case 'TIME':
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) {
                $bad('Enter a time as HH:MM.');
            }
            $out['value_time'] = strlen($raw) === 5 ? $raw . ':00' : $raw;
            $out['result']     = 'NOT_APPLICABLE';
            break;

        case 'TEXT':
            $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
            if (mb_strlen($text) > 255) {
                $bad('Text is limited to 255 characters.');
            }
            $out['value_text'] = $text;
            $out['result']     = 'NOT_APPLICABLE';
            break;

        default:
            $bad('Unknown observation type.');
    }

    return $out;
}

/**
 * Result of an already stored reading (re-check before submission). Null when empty
 * or when the stored column does not match the parameter type.
 */
function spec_result_for_stored(array $param, array $reading, ?string $lsl, ?string $usl, string $inspectionDate): ?string
{
    return match ((string) $param['observation_type']) {
        'NUMERIC', 'PERCENTAGE' => $reading['value_numeric'] === null ? null : spec_numeric_result((string) $reading['value_numeric'], $lsl, $usl),
        'OK_NOT_OK', 'VISUAL'   => match ($reading['value_choice']) { 'OK' => 'PASS', 'NOT_OK' => 'FAIL', default => null },
        'GO_NO_GO'              => match ($reading['value_choice']) { 'GO' => 'PASS', 'NO_GO' => 'FAIL', default => null },
        'DATE'                  => $reading['value_date'] === null ? null
            : (($param['date_rule'] ?? 'NONE') === 'ON_OR_AFTER_INSPECTION_DATE' ? ((string) $reading['value_date'] >= $inspectionDate ? 'PASS' : 'FAIL') : 'NOT_APPLICABLE'),
        'TIME'                  => $reading['value_time'] === null ? null : 'NOT_APPLICABLE',
        'TEXT'                  => $reading['value_text'] === null ? null : 'NOT_APPLICABLE',
        default                 => null,
    };
}

function spec_numeric_result(string $value, ?string $lsl, ?string $usl): string
{
    $hasLsl = $lsl !== null && $lsl !== '';
    $hasUsl = $usl !== null && $usl !== '';
    if (! $hasLsl && ! $hasUsl) {
        return 'NOT_APPLICABLE';
    }
    if ($hasLsl && dec_compare($value, $lsl) < 0) {
        return 'FAIL';
    }
    if ($hasUsl && dec_compare($value, $usl) > 0) {
        return 'FAIL';
    }

    return 'PASS';
}

/**
 * Result of one observation from its readings (index = reading no, null = empty).
 *
 * @param array<int, ?string> $readingResults
 */
function spec_aggregate(array $readingResults, int $expectedCount, bool $mandatory): string
{
    $filled  = array_filter($readingResults, static fn ($r): bool => $r !== null);
    $missing = $expectedCount - count($filled);

    if (in_array('FAIL', $filled, true)) {
        return 'FAIL';
    }
    if ($mandatory && $missing > 0) {
        return 'INCOMPLETE';
    }
    if ($filled === [] || array_diff($filled, ['NOT_APPLICABLE']) === []) {
        return 'NOT_APPLICABLE';
    }

    return 'PASS';
}

/** Report verdict from the observation results. */
function spec_overall(array $observationResults): string
{
    if (in_array('FAIL', $observationResults, true)) {
        return 'FAIL';
    }
    if (in_array('INCOMPLETE', $observationResults, true)) {
        return 'INCOMPLETE';
    }

    return 'PASS';
}
