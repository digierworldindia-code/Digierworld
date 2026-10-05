<?php
/**
 * Time. Everything is stored in UTC; the plant time zone (Settings → Regional)
 * is used for display, for "today" and for shift detection.
 */
defined('QMS') || exit;

function now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

/** UTC now as MySQL DATETIME. */
function now_str(): string
{
    return now_utc()->format('Y-m-d H:i:s');
}

function plant_zone(): DateTimeZone
{
    static $zone = null;
    if ($zone === null) {
        try {
            $zone = new DateTimeZone(setting_str('regional.timezone', 'Asia/Kolkata') ?: 'UTC');
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }
    }

    return $zone;
}

function plant_now(): DateTimeImmutable
{
    return now_utc()->setTimezone(plant_zone());
}

/** UTC DATETIME string → plant time text ('' for null). */
function to_plant(?string $utc, string $format = 'd-m-Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(plant_zone())->format($format);
    } catch (Throwable) {
        return '';
    }
}

/** Plant-local 'Y-m-d H:i[:s]' → UTC DATETIME string. */
function plant_to_utc(string $local): string
{
    return (new DateTimeImmutable($local, plant_zone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------- shifts and production date

/** Active shifts in display order. */
function shifts_active(): array
{
    static $shifts = null;

    return $shifts ??= db_all('SELECT * FROM shifts WHERE is_active = 1 ORDER BY sort_order, id');
}

/**
 * Production date and shift of a plant time. A shift ending before it starts
 * (22:00–06:00) crosses midnight: its hours after midnight belong to the date
 * on which it started.
 *
 * @return array{date: string, shift: array|null}
 */
function production_resolve(DateTimeImmutable $plantTime): array
{
    $time = $plantTime->format('H:i:s');
    foreach (shifts_active() as $shift) {
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

/** @return array{date: string, shift: array|null} today's production date and current shift */
function production_current(): array
{
    return production_resolve(plant_now());
}

/** Production dates a new report may use: today and up to inspection.max_backdate_days before. */
function production_allowed_dates(): array
{
    $current = new DateTimeImmutable(production_current()['date']);
    $days    = max(0, setting_int('inspection.max_backdate_days', 1));
    $dates   = [];
    for ($i = 0; $i <= $days; $i++) {
        $dates[] = $current->modify("-{$i} day")->format('Y-m-d');
    }

    return $dates;
}

/**
 * Plant time of day on a production date → UTC DATETIME (night-shift times
 * before the shift start belong to the next calendar day).
 */
function production_to_utc(string $date, string $time, ?array $shift): string
{
    $time = strlen($time) === 5 ? $time . ':00' : $time;
    $day  = new DateTimeImmutable($date);
    if ($shift !== null && $shift['start_time'] > $shift['end_time'] && $time < $shift['start_time']) {
        $day = $day->modify('+1 day');
    }

    return plant_to_utc($day->format('Y-m-d') . ' ' . $time);
}
