<?php

namespace App\Services\Inspections;

use App\Enums\ObservationType;
use App\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Server-side PASS / FAIL decision (docs/architecture/04-database-schema.md §4.5).
 *
 * - NUMERIC / PERCENTAGE: LSL <= value <= USL, limits inclusive, exact decimal
 *   comparison; a missing limit makes the check one-sided; no limits = N/A.
 * - OK / NOT OK and VISUAL: OK passes. GO / NO GO: GO passes.
 * - DATE with rule ON_OR_AFTER_INSPECTION_DATE: value >= inspection date.
 * - TEXT, TIME (and DATE without rule): recorded, NOT_APPLICABLE.
 *
 * The browser shows the same verdict instantly, but only this result is stored.
 */
class SpecEvaluator
{
    /**
     * @param array<string, mixed> $param needs observation_type, lsl, usl, decimal_places, date_rule
     *
     * @return array{value_numeric: string|null, value_choice: string|null, value_text: string|null,
     *               value_date: string|null, value_time: string|null, result: string|null}
     *
     * @throws ValidationException with a message for the inspector
     */
    public function evaluate(array $param, ?string $raw, string $inspectionDate): array
    {
        $out = ['value_numeric' => null, 'value_choice' => null, 'value_text' => null, 'value_date' => null, 'value_time' => null, 'result' => null];
        $raw = $raw === null ? '' : trim($raw);
        if ($raw === '') {
            return $out;
        }

        $type = ObservationType::from((string) $param['observation_type']);

        switch ($type) {
            case ObservationType::Numeric:
            case ObservationType::Percentage:
                $value = str_contains($raw, '.') ? $raw : str_replace(',', '.', $raw);
                if (! DecimalComparator::isDecimal($value)) {
                    throw ValidationException::single('value', 'Enter a number, for example 6.45.');
                }
                $places = (int) ($param['decimal_places'] ?? 3);
                if (DecimalComparator::decimals($value) > $places) {
                    throw ValidationException::single('value', $places === 0 ? 'Enter a whole number.' : "Use at most {$places} decimals.");
                }
                if ($type === ObservationType::Percentage
                    && (DecimalComparator::compare($value, '0') < 0 || DecimalComparator::compare($value, '100') > 0)) {
                    throw ValidationException::single('value', 'A percentage must be between 0 and 100.');
                }
                $out['value_numeric'] = DecimalComparator::normalise($value);
                $out['result']        = $this->numericResult($value, $param['lsl'] ?? null, $param['usl'] ?? null);
                break;

            case ObservationType::OkNotOk:
            case ObservationType::Visual:
                if (! in_array($raw, ['OK', 'NOT_OK'], true)) {
                    throw ValidationException::single('value', 'Choose OK or NOT OK.');
                }
                $out['value_choice'] = $raw;
                $out['result']       = $raw === 'OK' ? 'PASS' : 'FAIL';
                break;

            case ObservationType::GoNoGo:
                if (! in_array($raw, ['GO', 'NO_GO'], true)) {
                    throw ValidationException::single('value', 'Choose GO or NO GO.');
                }
                $out['value_choice'] = $raw;
                $out['result']       = $raw === 'GO' ? 'PASS' : 'FAIL';
                break;

            case ObservationType::Date:
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($date === false || $date->format('Y-m-d') !== $raw) {
                    throw ValidationException::single('value', 'Enter a valid date.');
                }
                $out['value_date'] = $raw;
                $out['result']     = ($param['date_rule'] ?? 'NONE') === 'ON_OR_AFTER_INSPECTION_DATE'
                    ? ($raw >= $inspectionDate ? 'PASS' : 'FAIL')
                    : 'NOT_APPLICABLE';
                break;

            case ObservationType::Time:
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) {
                    throw ValidationException::single('value', 'Enter a time as HH:MM.');
                }
                $out['value_time'] = strlen($raw) === 5 ? $raw . ':00' : $raw;
                $out['result']     = 'NOT_APPLICABLE';
                break;

            case ObservationType::Text:
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
                if (mb_strlen($text) > 255) {
                    throw ValidationException::single('value', 'Text is limited to 255 characters.');
                }
                $out['value_text'] = $text;
                $out['result']     = 'NOT_APPLICABLE';
                break;
        }

        return $out;
    }

    /**
     * Result of an already stored reading (typed columns), used to re-check a
     * report before submission. Null when the reading is empty.
     *
     * @param array<string, mixed> $param   observation_type, date_rule
     * @param array<string, mixed> $reading value_* columns
     */
    public function resultForStored(array $param, array $reading, ?string $lsl, ?string $usl, string $inspectionDate): ?string
    {
        $type = ObservationType::from((string) $param['observation_type']);

        return match ($type) {
            ObservationType::Numeric, ObservationType::Percentage => $reading['value_numeric'] === null ? null
                : $this->numericResult((string) $reading['value_numeric'], $lsl, $usl),
            ObservationType::OkNotOk, ObservationType::Visual => match ($reading['value_choice']) {
                'OK' => 'PASS', 'NOT_OK' => 'FAIL', default => null,
            },
            ObservationType::GoNoGo => match ($reading['value_choice']) {
                'GO' => 'PASS', 'NO_GO' => 'FAIL', default => null,
            },
            ObservationType::Date => $reading['value_date'] === null ? null
                : (($param['date_rule'] ?? 'NONE') === 'ON_OR_AFTER_INSPECTION_DATE'
                    ? ((string) $reading['value_date'] >= $inspectionDate ? 'PASS' : 'FAIL') : 'NOT_APPLICABLE'),
            ObservationType::Time => $reading['value_time'] === null ? null : 'NOT_APPLICABLE',
            ObservationType::Text => $reading['value_text'] === null ? null : 'NOT_APPLICABLE',
        };
    }

    public function numericResult(string $value, ?string $lsl, ?string $usl): string
    {
        if (($lsl === null || $lsl === '') && ($usl === null || $usl === '')) {
            return 'NOT_APPLICABLE';
        }
        if ($lsl !== null && $lsl !== '' && DecimalComparator::compare($value, $lsl) < 0) {
            return 'FAIL';
        }
        if ($usl !== null && $usl !== '' && DecimalComparator::compare($value, $usl) > 0) {
            return 'FAIL';
        }

        return 'PASS';
    }

    /**
     * Result of one observation from its readings (index = reading_no, null = empty).
     *
     * @param array<int, string|null> $readingResults
     */
    public function aggregate(array $readingResults, int $expectedCount, bool $mandatory): string
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

    /**
     * Report verdict from observation results.
     *
     * @param list<string> $observationResults
     */
    public function overall(array $observationResults): string
    {
        if (in_array('FAIL', $observationResults, true)) {
            return 'FAIL';
        }
        if (in_array('INCOMPLETE', $observationResults, true)) {
            return 'INCOMPLETE';
        }

        return 'PASS';
    }
}
