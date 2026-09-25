<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\DealerScope;
use App\Libraries\Identifiers;
use App\Libraries\RequestContext;
use App\Libraries\Settings;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Polyfix;
use DateTimeImmutable;

/**
 * Warranty claims: submission, evidence, review, decision and replacement.
 *
 * The replacement flow keeps both mattresses and the link between them:
 *
 *     original ──▶ claim ──▶ approval ──▶ replacement
 *    (REPLACED)                          (its own life begins, SOLD)
 *
 * The original is never deleted, re-sold or re-warrantied. The replacement
 * carries the REMAINDER of the original warranty, never a fresh full term — the
 * obligation is the one originally sold.
 *
 * Claim status machine (unchanged):
 *   SUBMITTED ─┬─▶ UNDER_REVIEW ─┬─▶ APPROVED ─┬─▶ REPLACED ─▶ CLOSED
 *              ├─▶ INFO_REQUESTED ┤             └─▶ CLOSED
 *              └─▶ WITHDRAWN      └─▶ REJECTED ────▶ CLOSED
 *
 * Inspection and repair are recorded without changing that machine: an
 * inspection is a dated event on a claim under review; a repair is an approval
 * whose resolution is REPAIR (no replacement unit needed).
 */
final class ClaimService
{
    public const TRANSITIONS = [
        'SUBMITTED'      => ['UNDER_REVIEW', 'INFO_REQUESTED', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
        'UNDER_REVIEW'   => ['INFO_REQUESTED', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
        'INFO_REQUESTED' => ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'WITHDRAWN'],
        'APPROVED'       => ['REPLACED', 'CLOSED'],
        'REJECTED'       => ['CLOSED'],
        'REPLACED'       => ['CLOSED'],
        'CLOSED'         => [],
        'WITHDRAWN'      => [],
    ];

    public const CLOSED_STATES = ['REJECTED', 'CLOSED', 'WITHDRAWN', 'REPLACED'];

    public const ISSUE_CATEGORIES = [
        'SAGGING' => 'Sagging or body impressions', 'FABRIC_TEAR' => 'Fabric tear', 'FOAM_DEGRADATION' => 'Foam breaking down',
        'SPRING_FAILURE' => 'Spring failure', 'STITCHING' => 'Stitching coming apart', 'SIZE_MISMATCH' => 'Wrong size',
        'TRANSIT_DAMAGE' => 'Damaged in transit', 'OTHER' => 'Something else',
    ];

    public const RESOLUTIONS = ['REPLACEMENT' => 'Replace the mattress', 'REPAIR' => 'Repair', 'GOODWILL' => 'Goodwill settlement'];

    private Lifecycle $lifecycle;
    private RiskService $risk;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
    ) {
        $this->lifecycle = new Lifecycle($db);
        $this->risk      = new RiskService($db);
    }

    public static function instance(): self
    {
        return new self(db_connect(), service('requestContext'));
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw AppException::rule(
                'A claim that is ' . strtolower(humanise($from)) . ' cannot move to ' . strtolower(humanise($to)) . '.',
                "illegal claim transition {$from} -> {$to}",
            );
        }
    }

    // =========================================================================
    // Dealer: submit and add evidence
    // =========================================================================

    /** @param array{serial:string, issue_category:string, reported_issue:string, description:string} $input */
    public function submit(array $input): array
    {
        $scope = DealerScope::current();

        return Tx::run(function (BaseConnection $db) use ($scope, $input): array {
            $mattress = $db->query(
                'SELECT id, serial_number, current_status, current_dealer_id, sold_at FROM mattresses
                  WHERE serial_number = ? AND deleted_at IS NULL FOR UPDATE',
                [$input['serial']],
            )->getRowArray();
            $mattress = $scope->assertOwns('mattresses', $mattress, 'mattress');

            if ($mattress['current_status'] === 'CLAIM_OPEN') {
                throw AppException::conflict('There is already an open claim on this mattress.');
            }
            if ($mattress['current_status'] !== 'SOLD') {
                throw AppException::rule('A warranty claim can only be raised on a mattress that has been sold.');
            }

            $warranty = $db->table('warranties')->where(['mattress_id' => $mattress['id'], 'deleted_at' => null])
                ->orderBy('created_at', 'DESC')->get()->getRowArray();
            if ($warranty === null) {
                throw AppException::rule('No warranty record exists for this mattress.');
            }
            if ($warranty['status'] === 'VOID') {
                throw AppException::rule('The warranty on this mattress has been voided.');
            }

            // An expired warranty does NOT block submission: out-of-warranty
            // claims are a reviewable business decision, and refusing to record
            // one just moves the conversation off-system. It is flagged instead.
            $minimumDays = (int) Settings::get('warranty.claim_window_days', 0);
            if ($minimumDays > 0 && $mattress['sold_at'] !== null) {
                $days = (int) floor((time() - strtotime($mattress['sold_at'] . ' UTC')) / 86400);
                if ($days < $minimumDays) {
                    throw AppException::rule("Claims can be raised from {$minimumDays} days after the sale. This mattress was sold {$days} day(s) ago.");
                }
            }

            $id     = uuid4();
            $number = (new Identifiers($db))->claimNumber();
            $actor  = $this->context->userId();
            $now    = utc_now();

            $db->table('warranty_claims')->insert([
                'id' => $id, 'claim_number' => $number, 'mattress_id' => $mattress['id'], 'warranty_id' => $warranty['id'],
                'dealer_id' => $scope->dealerId, 'customer_id' => $warranty['customer_id'],
                'issue_category' => $input['issue_category'], 'reported_issue' => $input['reported_issue'],
                'description' => $input['description'], 'status' => 'SUBMITTED', 'risk_score' => 0,
                'submitted_by_user_id' => $actor, 'submitted_at' => $now, 'created_at' => $now,
            ]);

            $this->lifecycle->transition($mattress['id'], 'SOLD', 'CLAIM_OPEN', 'CLAIM_RAISED', $scope->dealerId, $actor, [],
                ['claimNumber' => $number, 'issueCategory' => $input['issue_category']]);
            $this->event($db, $id, 'SUBMITTED', null, 'SUBMITTED', $input['reported_issue'], false);

            $risk = $this->risk->refresh($id);

            Audit::instance($db)->record('CLAIM_SUBMITTED', 'warranty_claim', $id, null, [
                'claimNumber' => $number, 'serialNumber' => $mattress['serial_number'], 'issueCategory' => $input['issue_category'],
                'riskLevel' => $risk['level'], 'riskScore' => $risk['score'],
            ]);

            return ['id' => $id, 'claim_number' => $number, 'risk' => $risk];
        }, $this->db);
    }

    /**
     * Attaches photos, videos or an invoice. A dealer can only attach to their
     * own claim; staff can attach to any open claim.
     *
     * @param list<UploadedFile> $files
     *
     * @return array{uploaded:int, rejected:list<array{name:string, reason:string}>}
     */
    public function attachMedia(string $claimId, array $files): array
    {
        $media    = MediaService::instance();
        $limit    = config(Polyfix::class)->maxUploadsPerClaim;
        $uploaded = 0;
        $rejected = [];

        $claim = $this->loadClaimForWrite($claimId);
        if (in_array($claim['status'], self::CLOSED_STATES, true)) {
            throw AppException::rule('This claim is closed, so no further files can be added to it.');
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = mb_substr((string) $file->getClientName(), 0, 80);

            try {
                Tx::run(function (BaseConnection $db) use ($file, $claim, $media, $limit, &$uploaded): void {
                    $held = $db->table('claim_media')->where('claim_id', $claim['id'])->countAllResults();
                    if ($held >= $limit) {
                        throw AppException::rule("A claim can hold at most {$limit} files.");
                    }

                    $info = $media->inspect($file);
                    $key  = $media->store($file, $claim['id'], $info['extension']);
                    $id   = uuid4();

                    $db->table('claim_media')->insert([
                        'id' => $id, 'claim_id' => $claim['id'], 'dealer_id' => $claim['dealer_id'], 'kind' => $info['kind'],
                        'storage_key' => $key, 'mime_type' => $info['mime'], 'byte_size' => $info['bytes'],
                        'width' => $info['width'], 'height' => $info['height'], 'sha256' => $info['sha256'],
                        'uploaded_by_user_id' => $this->context->userId(), 'created_at' => utc_now(),
                    ]);
                    Audit::instance($db)->record('CLAIM_MEDIA_UPLOADED', 'warranty_claim', $claim['id'], null, [
                        'mediaId' => $id, 'kind' => $info['kind'], 'mimeType' => $info['mime'], 'bytes' => $info['bytes'],
                    ]);
                    $uploaded++;
                }, $this->db);
            } catch (AppException $e) {
                $rejected[] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        if ($uploaded > 0) {
            // A reused photograph is itself a risk signal, so re-assess.
            $this->risk->refresh($claim['id']);
        }

        return ['uploaded' => $uploaded, 'rejected' => $rejected];
    }

    /** Dealer answers an information request. */
    public function dealerReply(string $claimId, string $message): void
    {
        $scope = DealerScope::current();
        Tx::run(function (BaseConnection $db) use ($scope, $claimId, $message): void {
            $claim = $scope->assertOwns('warranty_claims',
                $db->query('SELECT * FROM warranty_claims WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$claimId])->getRowArray(), 'claim');

            // A closed claim is finished. A reply here would look answered and
            // never be read, so it is refused rather than quietly filed.
            if (in_array($claim['status'], self::CLOSED_STATES, true)) {
                throw AppException::rule('This claim is closed. Contact the warranty team if something has changed.');
            }

            $to = $claim['status'] === 'INFO_REQUESTED' ? 'UNDER_REVIEW' : $claim['status'];
            if ($to !== $claim['status']) {
                self::assertTransition($claim['status'], $to);
                $db->table('warranty_claims')->where('id', $claimId)->update(['status' => $to]);
            }
            $this->event($db, $claimId, 'DEALER_REPLY', $claim['status'], $to, $message, false);
            Audit::instance($db)->record('CLAIM_DEALER_REPLIED', 'warranty_claim', $claimId, null, ['claimNumber' => $claim['claim_number']]);
        }, $this->db);
    }

    // =========================================================================
    // Staff: review, decide, replace
    // =========================================================================

    public function addNote(string $claimId, string $note, bool $internal): void
    {
        Tx::run(function (BaseConnection $db) use ($claimId, $note, $internal): void {
            $claim = $this->lockClaim($db, $claimId);
            $to    = $claim['status'] === 'SUBMITTED' ? 'UNDER_REVIEW' : $claim['status'];
            if ($to !== $claim['status']) {
                $db->table('warranty_claims')->where('id', $claimId)->update(['status' => $to]);
            }
            $this->event($db, $claimId, 'REVIEW_NOTE', $claim['status'], $to, $note, $internal);
            Audit::instance($db)->record('CLAIM_NOTE_ADDED', 'warranty_claim', $claimId, null, ['claimNumber' => $claim['claim_number'], 'isInternal' => $internal]);
        }, $this->db);
    }

    public function requestInformation(string $claimId, string $message): void
    {
        Tx::run(function (BaseConnection $db) use ($claimId, $message): void {
            $claim = $this->lockClaim($db, $claimId);
            self::assertTransition($claim['status'], 'INFO_REQUESTED');
            $db->table('warranty_claims')->where('id', $claimId)->update(['status' => 'INFO_REQUESTED']);
            $this->event($db, $claimId, 'INFO_REQUESTED', $claim['status'], 'INFO_REQUESTED', $message, false);
            $this->notifyDealer($db, $claim, 'CLAIM_INFO_REQUESTED', "More information needed on {$claim['claim_number']}", $message);
            Audit::instance($db)->record('CLAIM_INFO_REQUESTED', 'warranty_claim', $claimId, null, ['claimNumber' => $claim['claim_number']]);
        }, $this->db);
    }

    /** Records a physical inspection. Moves a fresh claim into review; otherwise just a dated event. */
    public function scheduleInspection(string $claimId, string $date, string $note): void
    {
        $when = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($when === false) {
            throw AppException::rule('Enter the inspection date.');
        }

        Tx::run(function (BaseConnection $db) use ($claimId, $when, $note): void {
            $claim = $this->lockClaim($db, $claimId);
            if (in_array($claim['status'], self::CLOSED_STATES, true) || $claim['status'] === 'APPROVED') {
                throw AppException::rule('An inspection can only be arranged while a claim is being reviewed.');
            }
            $to = $claim['status'] === 'SUBMITTED' ? 'UNDER_REVIEW' : $claim['status'];
            if ($to !== $claim['status']) {
                $db->table('warranty_claims')->where('id', $claimId)->update(['status' => $to]);
            }
            $text = 'Inspection arranged for ' . $when->format('d M Y') . ($note !== '' ? ": {$note}" : '.');
            $this->event($db, $claimId, 'INSPECTION_SCHEDULED', $claim['status'], $to, $text, false);
            $this->notifyDealer($db, $claim, 'CLAIM_INSPECTION', "Inspection arranged for {$claim['claim_number']}", $text);
            Audit::instance($db)->record('CLAIM_INSPECTION_SCHEDULED', 'warranty_claim', $claimId, null, [
                'claimNumber' => $claim['claim_number'], 'inspectionDate' => $when->format('Y-m-d'),
            ]);
        }, $this->db);
    }

    /**
     * Approve or reject. A rejection returns the mattress to SOLD; an approval
     * keeps the claim open until it is resolved (replacement) or closed (repair,
     * goodwill). The risk indicators in force are recorded with the decision,
     * so a later reviewer sees what the decision-maker was shown.
     */
    public function decide(string $claimId, string $decision, string $reason, ?string $resolution = null): void
    {
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw AppException::rule('Choose approve or reject.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw AppException::rule('Write the reason for this decision. The dealer sees it.');
        }
        if ($decision === 'APPROVED' && ! array_key_exists((string) $resolution, self::RESOLUTIONS)) {
            throw AppException::rule('Choose how an approved claim will be resolved.');
        }

        Tx::run(function (BaseConnection $db) use ($claimId, $decision, $reason, $resolution): void {
            $claim = $this->lockClaim($db, $claimId);
            self::assertTransition($claim['status'], $decision);
            $now = utc_now();

            $db->table('warranty_claims')->where('id', $claimId)->update([
                'status' => $decision, 'reviewed_by_user_id' => $this->context->userId(), 'decision_at' => $now,
                'decision_reason' => $reason, 'resolution' => $decision === 'APPROVED' ? $resolution : null,
            ]);

            if ($decision === 'REJECTED') {
                $status = $db->table('mattresses')->select('current_status')->where('id', $claim['mattress_id'])->get()->getRow()->current_status;
                $this->lifecycle->transition($claim['mattress_id'], $status, 'SOLD', 'CLAIM_REJECTED', $claim['dealer_id'],
                    $this->context->userId(), [], ['claimNumber' => $claim['claim_number']]);
            }

            $this->event($db, $claimId, $decision, $claim['status'], $decision, $reason, false);
            $this->notifyDealer($db, $claim, "CLAIM_{$decision}", "Claim {$claim['claim_number']} " . strtolower($decision), $reason);

            $serial = $db->table('mattresses')->select('serial_number')->where('id', $claim['mattress_id'])->get()->getRow()->serial_number;
            Audit::instance($db)->record("CLAIM_{$decision}", 'warranty_claim', $claimId, ['status' => $claim['status']], [
                'status' => $decision, 'serialNumber' => $serial, 'resolution' => $resolution,
                'riskLevelAtDecision' => $claim['risk_level'], 'riskScoreAtDecision' => (int) $claim['risk_score'],
            ], $reason);
        }, $this->db);
    }

    /** Closes an approved (repair/goodwill) or rejected claim. */
    public function close(string $claimId, string $note): void
    {
        Tx::run(function (BaseConnection $db) use ($claimId, $note): void {
            $claim = $this->lockClaim($db, $claimId);
            self::assertTransition($claim['status'], 'CLOSED');

            // An approved claim resolved without a replacement returns the unit to SOLD.
            if ($claim['status'] === 'APPROVED') {
                $status = $db->table('mattresses')->select('current_status')->where('id', $claim['mattress_id'])->get()->getRow()->current_status;
                if ($status === 'CLAIM_OPEN') {
                    $this->lifecycle->transition($claim['mattress_id'], 'CLAIM_OPEN', 'SOLD', 'CLAIM_RESOLVED', $claim['dealer_id'],
                        $this->context->userId(), [], ['claimNumber' => $claim['claim_number'], 'resolution' => $claim['resolution']]);
                }
            }

            $db->table('warranty_claims')->where('id', $claimId)->update(['status' => 'CLOSED', 'closed_at' => utc_now()]);
            $this->event($db, $claimId, 'CLOSED', $claim['status'], 'CLOSED', $note !== '' ? $note : null, false);
            Audit::instance($db)->record('CLAIM_CLOSED', 'warranty_claim', $claimId, ['status' => $claim['status']], ['status' => 'CLOSED'], $note ?: null);
        }, $this->db);
    }

    /**
     * Issues a replacement unit against an approved claim.
     */
    public function issueReplacement(string $claimId, string $replacementSerial, ?string $remarks = null): array
    {
        return Tx::run(function (BaseConnection $db) use ($claimId, $replacementSerial, $remarks): array {
            $claim = $this->lockClaim($db, $claimId);
            if ($claim['status'] !== 'APPROVED') {
                throw AppException::rule('A replacement can only be issued against an approved claim.');
            }
            if ($db->table('replacements')->where('claim_id', $claimId)->countAllResults() > 0) {
                throw AppException::conflict('A replacement has already been issued for this claim.');
            }

            $original = $db->query('SELECT id, serial_number, current_status FROM mattresses WHERE id = ? FOR UPDATE', [$claim['mattress_id']])->getRowArray();
            $new      = $db->query(
                'SELECT m.id, m.serial_number, m.current_status, m.current_dealer_id, p.warranty_years
                   FROM mattresses m JOIN product_variants v ON v.id = m.product_variant_id JOIN products p ON p.id = v.product_id
                  WHERE m.serial_number = ? AND m.deleted_at IS NULL FOR UPDATE',
                [trim($replacementSerial)],
            )->getRowArray();

            if ($new === null) {
                throw AppException::notFound('replacement mattress');
            }
            if ($new['id'] === $original['id']) {
                throw AppException::rule('The replacement must be a different mattress from the one being claimed.');
            }
            if (! in_array($new['current_status'], ['MANUFACTURED', 'DEALER_RECEIVED', 'RETURNED'], true)) {
                throw AppException::rule('The replacement unit is ' . strtolower(humanise($new['current_status'])) . ' and is not free stock.');
            }

            $warranty = $claim['warranty_id'] === null ? null
                : $db->table('warranties')->where('id', $claim['warranty_id'])->get()->getRowArray();
            $actor = $this->context->userId();
            $now   = utc_now();
            $today = utc_today();

            // --- the replacement takes custody at the dealer ----------------------
            $db->table('mattresses')->where('id', $new['id'])->update([
                'current_status' => 'DEALER_RECEIVED', 'current_dealer_id' => $claim['dealer_id'], 'current_warehouse_id' => null,
                'received_at' => $now, 'is_replacement' => 1, 'replacement_for_claim_id' => $claimId, 'updated_by' => $actor,
            ]);
            $this->lifecycle->event($new['id'], 'ISSUED_AS_REPLACEMENT', $new['current_status'], 'DEALER_RECEIVED', $claim['dealer_id'], $actor,
                ['claimNumber' => $claim['claim_number'], 'replacesSerial' => $original['serial_number']]);

            // --- handed to the customer: a zero-value sale, so warranty activation
            //     and dealer stock figures follow the one normal path ------------
            $saleId = uuid4();
            $db->table('sales')->insert([
                'id' => $saleId, 'dealer_id' => $claim['dealer_id'], 'mattress_id' => $new['id'], 'customer_id' => $claim['customer_id'],
                'invoice_number' => 'REP-' . $claim['claim_number'], 'sold_at' => $now, 'sale_price' => '0.00', 'payment_mode' => 'OTHER',
                'sold_by_user_id' => $actor, 'created_by' => $actor,
                'remarks' => "Warranty replacement issued against claim {$claim['claim_number']} for {$original['serial_number']}",
            ]);

            // The remainder of the original term — never a fresh full term.
            $end = ($warranty !== null && $warranty['end_date'] > $today)
                ? $warranty['end_date']
                : (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
            $db->table('warranties')->insert([
                'id' => uuid4(), 'mattress_id' => $new['id'], 'sale_id' => $saleId, 'dealer_id' => $claim['dealer_id'],
                'customer_id' => $claim['customer_id'], 'start_date' => $today, 'end_date' => $end,
                'years' => (int) ($warranty['years'] ?? $new['warranty_years']), 'status' => 'ACTIVE',
                'terms_version' => $warranty['terms_version'] ?? 'v1.0', 'created_by' => $actor,
            ]);

            $this->lifecycle->transition($new['id'], 'DEALER_RECEIVED', 'SOLD', 'REPLACEMENT_HANDED_OVER', $claim['dealer_id'], $actor,
                ['sold_at' => $now], ['claimNumber' => $claim['claim_number'], 'warrantyEnd' => $end]);

            // --- the original is retired, never removed ---------------------------
            $this->lifecycle->transition($original['id'], $original['current_status'], 'REPLACED', 'REPLACED', $claim['dealer_id'], $actor,
                [], ['claimNumber' => $claim['claim_number'], 'replacedBySerial' => $new['serial_number']]);
            if ($warranty !== null) {
                $db->table('warranties')->where('id', $warranty['id'])->update(['status' => 'SUPERSEDED']);
            }

            $db->table('replacements')->insert([
                'id' => uuid4(), 'claim_id' => $claimId, 'original_mattress_id' => $original['id'], 'replacement_mattress_id' => $new['id'],
                'dealer_id' => $claim['dealer_id'], 'approved_by_user_id' => $actor, 'issued_at' => $now,
                'remarks' => ($remarks ?? '') !== '' ? $remarks : null, 'created_at' => $now,
            ]);

            self::assertTransition('APPROVED', 'REPLACED');
            $db->table('warranty_claims')->where('id', $claimId)->update(['status' => 'REPLACED', 'closed_at' => $now]);
            $this->event($db, $claimId, 'REPLACEMENT_ISSUED', 'APPROVED', 'REPLACED', "Replaced with {$new['serial_number']}", false);
            $this->notifyDealer($db, $claim, 'CLAIM_REPLACED', "Replacement issued for {$claim['claim_number']}",
                "{$new['serial_number']} replaces {$original['serial_number']}. Warranty runs to {$end}.");

            Audit::instance($db)->record('REPLACEMENT_ISSUED', 'warranty_claim', $claimId, null, [
                'claimNumber' => $claim['claim_number'], 'originalSerial' => $original['serial_number'],
                'replacementSerial' => $new['serial_number'], 'warrantyEnd' => $end,
            ], $remarks);

            return ['original' => $original['serial_number'], 'replacement' => $new['serial_number'], 'warranty_end' => $end];
        }, $this->db);
    }

    public function recomputeRisk(string $claimId): array
    {
        $this->lockClaim($this->db, $claimId, false);

        return $this->risk->refresh($claimId);
    }

    // =========================================================================

    /** Staff may act on any claim; a dealer only on their own. */
    private function loadClaimForWrite(string $claimId): array
    {
        $row = $this->db->table('warranty_claims')->where(['id' => $claimId, 'deleted_at' => null])->get()->getRowArray();

        return $this->context->isDealer()
            ? DealerScope::current()->assertOwns('warranty_claims', $row, 'claim')
            : ($row ?? throw AppException::notFound('claim'));
    }

    private function lockClaim(BaseConnection $db, string $claimId, bool $forUpdate = true): array
    {
        $claim = $db->query('SELECT * FROM warranty_claims WHERE id = ? AND deleted_at IS NULL' . ($forUpdate ? ' FOR UPDATE' : ''), [$claimId])->getRowArray();

        return $claim ?? throw AppException::notFound('claim');
    }

    private function event(BaseConnection $db, string $claimId, string $type, ?string $from, ?string $to, ?string $note, bool $internal): void
    {
        $db->table('claim_events')->insert([
            'claim_id' => $claimId, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $this->context->userId(), 'note' => $note, 'is_internal' => $internal ? 1 : 0, 'created_at' => utc_now(),
        ]);
    }

    private function notifyDealer(BaseConnection $db, array $claim, string $type, string $title, string $body): void
    {
        $db->table('notifications')->insert([
            'id' => uuid4(), 'dealer_id' => $claim['dealer_id'], 'channel' => 'IN_APP', 'type' => $type,
            'title' => mb_substr($title, 0, 200), 'body' => $body, 'entity' => 'warranty_claim', 'entity_id' => $claim['id'], 'created_at' => utc_now(),
        ]);
    }
}
