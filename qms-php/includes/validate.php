<?php
/**
 * Server-side validation with rule strings (the browser checks are only a help).
 *
 *   validate_or_fail($data, [
 *       'gauge_code' => ['label' => 'Gauge ID', 'rules' => 'required|max_length[40]|regex_match[/^[A-Z0-9-]+$/]'],
 *       'email'      => 'permit_empty|valid_email',
 *   ]);
 *
 * Rules: required, permit_empty, max_length[n], min_length[n], integer, numeric,
 * decimal, is_natural, is_natural_no_zero, greater_than[n], greater_than_equal_to[n],
 * less_than[n], less_than_equal_to[n], in_list[a,b], regex_match[/re/],
 * valid_date[format], valid_email, differs[field], alpha_numeric.
 * Checks that need the database are done by the module functions.
 */
defined('QMS') || exit;

const VALIDATE_MESSAGES = [
    'alpha_numeric'         => 'The {field} field may only contain alphanumeric characters.',
    'decimal'               => 'The {field} field must contain a decimal number.',
    'differs'               => 'The {field} field must differ from the {param} field.',
    'greater_than'          => 'The {field} field must contain a number greater than {param}.',
    'greater_than_equal_to' => 'The {field} field must contain a number greater than or equal to {param}.',
    'in_list'               => 'The {field} field must be one of: {param}.',
    'integer'               => 'The {field} field must contain an integer.',
    'is_natural'            => 'The {field} field must only contain digits.',
    'is_natural_no_zero'    => 'The {field} field must only contain digits and must be greater than zero.',
    'less_than'             => 'The {field} field must contain a number less than {param}.',
    'less_than_equal_to'    => 'The {field} field must contain a number less than or equal to {param}.',
    'max_length'            => 'The {field} field cannot exceed {param} characters in length.',
    'min_length'            => 'The {field} field must be at least {param} characters in length.',
    'numeric'               => 'The {field} field must contain only numbers.',
    'regex_match'           => 'The {field} field is not in the correct format.',
    'required'              => 'The {field} field is required.',
    'valid_email'           => 'The {field} field must contain a valid email address.',
    'valid_date'            => 'The {field} field must contain a valid date.',
];

/**
 * Checks $data against the rules.
 *
 * @param array<string, mixed>                                           $data
 * @param array<string, string|array{label?: string, rules: string}>     $rules
 *
 * @return array<string, string> field => first error message ([] = valid)
 */
function validate(array $data, array $rules): array
{
    $labels = [];
    $parsed = [];
    foreach ($rules as $field => $definition) {
        $labels[$field] = is_array($definition) ? (string) ($definition['label'] ?? $field) : (string) $field;
        $parsed[$field] = validate_split(is_array($definition) ? (string) $definition['rules'] : (string) $definition);
    }

    $errors = [];
    foreach ($parsed as $field => $fieldRules) {
        $value = $data[$field] ?? null;
        if (in_array('permit_empty', $fieldRules, true) && ($value === null || $value === '' || $value === [])) {
            continue;
        }
        foreach ($fieldRules as $rule) {
            [$name, $param] = preg_match('/^([a-z_]+)\[(.*)\]$/s', $rule, $m) ? [$m[1], $m[2]] : [$rule, ''];
            if ($name === 'permit_empty') {
                continue;
            }
            if (! validate_rule($name, $param, $value, $data)) {
                if ($name === 'differs' && isset($labels[$param])) {
                    $param = $labels[$param];
                }
                $errors[$field] = is_array($value) && $name !== 'required'
                    ? "The {$labels[$field]} field is invalid."
                    : strtr(VALIDATE_MESSAGES[$name], ['{field}' => $labels[$field], '{param}' => $param]);
                break;
            }
        }
    }

    return $errors;
}

/** Like validate(), but throws a ValidationError with the messages. */
function validate_or_fail(array $data, array $rules): void
{
    $errors = validate($data, $rules);
    if ($errors !== []) {
        throw new ValidationError($errors);
    }
}

function validate_rule(string $rule, string $param, mixed $value, array $data): bool
{
    if (is_array($value)) {
        return $rule === 'required' && $value !== [];
    }
    $str = $value === null ? null : (is_scalar($value) ? (string) $value : '');

    return match ($rule) {
        'required'              => $str !== null && trim($str) !== '',
        'max_length'            => mb_strlen($str ?? '') <= (int) $param,
        'min_length'            => mb_strlen($str ?? '') >= (int) $param,
        'integer'               => (bool) preg_match('/\A[\-+]?\d+\z/', $str ?? ''),
        'numeric'               => (bool) preg_match('/\A[\-+]?\d*\.?\d+\z/', $str ?? ''),
        'decimal'               => (bool) preg_match('/\A[\-+]?\d*\.?\d+\z/', $str ?? ''),
        'is_natural'            => ctype_digit($str ?? ''),
        'is_natural_no_zero'    => ctype_digit($str ?? '') && ltrim((string) $str, '0') !== '',
        'greater_than'          => is_numeric($str) && (float) $str > (float) $param,
        'greater_than_equal_to' => is_numeric($str) && (float) $str >= (float) $param,
        'less_than'             => is_numeric($str) && (float) $str < (float) $param,
        'less_than_equal_to'    => is_numeric($str) && (float) $str <= (float) $param,
        'in_list'               => in_array(trim($str ?? ''), array_map('trim', explode(',', $param)), true),
        'regex_match'           => (bool) preg_match(in_array($param[0] ?? '', ['/', '#', '~'], true) ? $param : "/{$param}/", $str ?? ''),
        'valid_date'            => validate_date($str, $param),
        'valid_email'           => filter_var($str, FILTER_VALIDATE_EMAIL) !== false,
        'differs'               => $str !== (isset($data[$param]) && is_scalar($data[$param]) ? (string) $data[$param] : null),
        'alpha_numeric'         => ctype_alnum($str ?? ''),
        default                 => throw new InvalidArgumentException("Unknown validation rule {$rule}"),
    };
}

/** True when $value is a real date in $format (e.g. Y-m-d; 2026-02-30 is refused). */
function validate_date(?string $value, string $format = 'Y-m-d'): bool
{
    if ($value === null || $value === '') {
        return false;
    }
    if ($format === '') {
        return strtotime($value) !== false;
    }
    $date   = DateTime::createFromFormat('!' . $format, $value);
    $errors = DateTime::getLastErrors();

    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format($format) === $value;
}

/**
 * Splits "a|b[x|y]|c" at the pipes outside brackets (regular expressions may contain pipes).
 *
 * @return list<string>
 */
function validate_split(string $rules): array
{
    $out    = [];
    $length = strlen($rules);
    $cursor = 0;
    while ($cursor < $length) {
        $pos  = strpos($rules, '|', $cursor);
        $pos  = $pos === false ? $length : $pos;
        $rule = substr($rules, $cursor, $pos - $cursor);
        while ((substr_count($rule, '[') - substr_count($rule, '\[')) !== (substr_count($rule, ']') - substr_count($rule, '\]')) && $pos < $length) {
            $next = strpos($rules, '|', $pos + 1);
            $pos  = $next === false ? $length : $next;
            $rule = substr($rules, $cursor, $pos - $cursor);
        }
        if (trim($rule) !== '') {
            $out[] = trim($rule);
        }
        $cursor = $pos + 1;
    }

    return array_values(array_unique($out));
}
