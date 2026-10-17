<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Database\BaseConnection;

/**
 * Concurrency-safe document numbering.
 *
 * allocate() must run inside the caller's DB transaction: it takes a
 * row-level lock (SELECT … FOR UPDATE) on the series row, reads
 * next_number, increments it and returns the formatted number. Concurrent
 * finalizations queue on the lock, so two documents can never receive the
 * same number; the UNIQUE(sequence_id, sequence_number) and
 * UNIQUE(financial_year_id, document_number) keys are a second safety net.
 * If the outer transaction rolls back, the increment rolls back too, so no
 * statutory number is ever "burned" by a failed finalization. Drafts never
 * consume numbers.
 */
class DocumentNumberAllocator
{
    public function __construct(private readonly BaseConnection $db, private readonly FinancialYearService $fy)
    {
    }

    /**
     * @return array{sequence_id: int, number: int, formatted: string, financial_year_id: int}
     *
     * @throws BusinessRuleException
     */
    public function allocate(string $docType, string $date): array
    {
        if ($this->db->transDepth < 1) {
            throw new \LogicException('DocumentNumberAllocator::allocate() must be called inside a transaction.');
        }
        $fy  = $this->fy->forDate($date);
        $seq = $this->db->query(
            'SELECT * FROM document_sequences WHERE doc_type = ? AND financial_year_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1 FOR UPDATE',
            [$docType, $fy['id']]
        )->getRowArray();
        if ($seq === null) {
            $this->fy->ensureSequences((int) $fy['id']);
            $seq = $this->db->query(
                'SELECT * FROM document_sequences WHERE doc_type = ? AND financial_year_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1 FOR UPDATE',
                [$docType, $fy['id']]
            )->getRowArray();
        }
        if ($seq === null) {
            throw new BusinessRuleException("No active numbering series is configured for {$docType} in FY {$fy['code']}.");
        }
        if ((int) $fy['is_closed'] === 1) {
            throw new BusinessRuleException("Financial year {$fy['code']} is closed; documents cannot be numbered in it.");
        }

        $n         = (int) $seq['next_number'];
        $formatted = self::format($seq, $n);
        $errors    = self::validateFormat($seq['prefix'], $seq['suffix'], (int) $seq['padding'], $n, (bool) $seq['is_statutory']);
        if ($errors !== []) {
            throw new BusinessRuleException('Numbering series ' . $seq['series_code'] . ' cannot issue ' . $formatted . ': ' . implode(' ', $errors));
        }
        $this->db->query('UPDATE document_sequences SET next_number = next_number + 1, updated_at = NOW() WHERE id = ?', [$seq['id']]);

        return ['sequence_id' => (int) $seq['id'], 'number' => $n, 'formatted' => $formatted, 'financial_year_id' => (int) $fy['id']];
    }

    /** @param array<string, mixed> $seq */
    public static function format(array $seq, int $n): string
    {
        return $seq['prefix'] . str_pad((string) $n, (int) $seq['padding'], '0', STR_PAD_LEFT) . ($seq['suffix'] ?? '');
    }

    /**
     * GST Rule 46(b): consecutive serial number, unique per FY, ≤16 chars,
     * only letters, digits, hyphen '-' and slash '/'.
     *
     * @return list<string>
     */
    public static function validateFormat(string $prefix, string $suffix, int $padding, int $number, bool $statutory): array
    {
        $errors = [];
        $sample = $prefix . str_pad((string) $number, $padding, '0', STR_PAD_LEFT) . $suffix;
        if ($padding < 1 || $padding > 8) {
            $errors[] = 'Number padding must be between 1 and 8 digits.';
        }
        if ($statutory) {
            if (! preg_match('#^[A-Za-z0-9/\-]*$#', $prefix . $suffix)) {
                $errors[] = 'Statutory series may only contain letters, digits, "-" and "/".';
            }
            if (strlen($sample) > 16) {
                $errors[] = 'Statutory document numbers may not exceed 16 characters ("' . $sample . '" is ' . strlen($sample) . ').';
            }
        } elseif (! preg_match('#^[A-Za-z0-9/\-_.]*$#', $prefix . $suffix) || strlen($sample) > 30) {
            $errors[] = 'Prefix/suffix contain invalid characters or are too long.';
        }

        return $errors;
    }

    /** Highest number already used in a series (finalized docs/receipts/etc.). */
    public function maxUsed(int $sequenceId): int
    {
        $max = 0;
        foreach (['billing_documents', 'payment_receipts'] as $t) {
            $row = $this->db->query("SELECT COALESCE(MAX(sequence_number),0) m FROM {$t} WHERE sequence_id = ?", [$sequenceId])->getRowArray();
            $max = max($max, (int) $row['m']);
        }

        return $max;
    }

    /**
     * Allocate a number for internal (non-statutory) records that do not carry
     * sequence_id columns (transfers, expenses, journal vouchers).
     */
    public function allocateInternal(string $docType, string $date): string
    {
        return $this->allocate($docType, $date)['formatted'];
    }
}
