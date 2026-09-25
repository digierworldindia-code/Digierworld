<?php

namespace App\Validation;

/**
 * Field formats shared by every form, with the same patterns the previous
 * platform enforced, so a record valid there is valid here.
 */
class PolyfixRules
{
    public function indian_mobile(?string $value, ?string &$error = null): bool
    {
        $error = 'Enter a valid 10-digit Indian mobile number.';

        return $value !== null && preg_match('/^(\+?91[- ]?)?[6-9]\d{9}$/', trim($value)) === 1;
    }

    public function pincode(?string $value, ?string &$error = null): bool
    {
        $error = 'Enter a valid 6-digit PIN code.';

        return $value !== null && preg_match('/^[1-9][0-9]{5}$/', trim($value)) === 1;
    }

    public function gstin(?string $value, ?string &$error = null): bool
    {
        $error = 'Enter a valid 15-character GSTIN.';

        return $value === null || $value === '' || preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/', strtoupper(trim($value))) === 1;
    }

    /** Printable text: rejects control characters other than tab and newline. */
    public function safe_text(?string $value, ?string &$error = null): bool
    {
        $error = 'Contains characters that are not allowed.';

        return $value === null || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    public function serial_number(?string $value, ?string &$error = null): bool
    {
        $error = 'Serial numbers are three letters followed by digits, for example ' . brand('serialPrefix') . '26000001.';

        return $value !== null && preg_match('/^[A-Z]{3}[0-9]{8,12}$/', strtoupper(trim($value))) === 1;
    }
}
