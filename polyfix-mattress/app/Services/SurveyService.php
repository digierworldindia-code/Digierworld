<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Identifiers;
use App\Libraries\RequestContext;
use App\Libraries\ScanCode;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * Dealer stock surveys: a field agent checking a dealer's shelf against the
 * records, every six months.
 *
 * The one rule that shapes everything here: an agent reports, the office
 * decides. A mattress the records call available and the dealer says was
 * sold last month might be an unrecorded sale, a mix-up, or a mattress in
 * the stockroom the agent did not find. Changing the sales record on the
 * strength of one observation would turn a maybe into a fact, and the fact
 * would be wrong often enough to matter. So a disagreement raises a
 * discrepancy for someone with the standing to settle it, and the sale,
 * warranty and lifecycle records are left exactly as they were.
 *
 * The verdict on each unit is computed from the pair (what the system
 * believed, what the agent found) and never typed:
 *
 *   system says available + found                  MATCHED
 *   system says available + dealer says sold       POSSIBLE_UNRECORDED_SALE
 *   system says available + not found              STOCK_DISCREPANCY
 *   system says sold      + found on the floor     REVIEW_REQUIRED
 *   anything              + damaged                DAMAGED
 *
 * Counts on a submitted survey are frozen onto the row, because the report
 * has to show the position on the day of the visit. Recomputing it later
 * would quietly rewrite history every time stock moved.
 */
final class SurveyService
{
    /**
     * Six months, as the business asked for.
     *
     * This is the fallback. What a new assignment actually starts at comes
     * from the `survey.default_interval_days` setting, so an administrator
     * can change it without a deploy; and the interval then lives on the
     * assignment, so changing the setting never rewrites a schedule an
     * agent is already working to.
     */
    public const DEFAULT_INTERVAL_DAYS = 182;

    /** The interval a new assignment starts at, bounded to what the column allows. */
    public static function defaultIntervalDays(): int
    {
        $days = (int) \App\Libraries\Settings::get('survey.default_interval_days', self::DEFAULT_INTERVAL_DAYS);

        return max(7, min(1095, $days));
    }

    /** A visit coming up inside this window is worth showing the agent. */
    public const UPCOMING_WINDOW_DAYS = 30;

    /** What the system believing a unit is at the dealer looks like. */
    private const AT_DEALER = ['DISPATCHED', 'DEALER_RECEIVED'];

    private const GONE = ['SOLD', 'CLAIM_OPEN', 'REPLACED'];

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
    // What the agent is responsible for
    // =========================================================================

    /**
     * The dealers assigned to an agent, each with when it is next due.
     *
     * due_state is worked out here rather than in the view, so the panel, the
     * dashboard count and the admin screen all agree on what "overdue" means.
     *
     * @return list<array<string,mixed>>
     */
    public function assignments(string $agentUserId): array
    {
        $rows = $this->db->table('agent_dealer_assignments a')
            ->select('a.id, a.dealer_id, a.survey_interval_days, a.last_surveyed_on, a.next_due_on, a.notes,'
                . ' d.business_name, d.code AS dealer_code, d.city, d.state, d.phone, d.status AS dealer_status,'
                . ' (SELECT COUNT(*) FROM dealer_surveys s WHERE s.dealer_id = a.dealer_id) AS surveys_done,'
                . ' (SELECT s2.id FROM dealer_surveys s2 WHERE s2.dealer_id = a.dealer_id'
                . "  AND s2.agent_user_id = a.agent_user_id AND s2.status = 'IN_PROGRESS' LIMIT 1) AS open_survey_id", false)
            ->join('dealers d', 'd.id = a.dealer_id')
            ->where('a.agent_user_id', $agentUserId)
            ->where('a.active', 1)
            ->where('d.deleted_at', null)
            ->orderBy('a.next_due_on IS NULL', '', false)
            ->orderBy('a.next_due_on')
            ->orderBy('d.business_name')
            ->get()->getResultArray();

        $today = local_time(utc_now(), 'Y-m-d');

        foreach ($rows as $i => $row) {
            $rows[$i]['due_state'] = $this->dueState($row['next_due_on'], $today);
        }

        return $rows;
    }

    /** OVERDUE, DUE, UPCOMING or SCHEDULED. */
    private function dueState(?string $nextDueOn, string $today): string
    {
        if ($nextDueOn === null) {
            return 'DUE';   // never surveyed: the first visit is owed
        }
        if ($nextDueOn < $today) {
            return 'OVERDUE';
        }
        if ($nextDueOn === $today) {
            return 'DUE';
        }

        return (strtotime($nextDueOn) - strtotime($today)) / 86400 <= self::UPCOMING_WINDOW_DAYS
            ? 'UPCOMING' : 'SCHEDULED';
    }

    /**
     * The agent's front screen. Every figure counted from the records.
     *
     * @return array<string,int>
     */
    public function dashboard(string $agentUserId): array
    {
        $assignments = $this->assignments($agentUserId);
        $states      = array_count_values(array_column($assignments, 'due_state'));

        $surveys = $this->db->query(
            "SELECT
               SUM(CASE WHEN status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS in_progress,
               SUM(CASE WHEN status IN ('SUBMITTED', 'REVIEWED') THEN 1 ELSE 0 END) AS completed
             FROM dealer_surveys WHERE agent_user_id = ?",
            [$agentUserId],
        )->getRowArray();

        $raised = $this->db->query(
            "SELECT
               (SELECT COUNT(*) FROM inventory_discrepancies
                 WHERE raised_by = ? AND status IN ('OPEN', 'UNDER_REVIEW')) AS open_discrepancies,
               (SELECT COUNT(*) FROM warranty_review_cases
                 WHERE raised_by = ? AND status IN ('RECOMMENDED', 'UNDER_REVIEW', 'INFORMATION_REQUESTED')) AS open_reviews",
            [$agentUserId, $agentUserId],
        )->getRowArray();

        return [
            'assigned_dealers'   => count($assignments),
            'due'                => ($states['DUE'] ?? 0) + ($states['OVERDUE'] ?? 0),
            'overdue'            => $states['OVERDUE'] ?? 0,
            'upcoming'           => $states['UPCOMING'] ?? 0,
            'surveys_open'       => (int) ($surveys['in_progress'] ?? 0),
            'surveys_completed'  => (int) ($surveys['completed'] ?? 0),
            'open_discrepancies' => (int) ($raised['open_discrepancies'] ?? 0),
            'open_reviews'       => (int) ($raised['open_reviews'] ?? 0),
        ];
    }

    /**
     * Refuses unless this dealer is assigned to this agent.
     *
     * An agent sees the dealers they cover and no others. A dealer that is
     * not theirs answers exactly as a dealer that does not exist, so the
     * refusal reveals nothing about the network.
     */
    public function assertAssigned(string $agentUserId, string $dealerId): void
    {
        $assigned = $this->db->table('agent_dealer_assignments')
            ->where(['agent_user_id' => $agentUserId, 'dealer_id' => $dealerId, 'active' => 1])
            ->countAllResults() > 0;

        if (! $assigned) {
            throw AppException::notFound('dealer', "agent {$agentUserId} is not assigned to dealer {$dealerId}");
        }
    }

    // =========================================================================
    // The dealer's stock, as the records have it
    // =========================================================================

    /**
     * Every mattress ever sent to a dealer, with its latest status and
     * whatever is known about its sale and warranty.
     *
     * Everything is counted or read from the records; nothing here is a
     * stored figure that could be stale.
     *
     * @param array{status?:?string, model?:?string, size?:?string, q?:?string,
     *              from?:?string, to?:?string, warranty?:?string} $filters
     *
     * @return list<array<string,mixed>>
     */
    public function dealerStock(string $dealerId, array $filters = [], int $limit = 1000): array
    {
        // The consignment is a correlated subquery rather than a join: a
        // mattress can appear on more than one dispatch over its life (put
        // on one, taken off, sent on another), and joining would multiply
        // the row. The latest one is the one that brought it here.
        $builder = $this->db->table('mattresses m')
            ->select('m.id, m.serial_number, m.current_status, m.dispatched_at, m.received_at, m.sold_at,'
                . ' v.size_label, v.sku, p.name AS product_name, p.warranty_years,'
                . ' b.batch_code,'
                . ' (SELECT dsp.dispatch_code FROM dispatch_items di JOIN dispatches dsp ON dsp.id = di.dispatch_id'
                . '   WHERE di.mattress_id = m.id ORDER BY di.added_at DESC LIMIT 1) AS dispatch_code,'
                . ' s.id AS sale_id, s.invoice_number, s.sold_at AS sale_date,'
                . ' w.id AS warranty_id, w.status AS warranty_status, w.end_date AS warranty_expires_on', false)
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('manufacturing_batches b', 'b.id = m.batch_id', 'left')
            ->join('sales s', 's.mattress_id = m.id AND s.deleted_at IS NULL', 'left')
            ->join('warranties w', 'w.mattress_id = m.id AND w.deleted_at IS NULL', 'left')
            ->where('m.current_dealer_id', $dealerId)
            ->where('m.deleted_at', null)
            ->orderBy('m.current_status')->orderBy('m.serial_number')
            ->limit($limit);

        if (($filters['status'] ?? null) !== null) {
            $builder->where('m.current_status', $filters['status']);
        }
        if (($filters['model'] ?? null) !== null) {
            $builder->where('p.id', $filters['model']);
        }
        if (($filters['size'] ?? null) !== null) {
            $builder->where('v.id', $filters['size']);
        }
        if (($filters['from'] ?? null) !== null) {
            $builder->where('m.dispatched_at >=', $filters['from'] . ' 00:00:00');
        }
        if (($filters['to'] ?? null) !== null) {
            $builder->where('m.dispatched_at <=', $filters['to'] . ' 23:59:59');
        }
        if (($filters['warranty'] ?? null) === 'ACTIVE') {
            $builder->where('w.status', 'ACTIVE');
        }
        if (($filters['warranty'] ?? null) === 'NONE') {
            $builder->where('w.id', null);
        }
        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['q']);
            $builder->groupStart()->like('m.serial_number', $term)->orLike('p.name', $term)->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /**
     * What the records say a dealer holds, in totals.
     *
     * @return array<string,int>
     */
    public function dealerTotals(string $dealerId): array
    {
        $row = $this->db->query(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN current_status IN ('DISPATCHED', 'DEALER_RECEIVED') THEN 1 ELSE 0 END) AS available,
               SUM(CASE WHEN current_status IN ('SOLD', 'CLAIM_OPEN', 'REPLACED') THEN 1 ELSE 0 END) AS sold,
               SUM(CASE WHEN current_status = 'DISPATCHED' THEN 1 ELSE 0 END) AS in_transit,
               SUM(CASE WHEN current_status = 'RETURNED' THEN 1 ELSE 0 END) AS returned
             FROM mattresses WHERE current_dealer_id = ? AND deleted_at IS NULL",
            [$dealerId],
        )->getRowArray();

        return array_map('intval', $row ?? []);
    }

    // =========================================================================
    // Conducting a survey
    // =========================================================================

    /**
     * Opens a survey, or returns the one already open.
     *
     * An agent who loses signal halfway round a showroom and opens the
     * screen again must carry on with the same survey, not start a second
     * one beside it — so this is deliberately idempotent.
     *
     * @return array{id:string, reference:string, resumed:bool}
     */
    public function open(string $dealerId): array
    {
        $agentId = $this->requireAgent();
        $this->assertAssigned($agentId, $dealerId);

        $existing = $this->db->table('dealer_surveys')
            ->where(['dealer_id' => $dealerId, 'agent_user_id' => $agentId, 'status' => 'IN_PROGRESS'])
            ->get()->getRowArray();

        if ($existing !== null) {
            return ['id' => $existing['id'], 'reference' => $existing['reference'], 'resumed' => true];
        }

        return Tx::run(function (BaseConnection $db) use ($dealerId, $agentId): array {
            $dealer = $db->table('dealers')->where(['id' => $dealerId, 'deleted_at' => null])->get()->getRowArray();
            if ($dealer === null) {
                throw AppException::notFound('dealer');
            }

            $id        = uuid4();
            $reference = (new Identifiers($db))->surveyReference();
            $now       = utc_now();

            $db->table('dealer_surveys')->insert([
                'id'            => $id,
                'reference'     => $reference,
                'dealer_id'     => $dealerId,
                'agent_user_id' => $agentId,
                'status'        => 'IN_PROGRESS',
                'started_at'    => $now,
                'created_at'    => $now,
            ]);

            Audit::instance($db)->record('SURVEY_STARTED', 'dealer_survey', $id, null, [
                'reference' => $reference, 'dealerId' => $dealerId,
            ]);

            return ['id' => $id, 'reference' => $reference, 'resumed' => false];
        }, $this->db);
    }

    /**
     * Records what the agent found for one mattress.
     *
     * Writing the same unit again replaces the line rather than adding
     * another — an agent who mis-taps and corrects themselves should leave
     * one observation, and the unique key on (survey, mattress) makes that
     * true even if two taps race.
     *
     * Nothing outside the survey is changed. No sale is created, no status
     * moves, no warranty is touched. Where the observation and the records
     * disagree, a discrepancy is raised for the office.
     *
     * @return array{ok:bool, status:string, title:string, message:string,
     *               detail?:array<string,string>, counter:array{done:int,total:int,label:string},
     *               outcome?:string, mattress_id?:string}
     */
    public function record(string $surveyId, string $code, string $observation, ?string $remark = null, bool $scanned = true): array
    {
        $survey = $this->openSurveyOr404($surveyId);

        if (! in_array($observation, ['PRESENT', 'DEALER_SAYS_SOLD', 'NOT_FOUND', 'DAMAGED', 'OTHER'], true)) {
            throw AppException::rule('Choose what you found for this mattress.');
        }

        $unit = ScanCode::find($this->db, $code);

        if ($unit === null) {
            return $this->reply($survey, false, 'UNKNOWN', 'Not recognised',
                'That code is not a ' . brand('shortName') . ' mattress label. Check it, or type the serial.');
        }

        // A unit that was never sent to this dealer is not theirs to account
        // for, and recording it against them would make the report wrong.
        if ($unit['current_dealer_id'] !== $survey['dealer_id']) {
            return $this->reply($survey, false, 'REJECTED', 'Not this dealer\'s stock',
                $unit['serial_number'] . ' is not recorded against this dealer. Report it as a separate finding.', $unit);
        }

        $outcome = self::outcomeFor($unit['current_status'], $observation);

        return Tx::run(function (BaseConnection $db) use ($survey, $unit, $observation, $outcome, $remark, $scanned): array {
            $now = utc_now();

            // Replace the line if this unit has been looked at already.
            $db->query(
                'INSERT INTO dealer_survey_items
                    (id, survey_id, mattress_id, system_status, observation, outcome, scanned, remark, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    system_status = VALUES(system_status), observation = VALUES(observation),
                    outcome = VALUES(outcome), scanned = VALUES(scanned),
                    remark = VALUES(remark), recorded_at = VALUES(recorded_at)',
                [
                    uuid4(), $survey['id'], $unit['id'], $unit['current_status'],
                    $observation, $outcome, $scanned ? 1 : 0,
                    ($remark ?? '') !== '' ? mb_substr($remark, 0, 500) : null, $now,
                ],
            );

            $item = $db->table('dealer_survey_items')
                ->where(['survey_id' => $survey['id'], 'mattress_id' => $unit['id']])->get()->getRowArray();

            $this->syncDiscrepancy($db, $survey, $unit, $item, $outcome, $remark);

            $reply = $this->reply($survey, true, 'ACCEPTED', self::OUTCOME_TITLE[$outcome],
                $unit['serial_number'] . ' — ' . self::OUTCOME_NOTE[$outcome], $unit);

            $reply['outcome']     = $outcome;
            $reply['mattress_id'] = $unit['id'];

            return $reply;
        }, $this->db);
    }

    /**
     * The verdict, from what the system believed and what the agent found.
     *
     * Deliberately a pure function of the two: the same pair always produces
     * the same verdict, nobody can talk it into a different one on the day,
     * and it can be reasoned about without a database.
     */
    public static function outcomeFor(string $systemStatus, string $observation): string
    {
        if ($observation === 'DAMAGED') {
            return 'DAMAGED';
        }
        if ($observation === 'OTHER') {
            return 'REVIEW_REQUIRED';
        }

        $shouldBeOnTheFloor = in_array($systemStatus, self::AT_DEALER, true);
        $recordedAsGone     = in_array($systemStatus, self::GONE, true);

        return match ($observation) {
            // Found it, and the records expected it there.
            'PRESENT' => $shouldBeOnTheFloor ? 'MATCHED'
                // Found on the floor but the records say it is sold. Either
                // the sale is wrong or this is a different mattress; both
                // need a person.
                : ($recordedAsGone ? 'REVIEW_REQUIRED' : 'REVIEW_REQUIRED'),

            // The dealer says it went. If the records agree, nothing to see.
            // If they do not, it may be a sale nobody recorded.
            'DEALER_SAYS_SOLD' => $recordedAsGone ? 'MATCHED' : 'POSSIBLE_UNRECORDED_SALE',

            // Not on the floor. If the records say it was sold, that is the
            // explanation. If they say it should be here, stock is missing.
            'NOT_FOUND' => $recordedAsGone ? 'MATCHED' : 'STOCK_DISCREPANCY',

            default => 'REVIEW_REQUIRED',
        };
    }

    private const OUTCOME_TITLE = [
        'MATCHED'                  => 'Matches the records',
        'POSSIBLE_UNRECORDED_SALE' => 'Possible unrecorded sale',
        'REVIEW_REQUIRED'          => 'Review required',
        'STOCK_DISCREPANCY'        => 'Stock discrepancy',
        'DAMAGED'                  => 'Damaged',
    ];

    private const OUTCOME_NOTE = [
        'MATCHED'                  => 'what you found agrees with the records.',
        'POSSIBLE_UNRECORDED_SALE' => 'the records still show it in stock. Raised for the office to check.',
        'REVIEW_REQUIRED'          => 'the records and what you found do not line up. Raised for the office.',
        'STOCK_DISCREPANCY'        => 'the records say it should be here. Raised for the office.',
        'DAMAGED'                  => 'recorded as damaged and raised for the office.',
    ];

    /**
     * Keeps the discrepancy record in step with the line.
     *
     * A line that disagrees with the records raises one; a line corrected to
     * MATCHED withdraws the one it raised, but only while nobody has started
     * working on it. Once the office has picked it up, the agent changing
     * their mind is a note on the case, not a deletion of it.
     *
     * @param array<string,mixed> $survey
     * @param array<string,mixed> $unit
     * @param array<string,mixed> $item
     */
    private function syncDiscrepancy(BaseConnection $db, array $survey, array $unit, array $item, string $outcome, ?string $remark): void
    {
        $existing = $db->table('inventory_discrepancies')
            ->where(['survey_item_id' => $item['id']])->get()->getRowArray();

        if ($outcome === 'MATCHED') {
            if ($existing !== null && $existing['status'] === 'OPEN') {
                $db->table('inventory_discrepancies')->where('id', $existing['id'])->delete();
            }

            return;
        }

        if ($existing !== null) {
            if (in_array($existing['status'], ['RESOLVED', 'DISMISSED'], true)) {
                return;   // settled; the agent's correction does not reopen it
            }

            $db->table('inventory_discrepancies')->where('id', $existing['id'])->update([
                'kind'          => $outcome,
                'system_status' => $unit['current_status'],
                'observation'   => $item['observation'],
                'agent_remark'  => ($remark ?? '') !== '' ? mb_substr($remark, 0, 500) : null,
            ]);

            return;
        }

        $id = uuid4();
        $db->table('inventory_discrepancies')->insert([
            'id'             => $id,
            'survey_id'      => $survey['id'],
            'survey_item_id' => $item['id'],
            'dealer_id'      => $survey['dealer_id'],
            'mattress_id'    => $unit['id'],
            'kind'           => $outcome,
            'system_status'  => $unit['current_status'],
            'observation'    => $item['observation'],
            'status'         => 'OPEN',
            'raised_by'      => $this->context->userId(),
            'raised_at'      => utc_now(),
            'agent_remark'   => ($remark ?? '') !== '' ? mb_substr($remark, 0, 500) : null,
        ]);

        Audit::instance($db)->record('DISCREPANCY_RAISED', 'inventory_discrepancy', $id, null, [
            'surveyReference' => $survey['reference'],
            'serial'          => $unit['serial_number'],
            'kind'            => $outcome,
            'systemStatus'    => $unit['current_status'],
            'observation'     => $item['observation'],
        ]);
    }

    /**
     * Closes the survey and freezes its figures.
     *
     * Everything in the dealer's stock that was not examined is counted as
     * not checked rather than as missing, because "the agent did not get to
     * it" and "it is not there" are different statements and only one of
     * them is a discrepancy.
     *
     * @return array<string,mixed>
     */
    public function submit(string $surveyId, ?string $remarks = null): array
    {
        $survey = $this->openSurveyOr404($surveyId);

        return Tx::run(function (BaseConnection $db) use ($survey, $remarks): array {
            $counts = $this->counts($survey['id'], $survey['dealer_id']);

            if ($counts['examined'] === 0) {
                throw AppException::rule('Check at least one mattress before finishing the survey.');
            }

            $now = utc_now();
            $db->table('dealer_surveys')->where('id', $survey['id'])->update([
                'status'            => 'SUBMITTED',
                'submitted_at'      => $now,
                'updated_at'        => $now,
                'system_total'      => $counts['system_total'],
                'system_available'  => $counts['system_available'],
                'system_sold'       => $counts['system_sold'],
                'physically_found'  => $counts['physically_found'],
                'reported_sold'     => $counts['reported_sold'],
                'not_found'         => $counts['not_found'],
                'damaged'           => $counts['damaged'],
                'discrepancy_count' => $counts['discrepancies'],
                'agent_remarks'     => ($remarks ?? '') !== '' ? mb_substr($remarks, 0, 4000) : null,
            ]);

            // The next visit is scheduled from the day of this one, at
            // whatever interval the assignment carries — one statement, so
            // the interval is read and applied without a round trip that
            // another update could slip between.
            $today = local_time($now, 'Y-m-d');
            $db->query(
                'UPDATE agent_dealer_assignments
                    SET last_surveyed_on = ?,
                        next_due_on      = DATE_ADD(?, INTERVAL survey_interval_days DAY),
                        updated_at       = ?
                  WHERE agent_user_id = ? AND dealer_id = ?',
                [$today, $today, $now, $survey['agent_user_id'], $survey['dealer_id']],
            );

            Audit::instance($db)->record('SURVEY_SUBMITTED', 'dealer_survey', $survey['id'], ['status' => 'IN_PROGRESS'], [
                'status'        => 'SUBMITTED',
                'reference'     => $survey['reference'],
                'examined'      => $counts['examined'],
                'discrepancies' => $counts['discrepancies'],
            ]);

            return ['reference' => $survey['reference']] + $counts;
        }, $this->db);
    }

    /**
     * The figures for a survey, counted live.
     *
     * Used while a survey is open (where live is what is wanted) and once at
     * submission to freeze them onto the row.
     *
     * @return array<string,int>
     */
    public function counts(string $surveyId, string $dealerId): array
    {
        $totals = $this->dealerTotals($dealerId);

        $byOutcome = array_column(
            $this->db->table('dealer_survey_items')->select('outcome, COUNT(*) AS n', false)
                ->where('survey_id', $surveyId)->groupBy('outcome')->get()->getResultArray(),
            'n',
            'outcome',
        );

        $byObservation = array_column(
            $this->db->table('dealer_survey_items')->select('observation, COUNT(*) AS n', false)
                ->where('survey_id', $surveyId)->groupBy('observation')->get()->getResultArray(),
            'n',
            'observation',
        );

        $examined = array_sum(array_map('intval', $byOutcome));

        return [
            'system_total'     => $totals['total'] ?? 0,
            'system_available' => $totals['available'] ?? 0,
            'system_sold'      => $totals['sold'] ?? 0,
            'examined'         => $examined,
            'not_checked'      => max(0, ($totals['total'] ?? 0) - $examined),
            'physically_found' => (int) ($byObservation['PRESENT'] ?? 0),
            'reported_sold'    => (int) ($byObservation['DEALER_SAYS_SOLD'] ?? 0),
            'not_found'        => (int) ($byObservation['NOT_FOUND'] ?? 0),
            'damaged'          => (int) ($byObservation['DAMAGED'] ?? 0),
            'matched'          => (int) ($byOutcome['MATCHED'] ?? 0),
            'discrepancies'    => $examined - (int) ($byOutcome['MATCHED'] ?? 0),
        ];
    }

    /**
     * A survey's figures: live while the visit is happening, frozen once it
     * has been submitted.
     *
     * Frozen is the point. A verification report shows the position on the
     * day of the visit, so recomputing it afterwards would rewrite the past
     * every time stock moved. Both the agent's copy and the office's go
     * through here, so the two cannot disagree.
     *
     * @param array<string,mixed> $survey
     *
     * @return array<string,int>
     */
    public static function figuresFor(self $service, array $survey): array
    {
        if ($survey['status'] === 'IN_PROGRESS') {
            return $service->counts($survey['id'], $survey['dealer_id']);
        }

        return [
            'system_total'     => (int) $survey['system_total'],
            'system_available' => (int) $survey['system_available'],
            'system_sold'      => (int) $survey['system_sold'],
            'physically_found' => (int) $survey['physically_found'],
            'reported_sold'    => (int) $survey['reported_sold'],
            'not_found'        => (int) $survey['not_found'],
            'damaged'          => (int) $survey['damaged'],
            'discrepancies'    => (int) $survey['discrepancy_count'],
            'examined'         => (int) $survey['physically_found'] + (int) $survey['reported_sold']
                + (int) $survey['not_found'] + (int) $survey['damaged'],
        ];
    }

    /**
     * Abandons a survey that was opened by mistake.
     *
     * The record stays — a visit that was started and dropped is itself worth
     * knowing — and any discrepancy nobody has picked up goes with it.
     */
    public function abandon(string $surveyId, string $reason): array
    {
        $survey = $this->openSurveyOr404($surveyId);

        if (trim($reason) === '') {
            throw AppException::rule('Say why this survey is being abandoned.');
        }

        return Tx::run(function (BaseConnection $db) use ($survey, $reason): array {
            $db->table('dealer_surveys')->where('id', $survey['id'])->update([
                'status'        => 'ABANDONED',
                'updated_at'    => utc_now(),
                'agent_remarks' => mb_substr(trim($reason), 0, 4000),
            ]);

            $db->table('inventory_discrepancies')
                ->where(['survey_id' => $survey['id'], 'status' => 'OPEN'])->delete();

            Audit::instance($db)->record('SURVEY_ABANDONED', 'dealer_survey', $survey['id'],
                ['status' => 'IN_PROGRESS'], ['status' => 'ABANDONED'], trim($reason));

            return ['reference' => $survey['reference']];
        }, $this->db);
    }

    // =========================================================================
    // Reading surveys back
    // =========================================================================

    /** @return array<string,mixed> */
    public function survey(string $surveyId): array
    {
        $survey = $this->db->table('dealer_surveys s')
            ->select('s.*, d.business_name AS dealer, d.code AS dealer_code, d.city, d.state,'
                . ' d.address_line1, d.phone, u.full_name AS agent, u.email AS agent_email,'
                . ' r.full_name AS reviewer')
            ->join('dealers d', 'd.id = s.dealer_id')
            ->join('users u', 'u.id = s.agent_user_id')
            ->join('users r', 'r.id = s.reviewed_by', 'left')
            ->where('s.id', $surveyId)->get()->getRowArray();

        if ($survey === null) {
            throw AppException::notFound('survey');
        }

        return $survey;
    }

    /** @return list<array<string,mixed>> */
    public function surveyItems(string $surveyId): array
    {
        return $this->db->table('dealer_survey_items i')
            ->select('i.*, m.serial_number, m.current_status, v.size_label, p.name AS product_name,'
                . ' disc.id AS discrepancy_id, disc.status AS discrepancy_status')
            ->join('mattresses m', 'm.id = i.mattress_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('inventory_discrepancies disc', 'disc.survey_item_id = i.id', 'left')
            ->where('i.survey_id', $surveyId)
            ->orderBy("FIELD(i.outcome, 'STOCK_DISCREPANCY', 'POSSIBLE_UNRECORDED_SALE', 'REVIEW_REQUIRED', 'DAMAGED', 'MATCHED')", '', false)
            ->orderBy('m.serial_number')
            ->get()->getResultArray();
    }

    /**
     * An agent's surveys, newest first. History is never overwritten: each
     * visit is its own row and its own report.
     *
     * @return list<array<string,mixed>>
     */
    public function history(?string $agentUserId = null, ?string $dealerId = null, int $limit = 50): array
    {
        $builder = $this->db->table('dealer_surveys s')
            ->select('s.id, s.reference, s.status, s.started_at, s.submitted_at,'
                . ' s.system_total, s.physically_found, s.discrepancy_count,'
                . ' d.business_name AS dealer, d.city, u.full_name AS agent')
            ->join('dealers d', 'd.id = s.dealer_id')
            ->join('users u', 'u.id = s.agent_user_id')
            ->orderBy('s.started_at', 'DESC')
            ->limit($limit);

        if ($agentUserId !== null) {
            $builder->where('s.agent_user_id', $agentUserId);
        }
        if ($dealerId !== null) {
            $builder->where('s.dealer_id', $dealerId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Discrepancies, for the agent who raised them or for the office.
     *
     * @return list<array<string,mixed>>
     */
    public function discrepancies(?string $raisedBy = null, ?string $status = null, int $limit = 100): array
    {
        $builder = $this->db->table('inventory_discrepancies disc')
            ->select('disc.*, m.serial_number, v.size_label, p.name AS product_name,'
                . ' d.business_name AS dealer, s.reference AS survey_reference, u.full_name AS raised_by_name')
            ->join('mattresses m', 'm.id = disc.mattress_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id')
            ->join('dealers d', 'd.id = disc.dealer_id')
            ->join('dealer_surveys s', 's.id = disc.survey_id', 'left')
            ->join('users u', 'u.id = disc.raised_by', 'left')
            ->orderBy('disc.raised_at', 'DESC')
            ->limit($limit);

        if ($raisedBy !== null) {
            $builder->where('disc.raised_by', $raisedBy);
        }
        if ($status !== null) {
            $builder->where('disc.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @return array<string,mixed> */
    private function openSurveyOr404(string $surveyId): array
    {
        $survey = $this->db->table('dealer_surveys')->where('id', $surveyId)->get()->getRowArray();

        if ($survey === null) {
            throw AppException::notFound('survey');
        }

        // An agent works on their own survey only. Someone else's answers
        // exactly as one that does not exist.
        $agentId = $this->context->userId();
        if ($survey['agent_user_id'] !== $agentId && ! $this->context->can('survey:assign')) {
            throw AppException::notFound('survey', "user {$agentId} does not own survey {$surveyId}");
        }

        if ($survey['status'] !== 'IN_PROGRESS') {
            throw AppException::rule('This survey has been ' . strtolower(humanise($survey['status']))
                . ' and cannot be changed. Start a new one if you need to check again.');
        }

        return $survey;
    }

    private function requireAgent(): string
    {
        $agentId = $this->context->userId();
        if ($agentId === null) {
            throw AppException::forbidden('Sign in to conduct a survey.');
        }

        return $agentId;
    }

    /**
     * @param array<string,mixed>      $survey
     * @param array<string,mixed>|null $unit
     *
     * @return array{ok:bool, status:string, title:string, message:string,
     *               detail?:array<string,string>, counter:array{done:int,total:int,label:string}}
     */
    private function reply(array $survey, bool $ok, string $status, string $title, string $message, ?array $unit = null): array
    {
        $counts = $this->counts($survey['id'], $survey['dealer_id']);

        $reply = [
            'ok'      => $ok,
            'status'  => $status,
            'title'   => $title,
            'message' => $message,
            'counter' => [
                'done'  => $counts['examined'],
                'total' => $counts['system_total'],
                'label' => $counts['examined'] . ' / ' . $counts['system_total'] . ' Mattresses Checked',
            ],
        ];

        if ($unit !== null) {
            $reply['detail'] = array_filter([
                'model' => $unit['product_name'] ?? null,
                'size'  => $unit['size_label'] ?? null,
                'state' => humanise((string) $unit['current_status']),
            ]);
        }

        return $reply;
    }
}
