<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Issues serial numbers, batch codes, claim numbers, dispatch codes and dealer
 * codes. Continues the previous platform's sequences exactly — the counters
 * were carried over at import — so no issued identifier can repeat.
 *
 *   serial    CLF + yy + 6 digits    CLF26000176
 *   batch     BAT + yy + 4 digits    BAT260032
 *   claim     CLM + yy + 6 digits    CLM26000038
 *   dispatch  DSP + yy + 6 digits    DSP26000047
 *   dealer    DLR + 4 digits         DLR0063
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

    /** @return list<string> */
    public function serials(int $count): array
    {
        $start  = $this->reserve('serial', $count);
        $prefix = brand('serialPrefix') . $this->year();
        $out    = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $prefix . str_pad((string) ($start + $i), 6, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    public function batchCode(): string
    {
        return 'BAT' . $this->year() . str_pad((string) $this->reserve('batch', 1), 4, '0', STR_PAD_LEFT);
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

    private function year(): string
    {
        return gmdate('y');
    }
}
