<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Tx;
use App\Services\SurveyService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The office's side of the survey programme: who covers which dealers, how
 * often, what each visit found, and what to do about the discrepancies.
 *
 * The agent panel raises; this decides.
 */
class Surveys extends AdminController
{
    /** Every survey, newest first, with how far each visit got. */
    public function index(): string
    {
        $builder = db_connect()->table('dealer_surveys s')
            ->select('s.id, s.reference, s.status, s.started_at, s.submitted_at,'
                . ' s.system_total, s.physically_found, s.discrepancy_count,'
                . ' d.business_name AS dealer, d.city, u.full_name AS agent')
            ->join('dealers d', 'd.id = s.dealer_id')
            ->join('users u', 'u.id = s.agent_user_id')
            ->orderBy('s.started_at', 'DESC');

        if (($status = $this->oneOf('status', ['IN_PROGRESS', 'SUBMITTED', 'REVIEWED', 'ABANDONED'])) !== null) {
            $builder->where('s.status', $status);
        }
        if (($agent = $this->filter('agent', 36)) !== null && is_uuid($agent)) {
            $builder->where('s.agent_user_id', $agent);
        }

        return $this->render('admin/surveys/index', 'Stock surveys', 'admin/surveys', [
            'list'   => $this->paginate($builder),
            'status' => $status,
            'agent'  => $agent,
            'agents' => $this->agents(),
        ]);
    }

    /** One visit, in full. */
    public function show(string $id): string
    {
        $service = SurveyService::instance();
        $survey  = $service->survey($id);

        return $this->render('admin/surveys/show', 'Survey ' . $survey['reference'], 'admin/surveys', [
            's'      => $survey,
            'items'  => $service->surveyItems($id),
            'counts' => $survey['status'] === 'IN_PROGRESS'
                ? $service->counts($id, $survey['dealer_id'])
                : [
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
                ],
        ]);
    }

    /**
     * Marks a submitted survey as read.
     *
     * It changes nothing about the findings — the discrepancies are worked
     * through on their own screen. It records that somebody in the office
     * has looked, which is what an agent wants to know.
     */
    public function markReviewed(string $id): RedirectResponse
    {
        return $this->act(
            function () use ($id) {
                return Tx::run(function (BaseConnection $db) use ($id) {
                    $survey = $db->table('dealer_surveys')->where('id', $id)->get()->getRowArray();
                    if ($survey === null) {
                        throw AppException::notFound('survey');
                    }
                    if ($survey['status'] !== 'SUBMITTED') {
                        throw AppException::rule('Only a submitted survey can be marked as reviewed.');
                    }

                    $db->table('dealer_surveys')->where('id', $id)->update([
                        'status' => 'REVIEWED', 'reviewed_at' => utc_now(),
                        'reviewed_by' => $this->ctx->userId(), 'updated_at' => utc_now(),
                    ]);

                    Audit::instance($db)->record('SURVEY_REVIEWED', 'dealer_survey', $id,
                        ['status' => 'SUBMITTED'], ['status' => 'REVIEWED', 'reference' => $survey['reference']]);

                    return ['reference' => $survey['reference']];
                });
            },
            static fn ($r) => "Survey {$r['reference']} marked as reviewed.",
            site_url('admin/surveys/' . $id),
        );
    }

    // =========================================================================
    // Discrepancies
    // =========================================================================

    public function discrepancies(): string
    {
        $status = $this->oneOf('status', ['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'DISMISSED']);

        return $this->render('admin/surveys/discrepancies', 'Stock discrepancies', 'admin/discrepancies', [
            'rows'   => SurveyService::instance()->discrepancies(null, $status, 200),
            'status' => $status,
            'counts' => array_column(
                db_connect()->table('inventory_discrepancies')->select('status, COUNT(*) AS n', false)
                    ->groupBy('status')->get()->getResultArray(),
                'n',
                'status',
            ),
        ]);
    }

    /**
     * Settles a discrepancy.
     *
     * Note what this does NOT do: it does not create a sale, void a
     * warranty or move a mattress. The office records the conclusion here
     * and then makes any correction through the screen that owns it — the
     * sale through sales, the warranty through warranties — so every change
     * still goes through the service that validates and audits it.
     */
    public function resolve(string $id): RedirectResponse
    {
        $outcome = (string) $this->request->getPost('outcome');
        $note    = $this->post('note', 1000);

        if (! in_array($outcome, ['UNDER_REVIEW', 'RESOLVED', 'DISMISSED'], true)) {
            return redirect()->back()->with('error', 'Choose what is happening with this discrepancy.');
        }
        if ($outcome !== 'UNDER_REVIEW' && trim($note) === '') {
            return redirect()->back()->with('error', 'Record what was concluded before closing a discrepancy.');
        }

        return $this->act(
            function () use ($id, $outcome, $note) {
                return Tx::run(function (BaseConnection $db) use ($id, $outcome, $note) {
                    $row = $db->query('SELECT * FROM inventory_discrepancies WHERE id = ? FOR UPDATE', [$id])->getRowArray();
                    if ($row === null) {
                        throw AppException::notFound('discrepancy');
                    }
                    if (in_array($row['status'], ['RESOLVED', 'DISMISSED'], true)) {
                        throw AppException::rule('This discrepancy has already been settled.');
                    }

                    $settled = $outcome !== 'UNDER_REVIEW';
                    $db->table('inventory_discrepancies')->where('id', $id)->update([
                        'status'          => $outcome,
                        'resolution_note' => trim($note) !== '' ? $note : null,
                        'resolved_by'     => $settled ? $this->ctx->userId() : null,
                        'resolved_at'     => $settled ? utc_now() : null,
                    ]);

                    Audit::instance($db)->record('DISCREPANCY_' . $outcome, 'inventory_discrepancy', $id,
                        ['status' => $row['status']], ['status' => $outcome], trim($note) !== '' ? $note : null);

                    return ['status' => $outcome];
                });
            },
            static fn ($r) => 'Discrepancy ' . strtolower(humanise($r['status'])) . '.',
            site_url('admin/discrepancies'),
        );
    }

    // =========================================================================
    // Who covers which dealers
    // =========================================================================

    public function assignments(): string
    {
        $db = db_connect();

        return $this->render('admin/surveys/assignments', 'Agent assignments', 'admin/assignments', [
            'rows' => $db->table('agent_dealer_assignments a')
                ->select('a.*, d.business_name AS dealer, d.code AS dealer_code, d.city,'
                    . ' u.full_name AS agent, u.email AS agent_email')
                ->join('dealers d', 'd.id = a.dealer_id')
                ->join('users u', 'u.id = a.agent_user_id')
                ->orderBy('u.full_name')->orderBy('d.business_name')
                ->get()->getResultArray(),
            'agents'  => $this->agents(),
            'dealers' => $db->table('dealers')->select('id, code, business_name, city')
                ->where(['status' => 'ACTIVE', 'deleted_at' => null])
                ->orderBy('business_name')->get()->getResultArray(),
            'defaultInterval' => SurveyService::DEFAULT_INTERVAL_DAYS,
        ]);
    }

    /**
     * Assigns a dealer to an agent, or changes the schedule on an existing
     * assignment.
     *
     * The interval is per assignment rather than global: six months is the
     * default the business asked for, and a dealer who needs watching more
     * closely can be set to less without changing anybody else.
     */
    public function assign(): RedirectResponse
    {
        $in = $this->validated([
            'agent_user_id' => 'required|exact_length[36]',
            'dealer_id'     => 'required|exact_length[36]',
            'interval_days' => 'required|is_natural_no_zero|greater_than_equal_to[7]|less_than_equal_to[1095]',
            'notes'         => 'permit_empty|max_length[500]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(
            function () use ($in) {
                return Tx::run(function (BaseConnection $db) use ($in) {
                    $agentHoldsTheRole = $db->table('user_roles ur')->join('roles r', 'r.id = ur.role_id')
                        ->where(['ur.user_id' => $in['agent_user_id'], 'r.key' => 'FIELD_AGENT'])->countAllResults() > 0;

                    if (! $agentHoldsTheRole) {
                        throw AppException::rule('That user is not a field agent. Give them the Field Agent role first.');
                    }
                    if ($db->table('dealers')->where(['id' => $in['dealer_id'], 'deleted_at' => null])->countAllResults() === 0) {
                        throw AppException::notFound('dealer');
                    }

                    $now      = utc_now();
                    $existing = $db->table('agent_dealer_assignments')
                        ->where(['agent_user_id' => $in['agent_user_id'], 'dealer_id' => $in['dealer_id']])
                        ->get()->getRowArray();

                    if ($existing !== null) {
                        // Re-assigning keeps the history: the same row is
                        // reactivated rather than a second one created, which
                        // is what the unique key on the pair expects.
                        $db->table('agent_dealer_assignments')->where('id', $existing['id'])->update([
                            'survey_interval_days' => (int) $in['interval_days'],
                            'notes'                => ($in['notes'] ?? '') !== '' ? $in['notes'] : null,
                            'active'               => 1,
                            'assigned_by'          => $this->ctx->userId(),
                            'updated_at'           => $now,
                        ]);
                        $id = $existing['id'];
                    } else {
                        $id = uuid4();
                        $db->table('agent_dealer_assignments')->insert([
                            'id'                   => $id,
                            'agent_user_id'        => $in['agent_user_id'],
                            'dealer_id'            => $in['dealer_id'],
                            'survey_interval_days' => (int) $in['interval_days'],
                            'notes'                => ($in['notes'] ?? '') !== '' ? $in['notes'] : null,
                            'active'               => 1,
                            'assigned_by'          => $this->ctx->userId(),
                            'created_at'           => $now,
                        ]);
                    }

                    Audit::instance($db)->record('AGENT_ASSIGNED', 'agent_dealer_assignment', $id, null, [
                        'agentUserId' => $in['agent_user_id'],
                        'dealerId'    => $in['dealer_id'],
                        'intervalDays' => (int) $in['interval_days'],
                    ]);

                    return ['id' => $id];
                });
            },
            'Assignment saved.',
            site_url('admin/assignments'),
        );
    }

    /** Takes a dealer off an agent's round. The surveys they did stay. */
    public function unassign(string $id): RedirectResponse
    {
        return $this->act(
            function () use ($id) {
                return Tx::run(function (BaseConnection $db) use ($id) {
                    $row = $db->table('agent_dealer_assignments')->where('id', $id)->get()->getRowArray();
                    if ($row === null) {
                        throw AppException::notFound('assignment');
                    }

                    $db->table('agent_dealer_assignments')->where('id', $id)
                        ->update(['active' => 0, 'updated_at' => utc_now()]);

                    Audit::instance($db)->record('AGENT_UNASSIGNED', 'agent_dealer_assignment', $id,
                        ['active' => 1], ['active' => 0]);

                    return [];
                });
            },
            'Assignment ended. The surveys already done are kept.',
            site_url('admin/assignments'),
        );
    }

    /** @return list<array<string,mixed>> */
    private function agents(): array
    {
        return db_connect()->table('users u')
            ->select('u.id, u.full_name, u.email')
            ->join('user_roles ur', 'ur.user_id = u.id')
            ->join('roles r', 'r.id = ur.role_id')
            ->where(['r.key' => 'FIELD_AGENT', 'u.status' => 'ACTIVE', 'u.deleted_at' => null])
            ->orderBy('u.full_name')->get()->getResultArray();
    }
}
