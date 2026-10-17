<?php

namespace App\Services\Hr;

use CodeIgniter\Database\BaseConnection;

/**
 * Company calendar: public holidays and weekly-off rules
 * (e.g. every Sunday, or 2nd and 4th Saturday). Used by leave day counts,
 * attendance pre-fill and payroll proration.
 */
class HrCalendar
{
    /** @var array<string, array{name: string, optional: bool}>|null */
    private ?array $holidays = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rules = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function reset(): void
    {
        $this->holidays = null;
        $this->rules    = null;
    }

    /** @return array<string, array{name: string, optional: bool}> date => holiday */
    private function holidays(): array
    {
        if ($this->holidays === null) {
            $this->holidays = [];
            foreach ($this->db->table('holidays')->select('holiday_date, name, is_optional')->get()->getResultArray() as $h) {
                $this->holidays[$h['holiday_date']] = ['name' => $h['name'], 'optional' => (bool) $h['is_optional']];
            }
        }

        return $this->holidays;
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        return $this->rules ??= $this->db->table('weekly_off_rules')->where('is_active', 1)->get()->getResultArray();
    }

    /** Mandatory (non-optional) holiday name on that date, or null. */
    public function holiday(string $date): ?string
    {
        $h = $this->holidays()[$date] ?? null;

        return $h !== null && ! $h['optional'] ? $h['name'] : null;
    }

    public function isWeekOff(string $date): bool
    {
        $ts      = strtotime($date);
        $weekday = (int) date('w', $ts);
        $nth     = intdiv((int) date('j', $ts) - 1, 7) + 1;
        foreach ($this->rules() as $r) {
            if ((int) $r['weekday'] !== $weekday || $r['effective_from'] > $date) {
                continue;
            }
            $weeks = trim((string) $r['week_numbers']);
            if ($weeks === '' || in_array($nth, array_map('intval', explode(',', $weeks)), true)) {
                return true;
            }
        }

        return false;
    }

    /** 'holiday' | 'week_off' | null (a working day). Holidays win over week-offs. */
    public function dayKind(string $date): ?string
    {
        if ($this->holiday($date) !== null) {
            return 'holiday';
        }

        return $this->isWeekOff($date) ? 'week_off' : null;
    }

    /** @return list<string> Y-m-d dates from..to inclusive */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
            $out[] = date('Y-m-d', $d);
        }

        return $out;
    }

    public function workingDays(string $from, string $to): int
    {
        return count(array_filter(self::dates($from, $to), fn ($d) => $this->dayKind($d) === null));
    }

    /** @return list<string> human description of the weekly-off policy */
    public function describeRules(): array
    {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $ord   = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];

        return array_map(static function ($r) use ($names, $ord) {
            $weeks = trim((string) $r['week_numbers']);

            return ($weeks === '' ? 'Every ' : implode(' & ', array_map(static fn ($n) => $ord[(int) $n] ?? $n, explode(',', $weeks))) . ' ') . $names[(int) $r['weekday']];
        }, $this->rules());
    }
}
