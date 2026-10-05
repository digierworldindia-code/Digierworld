<?php
/**
 * Small HTML builders shared by the inspection entry, detail and print pages.
 */
defined('QMS') || exit;

/**
 * One reading control. Every control carries data-reading="<n>" inside the
 * observation container (data-obs) so inspection-form.js can track it.
 *
 * @param bool $compact grid cell (In-Process sheet)
 */
function insp_reading_input(array $param, array $obs, int $n, bool $disabled, bool $compact): string
{
    $reading = $obs['readings'][$n] ?? null;
    $value   = reading_input($param, $reading);
    $result  = $reading['result'] ?? null;
    $state   = $result === 'PASS' ? ' is-pass' : ($result === 'FAIL' ? ' is-fail' : '');
    $id      = 'r-' . (int) $obs['id'] . '-' . $n;
    $dis     = $disabled ? ' disabled' : '';
    $label   = e($param['name'] . ', observation ' . $n);
    $type    = (string) $param['observation_type'];
    $cls     = $compact ? 'form-control form-control-sm qms-cell' : 'form-control qms-reading';
    $choices = obs_choices($type);

    if ($choices !== []) {
        if ($compact) {
            $html = '<select class="form-select form-select-sm qms-cell' . $state . '" id="' . $id . '" data-reading="' . $n . '" aria-label="' . $label . '"' . $dis . '><option value="">–</option>';
            foreach ($choices as $code => $text) {
                $html .= '<option value="' . e($code) . '"' . ($value === $code ? ' selected' : '') . '>' . e($text) . '</option>';
            }

            return $html . '</select>';
        }
        $classes = ['OK' => 'btn-outline-success btn-choice-ok', 'NOT_OK' => 'btn-outline-danger btn-choice-notok', 'GO' => 'btn-outline-success btn-choice-go', 'NO_GO' => 'btn-outline-danger btn-choice-nogo'];
        $html    = '<div class="qms-choice" role="radiogroup" aria-label="' . $label . '" id="' . $id . '">';
        foreach ($choices as $code => $text) {
            $html .= '<input class="btn-check" type="radio" name="' . $id . '" id="' . $id . '-' . e($code) . '" value="' . e($code) . '" data-reading="' . $n . '"' . ($value === $code ? ' checked' : '') . $dis . '>'
                . '<label class="btn ' . $classes[$code] . '" for="' . $id . '-' . e($code) . '">' . qms_icon(in_array($code, ['OK', 'GO'], true) ? 'bi-check-lg' : 'bi-x-lg') . ' ' . e($text) . '</label>';
        }

        return $html . '</div>';
    }

    return match ($type) {
        'DATE'  => '<input class="' . $cls . $state . '" type="date" id="' . $id . '" data-reading="' . $n . '" value="' . e($value) . '" aria-label="' . $label . '"' . $dis . '>',
        'TIME'  => '<input class="' . $cls . $state . '" type="time" id="' . $id . '" data-reading="' . $n . '" value="' . e($value) . '" aria-label="' . $label . '"' . $dis . '>',
        'TEXT'  => '<input class="' . $cls . $state . '" type="text" id="' . $id . '" data-reading="' . $n . '" maxlength="255" value="' . e($value) . '" aria-label="' . $label . '" autocomplete="off"' . $dis . '>',
        default => '<input class="' . $cls . $state . ' num" type="text" inputmode="decimal" id="' . $id . '" data-reading="' . $n . '" maxlength="20" value="' . e($value)
            . '" aria-label="' . $label . '" autocomplete="off" enterkeyhint="next"' . $dis . '>',
    };
}

/** Data attributes of an observation container (read by inspection-form.js). */
function insp_obs_attributes(array $param, array $obs): string
{
    return ' data-obs="' . (int) $obs['id'] . '" data-type="' . e($param['observation_type']) . '" data-lsl="' . e((string) $obs['lsl']) . '"'
        . ' data-usl="' . e((string) $obs['usl']) . '" data-decimals="' . (int) $param['decimal_places'] . '" data-date-rule="' . e($param['date_rule']) . '"'
        . ' data-count="' . (int) $param['observation_count'] . '" data-mandatory="' . (int) $param['is_mandatory'] . '" data-name="' . e($param['name']) . '"';
}

/** Badge of an In-Process round state. */
function insp_round_badge(string $status): string
{
    return match ($status) {
        'VERIFIED' => '<span class="qms-badge qms-badge--approved">' . qms_icon('bi-patch-check') . ' Verified</span>',
        'SIGNED'   => '<span class="qms-badge qms-badge--pass">' . qms_icon('bi-pen') . ' Signed</span>',
        default    => '<span class="qms-badge qms-badge--muted">' . qms_icon('bi-pencil') . ' Open</span>',
    };
}

/**
 * Button that opens the signature dialog (app.js). $url is the page that performs the action.
 *
 * @param array<string, string> $options title, meaning, remarks (required|optional|none), password (yes|no), button, tone, class, label
 */
function insp_sign_button(string $action, string $url, array $options): string
{
    return '<button class="' . e($options['class'] ?? 'btn btn-outline-primary') . '" type="button" data-sign-action="' . e($action) . '" data-sign-url="' . e($url) . '"'
        . ' data-sign-title="' . e($options['title'] ?? 'Confirm') . '" data-sign-meaning="' . e($options['meaning'] ?? '') . '"'
        . ' data-sign-remarks="' . e($options['remarks'] ?? 'optional') . '" data-sign-password="' . e($options['password'] ?? 'yes') . '"'
        . ' data-sign-button="' . e($options['button'] ?? 'Sign') . '"' . (isset($options['tone']) ? ' data-sign-tone="' . e($options['tone']) . '"' : '') . '>'
        . ($options['label'] ?? 'Sign') . '</button>';
}
