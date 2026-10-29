<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Works out which mattress a scan refers to.
 *
 * Four things can come off a label or out of the typed-serial box, and all
 * four have to work, because they are all in the field already:
 *
 *   1. The verification URL in the QR code:
 *      https://www.polyfizz.in/warranty/verify?q=<qr_token>
 *   2. The bare qr_token, from a scanner app that strips the URL.
 *   3. The serial number, from the Code 128 barcode beside the QR code.
 *   4. The serial number typed by hand — in either numbering scheme, since
 *      CLF labels from the previous system are on mattresses in customers'
 *      homes and will be for a decade.
 *
 * The lookup is by exact match on an indexed, unique column. There is no
 * pattern search and no fuzzy matching: a scan either identifies one unit or
 * it identifies none, and guessing which mattress somebody meant is not a
 * thing stock control should do.
 *
 * Nothing here writes. It answers "which unit is this?", and the service that
 * called it decides what that means.
 */
final class ScanCode
{
    /**
     * Serial numbers, both schemes.
     *
     *   PFZ-2026-000001   current
     *   CLF00000001       the previous platform's, still on units in the field
     */
    private const SERIAL = '/^(?:[A-Z]{3}-[0-9]{4}-[0-9]{6,8}|[A-Z]{3}[0-9]{8,12})$/';

    /** A qr_token is 43 characters of base64url (a 32-byte value). */
    private const TOKEN = '/^[A-Za-z0-9_-]{22,64}$/';

    /**
     * Is this a serial number, in either numbering scheme?
     *
     * The form validation rule asks here rather than keeping a pattern of its
     * own. It used to keep one, it was the pre-2026 pattern, and so the forms
     * for recording a sale and raising a claim refused every serial this
     * release issues — a dealer could not sell a mattress the plant had made.
     * One pattern, one place.
     */
    public static function isSerial(string $raw): bool
    {
        $upper = strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');

        return preg_match(self::SERIAL, $upper) === 1;
    }

    /**
     * Reduces whatever was scanned to a kind and a value.
     *
     * @return array{kind:'token'|'serial', value:string}|null null when the
     *                                                         payload is not
     *                                                         one of ours
     */
    public static function parse(string $raw): ?array
    {
        $code = trim($raw);
        if ($code === '') {
            return null;
        }

        // A URL: take the q parameter and ignore everything else about it.
        // The host is deliberately not checked — a label printed before the
        // site moved to www.polyfizz.in carries the old address, and the
        // token it holds is still this one's token.
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $code) === 1) {
            $query = parse_url($code, PHP_URL_QUERY);
            parse_str((string) $query, $params);
            $code = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

            if ($code === '') {
                return null;
            }

            return preg_match(self::TOKEN, $code) === 1 ? ['kind' => 'token', 'value' => $code] : null;
        }

        // Typed by hand: people use spaces and lower case, and some phones
        // helpfully insert a trailing space.
        $upper = strtoupper(preg_replace('/\s+/', '', $code) ?? '');

        if (preg_match(self::SERIAL, $upper) === 1) {
            return ['kind' => 'serial', 'value' => $upper];
        }

        // A bare token, which is case-sensitive, so the original is used.
        if (preg_match(self::TOKEN, $code) === 1) {
            return ['kind' => 'token', 'value' => $code];
        }

        return null;
    }

    /**
     * The mattress a scan identifies, with everything the scanner needs to
     * report back about it. Null when the code is unreadable or unknown.
     *
     * Soft-deleted units are excluded: a deleted record is not stock, and
     * counting one would put the batch total above what exists.
     *
     * @return array<string,mixed>|null
     */
    public static function find(BaseConnection $db, string $raw): ?array
    {
        $parsed = self::parse($raw);
        if ($parsed === null) {
            return null;
        }

        $row = $db->table('mattresses m')
            ->select('m.id, m.serial_number, m.qr_token, m.current_status, m.batch_id, m.current_dealer_id,'
                . ' m.current_warehouse_id, m.product_variant_id, m.manufactured_at,'
                . ' v.size_label, p.name AS product_name, b.batch_code')
            ->join('product_variants v', 'v.id = m.product_variant_id', 'left')
            ->join('products p', 'p.id = v.product_id', 'left')
            ->join('manufacturing_batches b', 'b.id = m.batch_id', 'left')
            ->where($parsed['kind'] === 'serial' ? 'm.serial_number' : 'm.qr_token', $parsed['value'])
            ->where('m.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** "Ortho Plus · 72x36x6" — what the operator reads back off the screen. */
    public static function describe(array $mattress): string
    {
        return implode(' · ', array_filter([
            $mattress['product_name'] ?? null,
            $mattress['size_label'] ?? null,
        ]));
    }
}
