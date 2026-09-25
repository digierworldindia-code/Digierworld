<?php

namespace App\Libraries;

use App\Exceptions\AppException;
use CodeIgniter\Database\BaseBuilder;
use LogicException;

/**
 * Dealer data isolation.
 *
 * On the previous platform, PostgreSQL enforced this with FORCE ROW LEVEL
 * SECURITY on sixteen tables. MySQL has no equivalent, so the same rules live
 * here, and every dealer-facing query goes through this class. The rules are a
 * direct transcription of those sixteen policies:
 *
 *   own row when   <table>.<column> = the signed-in dealer
 *   claim_events   the claim is theirs AND the event is not internal — staff
 *                  review notes are never shown to a dealer
 *   *_items        through their parent (dispatch, receipt)
 *   mattress_events  the event names the dealer, or the unit is theirs now
 *
 * Two more layers stand behind this one: the dealer's identity comes only from
 * the server-side session (never a form field or URL), and the test suite
 * attempts cross-dealer access on every dealer route. It is still one layer
 * fewer than PostgreSQL gave; docs/security.md says so.
 */
final class DealerScope
{
    /** Tables with a direct dealer column. */
    public const COLUMN = [
        'claim_media'     => 'dealer_id',
        'customers'       => 'dealer_id',
        'dealer_receipts' => 'dealer_id',
        'dealer_users'    => 'dealer_id',
        'dealers'         => 'id',
        'dispatches'      => 'dealer_id',
        'mattresses'      => 'current_dealer_id',
        'notifications'   => 'dealer_id',
        'replacements'    => 'dealer_id',
        'sales'           => 'dealer_id',
        'warranties'      => 'dealer_id',
        'warranty_claims' => 'dealer_id',
    ];

    private function __construct(public readonly string $dealerId)
    {
    }

    /** The signed-in dealer's scope. Refuses outright if the user is not a dealer. */
    public static function current(): self
    {
        $dealerId = service('requestContext')->dealerId();
        if ($dealerId === null) {
            // Reaching dealer code without a dealer is a routing fault, not a
            // user error: the DealerFilter should have stopped the request.
            throw new LogicException('Dealer scope requested outside a dealer session.');
        }

        return new self($dealerId);
    }

    /** For tests and background jobs that act for a named dealer. */
    public static function for(string $dealerId): self
    {
        return new self($dealerId);
    }

    /**
     * Restricts a query to this dealer's rows.
     *
     * @param string      $table the real table name (decides the rule)
     * @param string|null $alias the alias used in the query, if any
     */
    public function apply(BaseBuilder $builder, string $table, ?string $alias = null): BaseBuilder
    {
        $ref = $alias ?? $table;

        if (isset(self::COLUMN[$table])) {
            return $builder->where("{$ref}." . self::COLUMN[$table], $this->dealerId);
        }

        $db = $builder->db();

        return match ($table) {
            'claim_events' => $builder
                ->where("{$ref}.is_internal", 0)
                ->where("EXISTS (SELECT 1 FROM warranty_claims c WHERE c.id = {$ref}.claim_id AND c.dealer_id = " . $db->escape($this->dealerId) . ')', null, false),
            'dispatch_items' => $builder
                ->where("EXISTS (SELECT 1 FROM dispatches d WHERE d.id = {$ref}.dispatch_id AND d.dealer_id = " . $db->escape($this->dealerId) . ')', null, false),
            'dealer_receipt_items' => $builder
                ->where("EXISTS (SELECT 1 FROM dealer_receipts r WHERE r.id = {$ref}.receipt_id AND r.dealer_id = " . $db->escape($this->dealerId) . ')', null, false),
            'mattress_events' => $builder->groupStart()
                ->where("{$ref}.dealer_id", $this->dealerId)
                ->orWhere("EXISTS (SELECT 1 FROM mattresses m WHERE m.id = {$ref}.mattress_id AND m.current_dealer_id = " . $db->escape($this->dealerId) . ')', null, false)
                ->groupEnd(),
            default => throw new LogicException("No dealer scope rule for table {$table}."),
        };
    }

    /**
     * Returns the row if it belongs to this dealer, otherwise the same
     * "not found" a missing row gives — a dealer learns nothing about records
     * that are not theirs, including whether they exist.
     *
     * @param array<string,mixed>|object|null $row
     *
     * @return array<string,mixed>
     */
    public function assertOwns(string $table, array|object|null $row, string $what = 'record'): array
    {
        $row = is_object($row) ? (array) $row : $row;
        $column = self::COLUMN[$table] ?? null;

        if ($row === null || $column === null || ($row[$column] ?? null) !== $this->dealerId) {
            throw AppException::notFound($what, $row === null ? 'no such row' : "{$table} row belongs to another dealer");
        }

        return $row;
    }
}
