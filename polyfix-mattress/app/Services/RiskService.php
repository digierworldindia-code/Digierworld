<?php

namespace App\Services;

use App\Libraries\Settings;
use CodeIgniter\Database\BaseConnection;

/**
 * Warranty claim risk indicators.
 *
 * Deterministic, explainable checks that surface claims worth a closer look,
 * each carrying the evidence that triggered it. This is NOT an automated
 * decision: nothing here rejects a claim, contacts a customer or penalises a
 * dealer. HIGH means "read this one carefully"; a person decides and records why.
 *
 * Signals, weights and thresholds are unchanged from the previous platform, so
 * scores on imported claims and new ones mean the same thing:
 *
 *   EARLY_CLAIM              25  raised within risk.early_claim_days of the sale
 *   WARRANTY_EXPIRED         40  raised after the warranty ended
 *   WARRANTY_NEAR_EXPIRY     10  raised within 60 days of the end
 *   DEALER_CLAIM_RATIO       20  dealer's claims/sales above threshold (20+ sales)
 *   DEALER_CLAIM_BURST       15  10+ claims from the dealer in 30 days
 *   REPEAT_CUSTOMER_CLAIMS   20  the same mobile number on other claims
 *   REPEAT_ADDRESS           10  other customers' claims at the same address
 *   DUPLICATE_PHOTO          30  an image byte-identical to one on another claim
 *   TIMELINE_INCONSISTENT    25  lifecycle dates out of order (compared by day)
 *   REPEAT_CLAIM_SAME_UNIT   15  this mattress has been claimed on before
 *
 * Score is the sum, capped at 100.  LOW < 20 <= MEDIUM < 50 <= HIGH.
 */
final class RiskService
{
    public const MEDIUM = 20;
    public const HIGH   = 50;
    private const DAY   = 86400;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function levelFor(int $score): string
    {
        return $score >= self::HIGH ? 'HIGH' : ($score >= self::MEDIUM ? 'MEDIUM' : 'LOW');
    }

    /** @return array{score:int, level:string, signals:list<array{code:string, weight:int, summary:string, evidence:array}>} */
    public function assess(string $claimId): array
    {
        $claim = $this->db->query(
            'SELECT c.id, c.dealer_id, c.customer_id, c.mattress_id, c.submitted_at,
                    w.end_date, m.dispatched_at, m.received_at, m.sold_at,
                    cu.phone_hash, cu.address_hash
               FROM warranty_claims c
               JOIN mattresses m ON m.id = c.mattress_id
               LEFT JOIN warranties w ON w.id = c.warranty_id
               LEFT JOIN customers cu ON cu.id = c.customer_id
              WHERE c.id = ?',
            [$claimId],
        )->getRowArray();

        if ($claim === null) {
            return ['score' => 0, 'level' => 'LOW', 'signals' => []];
        }

        $signals   = [];
        $submitted = strtotime($claim['submitted_at'] . ' UTC');
        $count     = fn (string $sql, array $binds): int => (int) $this->db->query($sql, $binds)->getRow()->n;

        // 1. how soon after the sale
        $earlyDays = (int) Settings::get('risk.early_claim_days', 30);
        if ($claim['sold_at'] !== null) {
            $days = (int) floor(($submitted - strtotime($claim['sold_at'] . ' UTC')) / self::DAY);
            if ($days >= 0 && $days <= $earlyDays) {
                $signals[] = $this->signal('EARLY_CLAIM', 25, "Claim raised {$days} day(s) after the sale.", ['daysSinceSale' => $days, 'threshold' => $earlyDays]);
            }
        }

        // 2. the warranty window
        if ($claim['end_date'] !== null) {
            $end = strtotime($claim['end_date'] . ' 00:00:00 UTC');
            if ($submitted > $end) {
                $signals[] = $this->signal('WARRANTY_EXPIRED', 40, 'The warranty period had already ended when this claim was raised.', ['warrantyEnd' => $claim['end_date']]);
            } elseif ($end - $submitted <= 60 * self::DAY) {
                $signals[] = $this->signal('WARRANTY_NEAR_EXPIRY', 10, 'Claim raised within 60 days of warranty expiry.', [
                    'warrantyEnd' => $claim['end_date'], 'daysRemaining' => (int) floor(($end - $submitted) / self::DAY),
                ]);
            }
        }

        // 3. the dealer's claim ratio — below 20 sales it is too noisy to mean anything
        $sales     = $count('SELECT COUNT(*) n FROM sales WHERE dealer_id = ? AND deleted_at IS NULL', [$claim['dealer_id']]);
        $claims    = $count('SELECT COUNT(*) n FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL', [$claim['dealer_id']]);
        $threshold = (float) Settings::get('risk.dealer_claim_ratio_threshold', 0.15);
        if ($sales >= 20 && $claims / $sales > $threshold) {
            $ratio     = $claims / $sales;
            $signals[] = $this->signal('DEALER_CLAIM_RATIO', 20, sprintf('This dealer has claimed on %.1f%% of their sales.', $ratio * 100), [
                'dealerSales' => $sales, 'dealerClaims' => $claims, 'ratio' => round($ratio, 4), 'threshold' => $threshold,
            ]);
        }

        // 4. a burst of claims from one dealer
        $recent = $count(
            'SELECT COUNT(*) n FROM warranty_claims WHERE dealer_id = ? AND deleted_at IS NULL AND submitted_at >= ?',
            [$claim['dealer_id'], gmdate('Y-m-d H:i:s', $submitted - 30 * self::DAY)],
        );
        if ($recent >= 10) {
            $signals[] = $this->signal('DEALER_CLAIM_BURST', 15, "{$recent} claims from this dealer in the last 30 days.", ['recentDealerClaims' => $recent, 'windowDays' => 30]);
        }

        // 5. the same customer (by mobile number) claiming repeatedly
        if ($claim['phone_hash'] !== null) {
            $prior     = $count(
                'SELECT COUNT(*) n FROM warranty_claims c JOIN customers cu ON cu.id = c.customer_id
                  WHERE c.id <> ? AND c.deleted_at IS NULL AND cu.phone_hash = ?',
                [$claim['id'], $claim['phone_hash']],
            );
            $threshold = (int) Settings::get('risk.repeat_customer_claim_threshold', 2);
            if ($prior >= $threshold) {
                $signals[] = $this->signal('REPEAT_CUSTOMER_CLAIMS', 20, "This customer contact number is linked to {$prior} other claim(s).", ['priorCustomerClaims' => $prior, 'threshold' => $threshold]);
            }
        }

        // 6. the same address across different customers
        if ($claim['address_hash'] !== null) {
            $same = $count(
                'SELECT COUNT(*) n FROM warranty_claims c JOIN customers cu ON cu.id = c.customer_id
                  WHERE c.id <> ? AND c.deleted_at IS NULL AND cu.address_hash = ? AND cu.id <> ?',
                [$claim['id'], $claim['address_hash'], $claim['customer_id']],
            );
            if ($same >= 2) {
                $signals[] = $this->signal('REPEAT_ADDRESS', 10, "{$same} claim(s) from other customers share this delivery address.", ['sameAddressClaims' => $same]);
            }
        }

        // 7. photographs reused from another claim
        $reused = $this->db->query(
            'SELECT COUNT(DISTINCT o.claim_id) n FROM claim_media mine
               JOIN claim_media o ON o.sha256 = mine.sha256 AND o.claim_id <> mine.claim_id
              WHERE mine.claim_id = ? AND mine.sha256 IS NOT NULL',
            [$claim['id']],
        )->getRow()->n;
        if ((int) $reused > 0) {
            $signals[] = $this->signal('DUPLICATE_PHOTO', 30, 'Uploaded image(s) are byte-identical to images on another claim.', ['matchingClaimCount' => (int) $reused]);
        }

        // 8. an impossible timeline — by DAY: an invoice date arrives as midnight
        //    and a receipt as a clock time, so a normal same-day sale must not trip it
        $day      = static fn (?string $v): ?string => $v === null ? null : substr($v, 0, 10);
        $problems = [];
        if ($day($claim['sold_at']) !== null && $day($claim['received_at']) !== null && $day($claim['sold_at']) < $day($claim['received_at'])) {
            $problems[] = 'sold before the dealer received it';
        }
        if ($day($claim['received_at']) !== null && $day($claim['dispatched_at']) !== null && $day($claim['received_at']) < $day($claim['dispatched_at'])) {
            $problems[] = 'received before it was dispatched';
        }
        if ($day($claim['sold_at']) !== null && $day($claim['submitted_at']) < $day($claim['sold_at'])) {
            $problems[] = 'claim raised before the sale date';
        }
        if ($problems !== []) {
            $signals[] = $this->signal('TIMELINE_INCONSISTENT', 25, 'The lifecycle dates do not line up: ' . implode('; ', $problems) . '.', ['timelineProblems' => $problems]);
        }

        // 9. this unit has been claimed on before
        $priorUnit = $count('SELECT COUNT(*) n FROM warranty_claims WHERE mattress_id = ? AND id <> ? AND deleted_at IS NULL', [$claim['mattress_id'], $claim['id']]);
        if ($priorUnit > 0) {
            $signals[] = $this->signal('REPEAT_CLAIM_SAME_UNIT', 15, "{$priorUnit} previous claim(s) have been raised on this same mattress.", ['priorUnitClaims' => $priorUnit]);
        }

        $score = min(100, array_sum(array_column($signals, 'weight')));

        return ['score' => $score, 'level' => self::levelFor($score), 'signals' => $signals];
    }

    /** Recomputes and stores. Called on submission and after every upload. */
    public function refresh(string $claimId): array
    {
        $assessment = $this->assess($claimId);
        $this->db->table('warranty_claims')->where('id', $claimId)->update([
            'risk_score'       => $assessment['score'],
            'risk_level'       => $assessment['level'],
            'risk_signals'     => json_encode($assessment['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'risk_computed_at' => utc_now(),
        ]);

        return $assessment;
    }

    private function signal(string $code, int $weight, string $summary, array $evidence): array
    {
        return ['code' => $code, 'weight' => $weight, 'summary' => $summary, 'evidence' => $evidence];
    }
}
