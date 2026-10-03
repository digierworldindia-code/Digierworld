<?php

/**
 * View helpers. Every helper that returns HTML escapes its input.
 */

use App\Enums\ReportStatus;

if (! function_exists('qms_user')) {
    /** @return array<string, mixed>|null */
    function qms_user(): ?array
    {
        return service('auth')->user();
    }
}

if (! function_exists('can')) {
    function can(string ...$permissions): bool
    {
        return service('authorization')->can(...$permissions);
    }
}

if (! function_exists('qms_setting')) {
    function qms_setting(string $key, mixed $default = null): mixed
    {
        return service('settings')->get($key, $default);
    }
}

if (! function_exists('plant_dt')) {
    /** UTC DATETIME → plant time display. */
    function plant_dt(?string $utc, string $format = 'd-m-Y H:i'): string
    {
        return service('clock')->toPlant($utc, $format);
    }
}

if (! function_exists('plant_date')) {
    /** Y-m-d → configured display format. */
    function plant_date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $format = (string) qms_setting('regional.date_format', 'd-m-Y');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $parsed === false ? esc($date) : $parsed->format($format);
    }
}

if (! function_exists('qms_icon')) {
    function qms_icon(string $name, string $extra = ''): string
    {
        return '<i class="bi ' . esc($name, 'attr') . ($extra !== '' ? ' ' . esc($extra, 'attr') : '') . '" aria-hidden="true"></i>';
    }
}

if (! function_exists('status_badge')) {
    /** Report status badge: colour + icon + text (never colour alone). */
    function status_badge(string $status): string
    {
        $enum = ReportStatus::tryFrom($status);
        if ($enum === null) {
            return '<span class="qms-badge qms-badge--muted">' . esc($status) . '</span>';
        }

        return '<span class="qms-badge qms-badge--' . $enum->tone() . '">' . qms_icon($enum->icon()) . ' ' . esc($enum->label()) . '</span>';
    }
}

if (! function_exists('result_badge')) {
    function result_badge(?string $result): string
    {
        return match ($result) {
            'PASS'           => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle-fill') . ' PASS</span>',
            'FAIL'           => '<span class="qms-badge qms-badge--fail">' . qms_icon('bi-x-octagon-fill') . ' OUT OF SPEC</span>',
            'NOT_APPLICABLE' => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-dash-circle') . ' N/A</span>',
            'INCOMPLETE'     => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-circle') . ' INCOMPLETE</span>',
            default          => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-circle') . ' NOT FILLED</span>',
        };
    }
}

if (! function_exists('sync_badge')) {
    function sync_badge(?string $status): string
    {
        return match ($status) {
            'SYNCED'                     => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-cloud-check') . ' SYNCED</span>',
            'FAILED'                     => '<span class="qms-badge qms-badge--fail">' . qms_icon('bi-cloud-slash') . ' SYNC FAILED</span>',
            'PENDING_SYNC', 'PROCESSING' => '<span class="qms-badge qms-badge--pending">' . qms_icon('bi-cloud-arrow-up') . ' SYNC PENDING</span>',
            default                      => '',
        };
    }
}

if (! function_exists('active_badge')) {
    function active_badge(mixed $active): string
    {
        return (int) $active === 1
            ? '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-check-circle') . ' Active</span>'
            : '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-archive') . ' Retired</span>';
    }
}

if (! function_exists('qms_decimal')) {
    /**
     * Formats a DECIMAL string with a fixed number of places without float conversion.
     * Inputs are validated to have at most $places significant decimals, so extra
     * digits are always zeros.
     */
    function qms_decimal(?string $value, int $places): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $negative = str_starts_with($value, '-');
        $value    = ltrim($value, '-+');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $int  = ltrim($int, '0');
        $int  = $int === '' ? '0' : $int;
        $frac = substr(str_pad($frac, $places, '0'), 0, $places);
        $out  = $places > 0 ? $int . '.' . $frac : $int;

        return ($negative && trim($out, '0.') !== '' ? '-' : '') . $out;
    }
}

if (! function_exists('field_invalid')) {
    /** @param array<string, string> $errors */
    function field_invalid(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' is-invalid' : '';
    }
}

if (! function_exists('field_error')) {
    /** @param array<string, string> $errors */
    function field_error(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<div class="invalid-feedback d-block" id="err-' . esc($field, 'attr') . '">' . esc($errors[$field]) . '</div>'
            : '';
    }
}

if (! function_exists('qms_asset')) {
    /** Versioned asset URL (cache busting by file modification time). */
    function qms_asset(string $path): string
    {
        $file    = FCPATH . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return base_url(ltrim($path, '/')) . '?v=' . $version;
    }
}

if (! function_exists('reading_text')) {
    /**
     * Display text of a stored reading (screens and printouts).
     *
     * @param array<string, mixed>      $param   template parameter (decimal_places)
     * @param array<string, mixed>|null $reading inspection_readings row
     */
    function reading_text(array $param, ?array $reading): string
    {
        if ($reading === null) {
            return '';
        }

        return match (true) {
            $reading['value_numeric'] !== null => qms_decimal((string) $reading['value_numeric'], (int) $param['decimal_places']),
            $reading['value_choice'] !== null  => str_replace('_', ' ', (string) $reading['value_choice']),
            $reading['value_date'] !== null    => plant_date((string) $reading['value_date']),
            $reading['value_time'] !== null    => substr((string) $reading['value_time'], 0, 5),
            $reading['value_text'] !== null    => (string) $reading['value_text'],
            default                            => '',
        };
    }
}

if (! function_exists('reading_input')) {
    /**
     * Value of a stored reading for an input control (raw codes, ISO dates, HH:MM).
     *
     * @param array<string, mixed>      $param
     * @param array<string, mixed>|null $reading
     */
    function reading_input(array $param, ?array $reading): string
    {
        if ($reading === null) {
            return '';
        }

        return match (true) {
            $reading['value_numeric'] !== null => qms_decimal((string) $reading['value_numeric'], (int) $param['decimal_places']),
            $reading['value_choice'] !== null  => (string) $reading['value_choice'],
            $reading['value_date'] !== null    => (string) $reading['value_date'],
            $reading['value_time'] !== null    => substr((string) $reading['value_time'], 0, 5),
            $reading['value_text'] !== null    => (string) $reading['value_text'],
            default                            => '',
        };
    }
}

if (! function_exists('spec_limits')) {
    /**
     * "6.450 – 6.550 mm", "≥ 5.0 bar", "≤ 2.000" or "" for a parameter / observation snapshot.
     */
    function spec_limits(?string $lsl, ?string $usl, int $places, ?string $unit = null): string
    {
        $lo   = $lsl === null || $lsl === '' ? null : qms_decimal($lsl, $places);
        $hi   = $usl === null || $usl === '' ? null : qms_decimal($usl, $places);
        $text = match (true) {
            $lo !== null && $hi !== null => $lo . ' – ' . $hi,
            $lo !== null                 => '≥ ' . $lo,
            $hi !== null                 => '≤ ' . $hi,
            default                      => '',
        };

        return $text !== '' && $unit !== null && $unit !== '' ? $text . ' ' . $unit : $text;
    }
}
