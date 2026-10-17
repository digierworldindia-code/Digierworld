<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Issues serial numbers, batch codes, claim numbers, dispatch codes and dealer
 * codes. Continues the previous platform's sequences exactly — the counters
 * were carried over at import — so no issued identifier can repeat.
 *
 *   serial    PFZ-yyyy-6 digits      PFZ-2026-000176   (was CLF26000176)
 *   batch     PFZ-yyyymmdd-3 digits  PFZ-20261017-001  (was BAT260032)
 *   claim     CLM + yy + 6 digits    CLM26000038
 *   dispatch  DSP + yy + 6 digits    DSP26000047
 *   dealer    DLR + 4 digits         DLR0063
 *   survey    SRV-yy-6 digits        SRV-26-000012
 *   review    WRV-yy-6 digits        WRV-26-000004
 *
 * The counter row is locked with SELECT ... FOR UPDATE, so two requests issuing
 * at the same moment are serialised by MySQL. Must be called inside the
 * transaction that uses the identifier: if that transaction rolls back, the
 * number is simply not issued. (A counter never goes backwards, so a rolled-back
 * number is skipped, never reused.)
 */
final class Identifiers
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function serial(): string
    {
        return $this->serials(1)[0];
    }

    /**
     * A contiguous block of serial numbers: PFZ-2026-000001, PFZ-2026-000002…
     *
     * Four-digit year, not two. The previous platform used CLF plus a
     * two-digit year and no separators; those serials are on labels in
     * customers' homes and are never rewritten, so the database CHECK accepts
     * both shapes and every lookup matches on either.
     *
     * @return list<string>
     */
    public function serials(int $count): array
    {
        $start  = $this->reserve('serial', $count);
        $prefix = brand('serialPrefix') . '-' . $this->fullYear() . '-';
        $out    = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $prefix . str_pad((string) ($start + $i), 6, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * A batch code: PFZ-20261017-001, the day's first batch.
     *
     * Readable off a whiteboard and off a label: the plant knows "yesterday's
     * second run" without looking anything up. Batches made under the
     * previous BAT260032 numbering keep their codes; they are history.
     *
     * Reserving from the shared counter is also the lock that makes the
     * day's number safe — two supervisors pressing Create at the same moment
     * are serialised by MySQL on that row, so they cannot both read the same
     * count and both claim -001.
     */
    public function batchCode(): string
    {
        $this->reserve('batch', 1);

        $prefix = brand('serialPrefix') . '-' . local_time(utc_now(), 'Ymd') . '-';
        $today  = $this->db->table('manufacturing_batches')->like('batch_code', $prefix, 'after')->countAllResults();

        return $prefix . str_pad((string) ($today + 1), 3, '0', STR_PAD_LEFT);
    }

    public function claimNumber(): string
    {
        return 'CLM' . $this->year() . str_pad((string) $this->reserve('claim', 1), 6, '0', STR_PAD_LEFT);
    }

    public function dispatchCode(): string
    {
        return 'DSP' . $this->year() . str_pad((string) $this->reserve('dispatch', 1), 6, '0', STR_PAD_LEFT);
    }

    public function dealerCode(): string
    {
        return 'DLR' . str_pad((string) $this->reserve('dealer', 1), 4, '0', STR_PAD_LEFT);
    }

    /** One dealer visit: SRV-26-000001. Quoted down the phone, so it is grouped. */
    public function surveyReference(): string
    {
        return 'SRV-' . $this->year() . '-' . str_pad((string) $this->reserve('survey', 1), 6, '0', STR_PAD_LEFT);
    }

    /** One recommendation that a warranty be reviewed: WRV-26-000001. */
    public function warrantyReviewReference(): string
    {
        return 'WRV-' . $this->year() . '-' . str_pad((string) $this->reserve('warranty_review', 1), 6, '0', STR_PAD_LEFT);
    }

    /** A QR token: 24 random bytes, base64url. Never derived from the serial. */
    public static function qrToken(): string
    {
        return Crypto::randomToken(24);
    }

    /** @return int the first reserved value */
    private function reserve(string $name, int $count): int
    {
        if ($count < 1) {
            throw new RuntimeException('Reserve at least one identifier.');
        }
        if ($this->db->transDepth === 0) {
            throw new RuntimeException('Identifiers must be issued inside the transaction that uses them.');
        }

        $row = $this->db->query('SELECT value FROM identifier_counters WHERE name = ? FOR UPDATE', [$name])->getRow();
        if ($row === null) {
            throw new RuntimeException("Identifier counter '{$name}' is missing. Run the migrations.");
        }

        $first = (int) $row->value + 1;
        $this->db->query('UPDATE identifier_counters SET value = ? WHERE name = ?', [(int) $row->value + $count, $name]);

        return $first;
    }

    /**
     * Four digits, in the company's own timezone rather than UTC.
     *
     * A batch serialised at half past midnight in Manesar on 1 January is a
     * unit of the new year to everyone who handles it. year() below stays on
     * UTC because the codes it builds are long established; this one is new.
     */
    private function fullYear(): string
    {
        return local_time(utc_now(), 'Y');
    }

    private function year(): string
    {
        return gmdate('y');
    }
}
