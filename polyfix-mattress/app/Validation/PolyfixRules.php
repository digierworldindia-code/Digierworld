<?php

namespace App\Validation;

use App\Libraries\ScanCode;

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

    /**
     * Both numbering schemes, because both are in the field: PFZ-2026-000001
     * on everything made since the rename, and CLF00000001 on mattresses in
     * customers' homes. The pattern lives in ScanCode, which is what the
     * camera and the typed-serial box already use; keeping a second copy here
     * is how this rule came to reject every serial the plant now issues.
     */
    public function serial_number(?string $value, ?string &$error = null): bool
    {
        $error = 'That is not a serial number. They look like '
            . brand('serialPrefix') . '-' . date('Y') . '-000123, and older labels like '
            . 'CLF26000123.';

        return $value !== null && ScanCode::isSerial($value);
    }
}
