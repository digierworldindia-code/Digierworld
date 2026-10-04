<?php

declare(strict_types=1);

namespace App\Core;

use DateTime;

/**
 * Server-side validation with rule strings:
 *
 *   $v = new Validator();
 *   $v->setRules(['gauge_code' => 'required|max_length[40]|regex_match[/^[A-Z0-9]+$/]',
 *                 'email'      => ['label' => 'E-mail', 'rules' => 'permit_empty|valid_email']]);
 *   if (! $v->run($data)) { $errors = $v->getErrors(); }   // field => first message
 *
 * Value rules: required, permit_empty, max_length[n], min_length[n], integer,
 * numeric, decimal, is_natural, is_natural_no_zero, greater_than[n],
 * greater_than_equal_to[n], less_than[n], less_than_equal_to[n], in_list[a,b],
 * regex_match[/re/], valid_date[format], valid_email, differs[field],
 * alpha_numeric.
 * File rules (uploads): uploaded[f], max_size[f,kb], is_image[f], mime_in[f,…], ext_in[f,…].
 *
 * Business rules that need the database live in the services.
 */
final class Validator
{
    private const FILE_RULES = ['uploaded', 'max_size', 'is_image', 'mime_in', 'ext_in'];

    private const MESSAGES = [
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

    /** @var array<string, array{label: string, rules: list<string>}> */
    private array $rules = [];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, string|array{label?: string, rules: string}> $rules
     */
    public function setRules(array $rules): self
    {
        $this->rules = [];
        foreach ($rules as $field => $definition) {
            $label = is_array($definition) ? (string) ($definition['label'] ?? $field) : (string) $field;
            $rule  = is_array($definition) ? (string) $definition['rules'] : $definition;
            $this->rules[(string) $field] = ['label' => $label, 'rules' => self::splitRules($rule)];
        }

        return $this;
    }

    /**
     * @param array<string, mixed>                                            $data
     * @param array<string, string|array{label?: string, rules: string}>|null $rules
     */
    public function run(array $data, ?array $rules = null, ?Request $request = null): bool
    {
        if ($rules !== null) {
            $this->setRules($rules);
        }
        $this->errors = [];

        foreach ($this->rules as $field => ['label' => $label, 'rules' => $fieldRules]) {
            $value = $data[$field] ?? null;
            if (in_array('permit_empty', $fieldRules, true) && ($value === null || $value === '' || $value === [])) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $param] = self::parse($rule);
                if ($name === 'permit_empty') {
                    continue;
                }
                $error = in_array($name, self::FILE_RULES, true)
                    ? $this->checkFile($name, $param, $request)
                    : $this->checkValue($name, $param, $value, $data, $label);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }

        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkValue(string $rule, string $param, mixed $value, array $data, string $label): ?string
    {
        if (is_array($value) && $rule !== 'required') {
            return "The {$label} field is invalid.";
        }
        $str = $value === null ? null : (is_scalar($value) ? (string) $value : '');

        $ok = match ($rule) {
            'required'              => is_array($value) ? $value !== [] : ($str !== null && trim($str) !== ''),
            'max_length'            => mb_strlen($str ?? '') <= (int) $param,
            'min_length'            => mb_strlen($str ?? '') >= (int) $param,
            'integer'               => (bool) preg_match('/\A[\-+]?\d+\z/', $str ?? ''),
            'numeric'               => (bool) preg_match('/\A[\-+]?\d*\.?\d+\z/', $str ?? ''),
            'decimal'               => (bool) preg_match('/\A[\-+]?\d{0,}\.?\d+\z/', $str ?? ''),
            'is_natural'            => ctype_digit($str ?? ''),
            'is_natural_no_zero'    => ctype_digit($str ?? '') && (int) $str !== 0,
            'greater_than'          => is_numeric($str) && $str > $param,
            'greater_than_equal_to' => is_numeric($str) && $str >= $param,
            'less_than'             => is_numeric($str) && $str < $param,
            'less_than_equal_to'    => is_numeric($str) && $str <= $param,
            'in_list'               => in_array(trim($str ?? ''), array_map('trim', explode(',', $param)), true),
            'regex_match'           => (bool) preg_match(in_array($param[0] ?? '', ['/', '#', '~'], true) ? $param : "/{$param}/", $str ?? ''),
            'valid_date'            => self::validDate($str, $param),
            'valid_email'           => filter_var($str, FILTER_VALIDATE_EMAIL) !== false,
            'differs'               => $str !== (isset($data[$param]) && is_scalar($data[$param]) ? (string) $data[$param] : null),
            'alpha_numeric'         => ctype_alnum($str ?? ''),
            default                 => throw new \InvalidArgumentException("Unknown validation rule {$rule}"),
        };
        if ($ok) {
            return null;
        }

        if ($rule === 'differs' && isset($this->rules[$param])) {
            $param = $this->rules[$param]['label'];
        }

        return strtr(self::MESSAGES[$rule], ['{field}' => $label, '{param}' => $param]);
    }

    private function checkFile(string $rule, string $param, ?Request $request): ?string
    {
        $args = array_map('trim', explode(',', $param));
        $file = ($request ?? service('request'))->getFile($args[0]);

        if ($rule === 'uploaded') {
            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                return 'Choose a file to upload.';
            }

            return $file->isValid() ? null : ($file->getErrorString() ?: 'The file could not be uploaded.');
        }
        if ($file === null || ! $file->isValid()) {
            return 'Choose a file to upload.';
        }

        return match ($rule) {
            'max_size' => $file->getSize() > (int) ($args[1] ?? 0) * 1024 ? 'The file is larger than ' . (int) ($args[1] ?? 0) . ' KB.' : null,
            'is_image' => str_starts_with($file->getMimeType(), 'image/') && @getimagesize($file->getTempName()) !== false ? null : 'The file must be an image.',
            'mime_in'  => in_array($file->getMimeType(), array_slice($args, 1), true) ? null : 'This file type is not allowed.',
            'ext_in'   => in_array($file->getClientExtension(), array_map('strtolower', array_slice($args, 1)), true) ? null : 'This file extension is not allowed.',
        };
    }

    private static function validDate(?string $value, string $format): bool
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
     * Splits "a|b[x|y]|c" at the pipes that are not inside brackets (regular expressions contain pipes).
     *
     * @return list<string>
     */
    private static function splitRules(string $rules): array
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

    /**
     * @return array{0: string, 1: string} rule name and parameter text
     */
    private static function parse(string $rule): array
    {
        if (preg_match('/^([a-z_]+)\[(.*)\]$/s', $rule, $m)) {
            return [$m[1], $m[2]];
        }

        return [$rule, ''];
    }
}
