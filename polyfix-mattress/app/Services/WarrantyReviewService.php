<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\DealerScope;
use App\Libraries\Identifiers;
use App\Libraries\RequestContext;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * Warranty rejection: a recommendation, and the decision on it.
 *
 * A field agent standing in a showroom can see that something is wrong with
 * a warranty — no invoice was ever uploaded, the sale was never registered,
 * the invoice does not match the unit. What they must not be able to do is
 * cancel it. A customer who bought a mattress in good faith should not lose
 * ten years of cover because of one visit and one tap, and the person best
 * placed to check the paperwork is not the person standing in the shop.
 *
 * So the agent opens a case. It sits at RECOMMENDED until somebody with
 * warranty_review:decide looks at it, and they have four ways out:
 *
 *   uphold    the recommendation stands; the warranty is voided, with a
 *             reason, through the same service that voids any warranty
 *   dismiss   the warranty is untouched and stays exactly as it was
 *   ask       the dealer is asked for the missing invoice and the case waits
 *   comment   a note, visible to the dealer or not
 *
 * The dealer sees the decision and its reason, and can upload what was
 * missing. Every step is appended to warranty_review_events, which is never
 * edited or deleted, so the history of a contested warranty can be read end
 * to end afterwards.
 */
final class WarrantyReviewService
{
    public const REASONS = [
        'INVOICE_NOT_UPLOADED'       => 'Sale invoice not uploaded',
        'SALE_NOT_REGISTERED'        => 'Sale not registered in the system',
        'INVOICE_MISMATCH'           => 'Invoice details do not match',
        'INCORRECT_SALE_INFORMATION' => 'Incorrect sale information',
        'OTHER'                      => 'Other',
    ];

    /** A case nobody has decided yet. */
    public const OPEN_STATUSES = ['RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
    ) {
    }

    public static function instance(): self
    {
        return new self(db_connect(), service('requestContext'));
    }

    // =========================================================================
    // The agent's half: raising it
    // =========================================================================

    /**
     * Opens a case recommending that a warranty be reviewed for rejection.
     *
     * Nothing about the warranty changes. The only thing that happens is
     * that somebody is asked to look at it.
     *
     * @return array{id:string, reference:string}
     */
    public function recommend(string $mattressId, string $reason, ?string $remark = null, ?string $surveyId = null): array
    {
        if (! isset(self::REASONS[$reason])) {
            throw AppException::rule('Choose a reason for the review.');
        }
        if ($reason === 'OTHER' && trim((string) $remark) === '') {
            throw AppException::rule('Say what the problem is when the reason is "Other".');
        }

        return Tx::run(function (BaseConnection $db) use ($mattressId, $reason, $remark, $surveyId): array {
            $unit = $db->table('mattresses m')
                ->select('m.id, m.serial_number, m.current_dealer_id, m.current_status,'
                    . ' w.id AS warranty_id, w.status AS warranty_status')
                ->join('warranties w', 'w.mattress_id = m.id AND w.deleted_at IS NULL', 'left')
                ->where(['m.id' => $mattressId, 'm.deleted_at' => null])
                ->get()->getRowArray();

            if ($unit === null) {
                throw AppException::notFound('mattress');
            }
            if ($unit['current_dealer_id'] === null) {
                throw AppException::rule('This mattress is not at a dealer, so there is no dealer warranty to review.');
            }

            // An agent raises cases only for dealers they cover.
            if ($this->context->can('survey:conduct') && ! $this->context->can('warranty_review:decide')) {
                (new SurveyService($db, $this->context))->assertAssigned(
                    (string) $this->context->userId(),
                    $unit['current_dealer_id'],
                );
            }

            // One open case per mattress. A second agent seeing the same
            // problem should add to the case, not start a parallel one that
            // gets a different answer.
            $open = $db->table('warranty_review_cases')
                ->where('mattress_id', $mattressId)
                ->whereIn('status', self::OPEN_STATUSES)
                ->get()->getRowArray();

            if ($open !== null) {
                throw AppException::rule(
                    'There is already an open review on ' . $unit['serial_number'] . ' (' . $open['reference'] . '). '
                    . 'Add a note to that one rather than opening a second.',
                );
            }

            $id        = uuid4();
            $reference = (new Identifiers($db))->warrantyReviewReference();
            $now       = utc_now();

            $db->table('warranty_review_cases')->insert([
                'id'           => $id,
                'reference'    => $reference,
                'mattress_id'  => $mattressId,
                'warranty_id'  => $unit['warranty_id'],
                'dealer_id'    => $unit['current_dealer_id'],
                'survey_id'    => $surveyId,
                'raised_by'    => $this->context->userId(),
                'raised_at'    => $now,
                'reason'       => $reason,
                'agent_remark' => ($remark ?? '') !== '' ? mb_substr($remark, 0, 1000) : null,
                'status'       => 'RECOMMENDED',
                'created_at'   => $now,
            ]);

            $this->event($db, $id, 'RECOMMENDED', null, 'RECOMMENDED',
                self::REASONS[$reason] . (($remark ?? '') !== '' ? ' — ' . $remark : ''), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_RAISED', 'warranty_review_case', $id, null, [
                'reference' => $reference, 'serial' => $unit['serial_number'], 'reason' => $reason,
            ]);

            return ['id' => $id, 'reference' => $reference];
        }, $this->db);
    }

    /** The agent who raised a case may withdraw it, while nobody has decided. */
    public function withdraw(string $caseId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('Say why the recommendation is being withdrawn.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $reason): array {
            $case = $this->openCaseForUpdate($db, $caseId);

            if ($case['raised_by'] !== $this->context->userId() && ! $this->context->can('warranty_review:decide')) {
                throw AppException::forbidden('Only the agent who raised this recommendation can withdraw it.');
            }

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status' => 'WITHDRAWN', 'updated_at' => utc_now(),
            ]);

            $this->event($db, $caseId, 'WITHDRAWN', $case['status'], 'WITHDRAWN', trim($reason), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_WITHDRAWN', 'warranty_review_case', $caseId,
                ['status' => $case['status']], ['status' => 'WITHDRAWN'], trim($reason));

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    // =========================================================================
    // The reviewer's half: deciding it
    // =========================================================================

    /** Picks a case up, so the agent and the dealer can see somebody is on it. */
    public function take(string $caseId): array
    {
        return Tx::run(function (BaseConnection $db) use ($caseId): array {
            $case = $this->openCaseForUpdate($db, $caseId);

            if ($case['status'] === 'UNDER_REVIEW') {
                return ['reference' => $case['reference']];
            }

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status' => 'UNDER_REVIEW', 'updated_at' => utc_now(),
            ]);

            $this->event($db, $caseId, 'TAKEN_UP', $case['status'], 'UNDER_REVIEW', null, true);

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    /** Asks the dealer for what is missing. The case waits; nothing is decided. */
    public function requestInformation(string $caseId, string $message): array
    {
        if (trim($message) === '') {
            throw AppException::rule('Say what the dealer needs to provide.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $message): array {
            $case = $this->openCaseForUpdate($db, $caseId);

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status'              => 'INFORMATION_REQUESTED',
                'dealer_visible_note' => mb_substr(trim($message), 0, 1000),
                'updated_at'          => utc_now(),
            ]);

            $this->event($db, $caseId, 'INFORMATION_REQUESTED', $case['status'], 'INFORMATION_REQUESTED', trim($message), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_INFO_REQUESTED', 'warranty_review_case', $caseId, null, [
                'reference' => $case['reference'],
            ]);

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    /** A note on the case. `$dealerVisible` decides whether the dealer sees it. */
    public function comment(string $caseId, string $note, bool $dealerVisible = false): array
    {
        if (trim($note) === '') {
            throw AppException::rule('Write something before adding a note.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $note, $dealerVisible): array {
            $case = $this->caseOr404($caseId);

            $this->event($db, $caseId, 'COMMENT', $case['status'], $case['status'], trim($note), $dealerVisible);

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    /**
     * Upholds the recommendation: the warranty is voided.
     *
     * This is the only path by which a survey can end a warranty, and it
     * goes through the ordinary voiding service, so the warranty record,
     * its audit entry and anything watching warranties all behave exactly as
     * they would for a void decided any other way.
     */
    public function uphold(string $caseId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('A warranty cannot be rejected without a reason the customer could be shown.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $reason): array {
            $case = $this->openCaseForUpdate($db, $caseId);
            $now  = utc_now();

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status'              => 'UPHELD',
                'decided_by'          => $this->context->userId(),
                'decided_at'          => $now,
                'decision_reason'     => mb_substr(trim($reason), 0, 1000),
                'dealer_visible_note' => mb_substr(trim($reason), 0, 1000),
                'updated_at'          => $now,
            ]);

            $voided = false;
            if ($case['warranty_id'] !== null) {
                $warranty = $db->table('warranties')->where('id', $case['warranty_id'])->get()->getRowArray();

                if ($warranty !== null && $warranty['status'] === 'ACTIVE') {
                    $db->table('warranties')->where('id', $case['warranty_id'])->update([
                        'status'      => 'VOID',
                        'void_reason' => 'Warranty review ' . $case['reference'] . ': ' . trim($reason),
                        'updated_by'  => $this->context->userId(),
                    ]);
                    $voided = true;
                }
            }

            $this->event($db, $caseId, 'UPHELD', $case['status'], 'UPHELD', trim($reason), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_UPHELD', 'warranty_review_case', $caseId,
                ['status' => $case['status']],
                ['status' => 'UPHELD', 'warrantyVoided' => $voided, 'reference' => $case['reference']],
                trim($reason));

            return ['reference' => $case['reference'], 'warranty_voided' => $voided];
        }, $this->db);
    }

    /** Dismisses the recommendation. The warranty is untouched. */
    public function dismiss(string $caseId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('Say why the recommendation is being dismissed.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $reason): array {
            $case = $this->openCaseForUpdate($db, $caseId);
            $now  = utc_now();

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status'              => 'DISMISSED',
                'decided_by'          => $this->context->userId(),
                'decided_at'          => $now,
                'decision_reason'     => mb_substr(trim($reason), 0, 1000),
                'dealer_visible_note' => mb_substr(trim($reason), 0, 1000),
                'updated_at'          => $now,
            ]);

            $this->event($db, $caseId, 'DISMISSED', $case['status'], 'DISMISSED', trim($reason), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_DISMISSED', 'warranty_review_case', $caseId,
                ['status' => $case['status']], ['status' => 'DISMISSED'], trim($reason));

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    /**
     * Restores a warranty that was voided by an upheld case.
     *
     * Mistakes happen, and a customer whose cover was removed in error has
     * to be able to get it back without anyone editing the database. The
     * case is reopened as dismissed so the record reads as what it is: a
     * recommendation that was upheld and then overturned.
     */
    public function restore(string $caseId, string $reason): array
    {
        if (trim($reason) === '') {
            throw AppException::rule('Say why the warranty is being restored.');
        }

        return Tx::run(function (BaseConnection $db) use ($caseId, $reason): array {
            $case = $this->caseOr404($caseId);

            if ($case['status'] !== 'UPHELD') {
                throw AppException::rule('Only a warranty rejected by this review can be restored here.');
            }

            $now      = utc_now();
            $restored = false;

            if ($case['warranty_id'] !== null) {
                $warranty = $db->table('warranties')->where('id', $case['warranty_id'])->get()->getRowArray();

                if ($warranty !== null && $warranty['status'] === 'VOID') {
                    // Back to ACTIVE or EXPIRED according to its own end
                    // date — restoring cover must not extend it.
                    $status = $warranty['end_date'] >= local_time($now, 'Y-m-d') ? 'ACTIVE' : 'EXPIRED';

                    $db->table('warranties')->where('id', $case['warranty_id'])->update([
                        'status'      => $status,
                        'void_reason' => null,
                        'updated_by'  => $this->context->userId(),
                    ]);
                    $restored = true;
                }
            }

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'status'              => 'DISMISSED',
                'decided_by'          => $this->context->userId(),
                'decided_at'          => $now,
                'decision_reason'     => mb_substr('Restored: ' . trim($reason), 0, 1000),
                'dealer_visible_note' => mb_substr('Restored: ' . trim($reason), 0, 1000),
                'updated_at'          => $now,
            ]);

            $this->event($db, $caseId, 'RESTORED', 'UPHELD', 'DISMISSED', trim($reason), true);

            Audit::instance($db)->record('WARRANTY_REVIEW_RESTORED', 'warranty_review_case', $caseId,
                ['status' => 'UPHELD'], ['status' => 'DISMISSED', 'warrantyRestored' => $restored], trim($reason));

            return ['reference' => $case['reference'], 'warranty_restored' => $restored];
        }, $this->db);
    }

    // =========================================================================
    // The dealer's half: answering it
    // =========================================================================

    /**
     * The dealer supplies the invoice that was asked for.
     *
     * The case goes back to UNDER_REVIEW: the dealer answering is not the
     * same as the question being settled.
     */
    public function attachInvoice(string $caseId, string $mediaId, ?string $note = null): array
    {
        return Tx::run(function (BaseConnection $db) use ($caseId, $mediaId, $note): array {
            $case = $this->openCaseForUpdate($db, $caseId);

            // A dealer answers only on their own case. assertOwns takes the
            // row, not the id: a case belonging to someone else then reads
            // exactly as a case that does not exist.
            if ($this->context->isDealer()) {
                DealerScope::current()->assertOwns('warranty_review_cases', $case, 'warranty review');
            }

            $db->table('warranty_review_cases')->where('id', $caseId)->update([
                'invoice_media_id' => $mediaId,
                'status'           => 'UNDER_REVIEW',
                'updated_at'       => utc_now(),
            ]);

            $this->event($db, $caseId, 'INVOICE_UPLOADED', $case['status'], 'UNDER_REVIEW',
                ($note ?? '') !== '' ? trim($note) : 'Invoice uploaded by the dealer.', true);

            Audit::instance($db)->record('WARRANTY_REVIEW_INVOICE_UPLOADED', 'warranty_review_case', $caseId, null, [
                'reference' => $case['reference'],
            ]);

            return ['reference' => $case['reference']];
        }, $this->db);
    }

    // =========================================================================
    // Reading cases back
    // =========================================================================

    /**
     * Cases, filtered for whoever is asking.
     *
     * @return list<array<string,mixed>>
     */
    public function cases(array $filters = [], int $limit = 100): array
    {
        $builder = $this->db->table('warranty_review_cases c')
            ->select('c.*, m.serial_number, v.size_label, p.name AS product_name,'
                . ' d.business_name AS dealer, d.city AS dealer_city,'
                . ' u.full_name AS raised_by_name, dec.full_name AS decided_by_name,'
                . ' s.reference AS survey_reference,'
                . ' sale.invoice_number, sale.sold_at AS sale_date,'
                . ' w.status AS warranty_status, w.end_date AS warranty_end_date')
            ->join('mattresses m', 'm.id = c.mattress_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = c.dealer_id')
            ->join('users u', 'u.id = c.raised_by', 'left')
            ->join('users dec', 'dec.id = c.decided_by', 'left')
            ->join('dealer_surveys s', 's.id = c.survey_id', 'left')
            ->join('sales sale', 'sale.mattress_id = m.id AND sale.deleted_at IS NULL', 'left')
            ->join('warranties w', 'w.id = c.warranty_id', 'left')
            ->orderBy('c.raised_at', 'DESC')
            ->limit($limit);

        if (($filters['dealer_id'] ?? null) !== null) {
            $builder->where('c.dealer_id', $filters['dealer_id']);
        }
        if (($filters['raised_by'] ?? null) !== null) {
            $builder->where('c.raised_by', $filters['raised_by']);
        }
        if (($filters['status'] ?? null) !== null) {
            $builder->where('c.status', $filters['status']);
        }
        if (($filters['open'] ?? false) === true) {
            $builder->whereIn('c.status', self::OPEN_STATUSES);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed> */
    public function case(string $caseId): array
    {
        $row = $this->db->table('warranty_review_cases c')
            ->select('c.*, m.serial_number, m.current_status AS mattress_status,'
                . ' v.size_label, p.name AS product_name,'
                . ' d.business_name AS dealer, d.city AS dealer_city, d.phone AS dealer_phone,'
                . ' u.full_name AS raised_by_name, dec.full_name AS decided_by_name,'
                . ' s.reference AS survey_reference,'
                . ' sale.invoice_number, sale.sold_at AS sale_date, sale.sale_price,'
                . ' w.status AS warranty_status, w.start_date AS warranty_start_date, w.end_date AS warranty_end_date')
            ->join('mattresses m', 'm.id = c.mattress_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = c.dealer_id')
            ->join('users u', 'u.id = c.raised_by', 'left')
            ->join('users dec', 'dec.id = c.decided_by', 'left')
            ->join('dealer_surveys s', 's.id = c.survey_id', 'left')
            ->join('sales sale', 'sale.mattress_id = m.id AND sale.deleted_at IS NULL', 'left')
            ->join('warranties w', 'w.id = c.warranty_id', 'left')
            ->where('c.id', $caseId)->get()->getRowArray();

        if ($row === null) {
            throw AppException::notFound('warranty review');
        }

        return $row;
    }

    /**
     * The history of a case. `$dealerVisibleOnly` is what a dealer sees:
     * the decisions and the messages addressed to them, not the reviewer's
     * internal notes.
     *
     * @return list<array<string,mixed>>
     */
    public function events(string $caseId, bool $dealerVisibleOnly = false): array
    {
        $builder = $this->db->table('warranty_review_events e')
            ->select('e.*, u.full_name AS actor')
            ->join('users u', 'u.id = e.actor_id', 'left')
            ->where('e.case_id', $caseId)
            ->orderBy('e.occurred_at');

        if ($dealerVisibleOnly) {
            $builder->where('e.dealer_visible', 1);
        }

        return $builder->get()->getResultArray();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Appends to the history. Never updated, never deleted. */
    private function event(
        BaseConnection $db,
        string $caseId,
        string $event,
        ?string $from,
        ?string $to,
        ?string $note,
        bool $dealerVisible,
    ): void {
        $db->table('warranty_review_events')->insert([
            'id'             => uuid4(),
            'case_id'        => $caseId,
            'actor_id'       => $this->context->userId(),
            'actor_role'     => $this->context->primaryRole(),
            'event'          => $event,
            'from_status'    => $from,
            'to_status'      => $to,
            'note'           => $note,
            'dealer_visible' => $dealerVisible ? 1 : 0,
            'occurred_at'    => utc_now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function caseOr404(string $caseId): array
    {
        $case = $this->db->table('warranty_review_cases')->where('id', $caseId)->get()->getRowArray();

        if ($case === null) {
            throw AppException::notFound('warranty review');
        }

        return $case;
    }

    /**
     * Locks a case that is still open.
     *
     * @return array<string,mixed>
     */
    private function openCaseForUpdate(BaseConnection $db, string $caseId): array
    {
        $case = $db->query('SELECT * FROM warranty_review_cases WHERE id = ? FOR UPDATE', [$caseId])->getRowArray();

        if ($case === null) {
            throw AppException::notFound('warranty review');
        }
        if (! in_array($case['status'], self::OPEN_STATUSES, true)) {
            throw AppException::rule('This review has already been ' . strtolower(humanise($case['status'])) . '.');
        }

        return $case;
    }
}
