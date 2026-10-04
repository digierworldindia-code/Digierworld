<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side checks for simple form fields and uploaded files.
 * Business validation lives in the services (ValidationException); this class
 * covers the request-level rules the controllers declare:
 *
 *   required | max_length[n] | uploaded[f] | max_size[f,kb] | is_image[f]
 *   mime_in[f,a/b,…] | ext_in[f,png,…]
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data  field => value
     * @param array<string, string> $rules field => 'rule|rule[arg]'
     */
    public function run(array $data, array $rules, ?Request $request = null): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $args] = self::parse($rule);
                $error = in_array($name, ['uploaded', 'max_size', 'is_image', 'mime_in', 'ext_in'], true)
                    ? $this->checkFile($name, $args, $request)
                    : $this->checkValue($field, $name, $args, $data[$field] ?? null);
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
     * @param list<string> $args
     */
    private function checkValue(string $field, string $rule, array $args, mixed $value): ?string
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        return match ($rule) {
            'required'   => is_string($value) ? (trim($value) === '' ? "{$label} is required." : null) : ($value === null || $value === [] ? "{$label} is required." : null),
            'max_length' => is_string($value) && mb_strlen($value) > (int) ($args[0] ?? 0) ? "{$label} is too long." : (is_array($value) ? "{$label} is invalid." : null),
            default      => throw new \InvalidArgumentException("Unknown validation rule {$rule}"),
        };
    }

    /**
     * @param list<string> $args
     */
    private function checkFile(string $rule, array $args, ?Request $request): ?string
    {
        $file = ($request ?? service('request'))->getFile((string) ($args[0] ?? ''));

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

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function parse(string $rule): array
    {
        if (preg_match('/^([a-z_]+)\[(.*)\]$/', trim($rule), $m)) {
            return [$m[1], array_map('trim', explode(',', $m[2]))];
        }

        return [trim($rule), []];
    }
}
