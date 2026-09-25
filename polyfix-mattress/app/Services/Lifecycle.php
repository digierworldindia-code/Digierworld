<?php

namespace App\Services;

use App\Exceptions\AppException;
use CodeIgniter\Database\BaseConnection;

/**
 * The mattress state machine and its passport.
 *
 * A status is never set directly. Every change goes through transition(),
 * which checks the move is legal, writes the new state, and appends a passport
 * event in the same transaction. The database's CHECK constraints back this up:
 * an impossible combination (sold without a sale date, in dealer custody with
 * no dealer) cannot be stored even by a bug.
 */
final class Lifecycle
{
    /** The only legal moves, unchanged from the previous platform. */
    public const TRANSITIONS = [
        'MANUFACTURED'    => ['IN_DISPATCH', 'SCRAPPED'],
        'IN_DISPATCH'     => ['DISPATCHED', 'MANUFACTURED'],
        'DISPATCHED'      => ['DEALER_RECEIVED', 'RETURNED'],
        'DEALER_RECEIVED' => ['SOLD', 'RETURNED', 'SCRAPPED'],
        'SOLD'            => ['CLAIM_OPEN', 'RETURNED'],
        'CLAIM_OPEN'      => ['SOLD', 'REPLACED'],
        'REPLACED'        => [],
        'RETURNED'        => ['IN_DISPATCH', 'SCRAPPED'],
        'SCRAPPED'        => [],
    ];

    /** How each status reads to a dealer looking at their own stock. */
    public const DEALER_LABELS = [
        'DISPATCHED'      => 'Incoming',
        'DEALER_RECEIVED' => 'Available',
        'SOLD'            => 'Sold · warranty active',
        'CLAIM_OPEN'      => 'Claim open',
        'REPLACED'        => 'Replaced',
        'RETURNED'        => 'Returned',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw AppException::rule(
                'A mattress that is ' . strtolower(humanise($from)) . ' cannot become ' . strtolower(humanise($to)) . '.',
                "illegal transition {$from} -> {$to}",
            );
        }
    }

    /**
     * Moves one mattress to a new status and records why.
     *
     * @param array<string,mixed> $changes extra columns to set with the status
     * @param array<string,mixed> $payload stored on the passport event
     */
    public function transition(
        string $mattressId,
        string $from,
        string $to,
        string $eventType,
        ?string $dealerId,
        ?string $actorUserId,
        array $changes = [],
        array $payload = [],
    ): void {
        self::assertTransition($from, $to);

        $this->db->table('mattresses')
            ->where('id', $mattressId)
            ->where('current_status', $from)   // optimistic check: the state we validated is the state we change
            ->update(['current_status' => $to, 'updated_by' => $actorUserId] + $changes);

        if ($this->db->affectedRows() !== 1) {
            throw AppException::conflict('This mattress changed while you were working on it. Reload and try again.');
        }

        $this->event($mattressId, $eventType, $from, $to, $dealerId, $actorUserId, $payload);
    }

    /**
     * Appends a passport event without a status change (for example a unit
     * reported missing at receipt).
     *
     * @param array<string,mixed> $payload
     */
    public function event(
        string $mattressId,
        string $eventType,
        ?string $from,
        ?string $to,
        ?string $dealerId,
        ?string $actorUserId,
        array $payload = [],
    ): void {
        $this->db->table('mattress_events')->insert([
            'mattress_id'   => $mattressId,
            'event_type'    => $eventType,
            'from_status'   => $from,
            'to_status'     => $to,
            'actor_user_id' => $actorUserId,
            'dealer_id'     => $dealerId,
            'payload'       => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at'   => utc_now(),
        ]);
    }
}
