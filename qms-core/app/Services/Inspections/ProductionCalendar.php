<?php

namespace App\Services\Inspections;

use App\Services\Settings\SettingsService;
use App\Services\Support\Clock;
use App\Core\Database;
use DateTimeImmutable;

/**
 * Shift and production-date logic. A shift whose end time is before its start
 * time crosses midnight; its hours after midnight belong to the production date
 * on which the shift started (02:00 on 4 Oct = 3 Oct, shift C).
 */
class ProductionCalendar
{
    /** @var list<array<string, mixed>>|null */
    private ?array $shifts = null;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return list<array<string, mixed>> active shifts in display order
     */
    public function shifts(): array
    {
        return $this->shifts ??= $this->db->table('shifts')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
    }

    /**
     * @return array{date: string, shift: array<string, mixed>|null}
     */
    public function current(): array
    {
        return $this->resolve($this->clock->plantNow());
    }

    /**
     * @return array{date: string, shift: array<string, mixed>|null}
     */
    public function resolve(DateTimeImmutable $plantTime): array
    {
        $time = $plantTime->format('H:i:s');

        foreach ($this->shifts() as $shift) {
            $start = $shift['start_time'];
            $end   = $shift['end_time'];

            if ($start < $end) {
                if ($time >= $start && $time < $end) {
                    return ['date' => $plantTime->format('Y-m-d'), 'shift' => $shift];
                }
            } elseif ($time >= $start || $time < $end) {
                $date = $time < $end ? $plantTime->modify('-1 day') : $plantTime;

                return ['date' => $date->format('Y-m-d'), 'shift' => $shift];
            }
        }

        return ['date' => $plantTime->format('Y-m-d'), 'shift' => null];
    }

    /**
     * Production dates a new report may use: today's production date and up to
     * inspection.max_backdate_days before it. Future dates are never allowed.
     *
     * @return list<string>
     */
    public function allowedDates(): array
    {
        $current = new DateTimeImmutable($this->current()['date']);
        $days    = max(0, $this->settings->int('inspection.max_backdate_days', 1));
        $dates   = [];
        for ($i = 0; $i <= $days; $i++) {
            $dates[] = $current->modify("-{$i} day")->format('Y-m-d');
        }

        return $dates;
    }

    public function isAllowedDate(string $date): bool
    {
        return in_array($date, $this->allowedDates(), true);
    }

    /**
     * Converts a plant-local time of day on a production date to a UTC DATETIME,
     * honouring night shifts (times before the shift start belong to the next day).
     *
     * @param array<string, mixed>|null $shift
     */
    public function toUtc(string $productionDate, string $time, ?array $shift): string
    {
        $time = strlen($time) === 5 ? $time . ':00' : $time;
        $day  = new DateTimeImmutable($productionDate);
        if ($shift !== null && $shift['start_time'] > $shift['end_time'] && $time < $shift['start_time']) {
            $day = $day->modify('+1 day');
        }

        return $this->clock->plantToUtc($day->format('Y-m-d') . ' ' . $time);
    }
}
