<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyModel;
use App\Models\VerificationApplicationModel;
use App\Models\VerificationEventModel;
use App\Models\VerificationStageModel;

/**
 * Multi-stage supplier / buyer verification.
 *
 * Supplier flow: Registration → Documents → KYC → Compliance → Financial →
 * Risk → Quality → Site audit → Sanctions → Management approval →
 * Required commercial activation (membership payment) → Verified status.
 *
 * Compliance approval is deliberately separate from the subscription
 * payment: approval sets the company's verification_status, while the
 * Verified Supplier plan only becomes effective once its subscription is paid.
 */
class VerificationService
{
    public const STAGES = [
        'registration'        => 'Registration & company profile',
        'documents'           => 'Document submission',
        'kyc'                 => 'KYC / KYB',
        'compliance'          => 'Compliance & certifications',
        'financial'           => 'Financial verification',
        'risk'                => 'Risk assessment',
        'quality'             => 'Quality assessment',
        'site_audit'          => 'Site audit',
        'sanctions'           => 'Sanctions / PEP / AML screening',
        'management_approval' => 'Management approval',
    ];

    public function __construct(
        private VerificationApplicationModel $apps = new VerificationApplicationModel(),
        private VerificationStageModel $stages = new VerificationStageModel(),
    ) {
    }

    /**
     * Stages required for a company type (configurable in Platform Settings).
     *
     * @return list<string>
     */
    public function requiredStages(string $type): array
    {
        $default = $type === 'supplier'
            ? array_keys(self::STAGES)
            : ['registration', 'documents', 'kyc', 'sanctions', 'management_approval'];
        $cfg = (array) policy("verification.{$type}_stages", $default);

        $stages = array_values(array_intersect(array_keys(self::STAGES), $cfg));
        if (! in_array('management_approval', $stages, true)) {
            $stages[] = 'management_approval';
        }

        return $stages;
    }

    public function current(int $companyId): ?array
    {
        return $this->apps->where('company_id', $companyId)->orderBy('id', 'DESC')->first();
    }

    public function stagesOf(int $applicationId): array
    {
        return $this->stages->where('application_id', $applicationId)->orderBy('sort_order')->findAll();
    }

    /**
     * Returns the open (draft/changes requested) application, creating one if needed.
     */
    public function openApplication(array $company): array
    {
        $app = $this->current((int) $company['id']);
        if ($app && in_array($app['status'], ['draft', 'changes_requested', 'submitted', 'in_review'], true)) {
            return $app;
        }
        if ($app && $app['status'] === 'approved' && $company['verification_status'] === 'verified') {
            return $app;
        }

        $cycle = $app ? (int) $app['cycle'] + 1 : 1;
        $id    = (int) $this->apps->insert([
            'company_id'       => $company['id'],
            'application_type' => $company['company_type'],
            'cycle'            => $cycle,
            'status'           => 'draft',
            'current_stage'    => 'registration',
        ]);
        $order = 0;
        foreach (array_keys(self::STAGES) as $key) {
            $required = in_array($key, $this->requiredStages($company['company_type']), true);
            $this->stages->insert([
                'application_id' => $id,
                'stage_key'      => $key,
                'sort_order'     => $order++,
                'status'         => $required ? 'pending' : 'not_applicable',
            ]);
        }
        $this->event($id, null, $cycle > 1 ? 'reapplication_started' : 'application_started');

        return $this->apps->find($id);
    }

    /**
     * Checks that the applicant supplied the minimum information.
     *
     * @return list<string> missing items
     */
    public function missingItems(array $company): array
    {
        $db      = db_connect();
        $missing = [];
        foreach (['legal_name', 'address_line1', 'city', 'country_code', 'phone', 'email'] as $f) {
            if (empty($company[$f])) {
                $missing[] = 'Company profile: ' . str_replace('_', ' ', $f);
            }
        }
        $kyc = $db->table('kyc_records')->where('company_id', $company['id'])->get()->getRowArray();
        if (! $kyc || (empty($kyc['tax_id']) && empty($kyc['gst_number']) && empty($kyc['vat_number']))) {
            $missing[] = 'KYC: at least one tax registration (GST, VAT or Tax ID)';
        }
        if ($company['company_type'] === 'supplier') {
            if (! $kyc || empty($kyc['bank_account_number_enc'])) {
                $missing[] = 'KYC: bank account details';
            }
            if ($db->table('company_directors')->where('company_id', $company['id'])->countAllResults() === 0) {
                $missing[] = 'At least one director / owner';
            }
        }
        $docs = $db->table('documents')->where('company_id', $company['id'])->where('entity_type', 'company')
            ->where('is_current', 1)->where('deleted_at', null)->countAllResults();
        if ($docs === 0) {
            $missing[] = 'At least one company registration document';
        }

        return $missing;
    }

    public function submit(array $company, int $userId): array
    {
        $app = $this->openApplication($company);
        if (! in_array($app['status'], ['draft', 'changes_requested'], true)) {
            throw new BusinessRuleException('This application has already been submitted.');
        }
        $missing = $this->missingItems($company);
        if ($missing !== []) {
            throw new BusinessRuleException('Please complete: ' . implode('; ', $missing) . '.');
        }

        $this->apps->update($app['id'], ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'), 'current_stage' => 'documents']);
        $this->stages->where('application_id', $app['id'])->where('stage_key', 'registration')->set(['status' => 'passed', 'reviewed_at' => date('Y-m-d H:i:s'), 'comments' => 'Profile completed by applicant'])->update();
        model(CompanyModel::class)->update($company['id'], ['verification_status' => 'in_review']);
        $this->event((int) $app['id'], null, 'submitted', $userId);

        service('notifications')->notifyStaff(['verification_officer'], 'verification.submitted', 'Verification submitted', $company['legal_name'] . ' submitted a ' . $company['company_type'] . ' verification application.', 'admin/verifications/' . $app['id']);
        service('notifications')->notifyCompany((int) $company['id'], 'verification.submitted', 'Verification application received', 'Our verification team will review your application. You will be notified of each decision.', $company['company_type'] . '/verification');
        service('audit')->log('verification.submitted', ['entity_type' => 'verification_application', 'entity_id' => (int) $app['id'], 'company_id' => (int) $company['id']]);

        return $this->apps->find($app['id']);
    }

    public function assign(array $app, string $stageKey, ?int $assigneeId, int $actorId): void
    {
        $this->stages->where('application_id', $app['id'])->where('stage_key', $stageKey)->set(['assigned_to' => $assigneeId])->update();
        $this->event((int) $app['id'], $stageKey, 'assigned', $actorId, $assigneeId ? "Assigned to user #{$assigneeId}" : 'Unassigned');
        if ($assigneeId) {
            service('notifications')->notifyUser($assigneeId, 'verification.assigned', 'Verification stage assigned to you', self::STAGES[$stageKey] . ' — application #' . $app['id'], 'admin/verifications/' . $app['id'], false);
        }
    }

    public function reviewStage(array $app, string $stageKey, string $status, ?string $comments, int $reviewerId): void
    {
        if (! isset(self::STAGES[$stageKey]) || $stageKey === 'management_approval') {
            throw new BusinessRuleException('Use approve/reject for the management approval stage.');
        }
        if (! in_array($status, ['in_review', 'passed', 'failed', 'waived'], true)) {
            throw new BusinessRuleException('Invalid stage status.');
        }
        if (! in_array($app['status'], ['submitted', 'in_review'], true)) {
            throw new BusinessRuleException('The application is not open for review.');
        }
        if ($status === 'failed' && trim((string) $comments) === '') {
            throw new BusinessRuleException('Please explain why the stage failed.');
        }

        $this->stages->where('application_id', $app['id'])->where('stage_key', $stageKey)->set([
            'status' => $status, 'comments' => $comments, 'reviewed_by' => $reviewerId, 'reviewed_at' => date('Y-m-d H:i:s'),
        ])->update();
        $next = $this->stages->where('application_id', $app['id'])->whereIn('status', ['pending', 'in_review'])->orderBy('sort_order')->first();
        $this->apps->update($app['id'], ['status' => 'in_review', 'current_stage' => $next['stage_key'] ?? 'management_approval']);
        $this->event((int) $app['id'], $stageKey, 'stage_' . $status, $reviewerId, $comments);
        service('audit')->log('verification.stage_reviewed', ['entity_type' => 'verification_application', 'entity_id' => (int) $app['id'], 'company_id' => (int) $app['company_id'], 'description' => "{$stageKey} → {$status}"]);
    }

    /**
     * @return list<string> stage keys still blocking approval
     */
    public function blockingStages(array $app): array
    {
        $rows = $this->stages->where('application_id', $app['id'])->where('stage_key !=', 'management_approval')
            ->whereNotIn('status', ['passed', 'waived', 'not_applicable'])->findAll();

        return array_column($rows, 'stage_key');
    }

    public function approve(array $app, int $userId, ?string $notes = null): void
    {
        if (! in_array($app['status'], ['submitted', 'in_review'], true)) {
            throw new BusinessRuleException('Only submitted applications can be approved.');
        }
        $blocking = $this->blockingStages($app);
        if ($blocking !== []) {
            throw new BusinessRuleException('These stages must be passed or waived first: ' . implode(', ', array_map(static fn ($k) => self::STAGES[$k], $blocking)) . '.');
        }
        $company = model(CompanyModel::class)->find($app['company_id']);
        if ($company['company_type'] === 'supplier') {
            $match = db_connect()->table('sanctions_screenings')->where('company_id', $company['id'])->where('result', 'confirmed_match')->countAllResults();
            if ($match > 0) {
                throw new BusinessRuleException('A confirmed sanctions match is on record. The application cannot be approved.');
            }
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $now = date('Y-m-d H:i:s');
            $this->stages->where('application_id', $app['id'])->where('stage_key', 'management_approval')->set(['status' => 'passed', 'reviewed_by' => $userId, 'reviewed_at' => $now, 'comments' => $notes])->update();
            $this->apps->update($app['id'], ['status' => 'approved', 'decided_at' => $now, 'decided_by' => $userId, 'decision_notes' => $notes, 'current_stage' => null]);
            model(CompanyModel::class)->update($company['id'], ['verification_status' => 'verified', 'verified_at' => $now]);
            $this->event((int) $app['id'], 'management_approval', 'approved', $userId, $notes);

            $activation = null;
            if ($company['company_type'] === 'supplier') {
                $activation = service('membership')->ensureVerifiedActivation(model(CompanyModel::class)->find($company['id']), $userId);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('entitlements')->flush();
        service('companyContext')->flush();

        $msg = $company['company_type'] === 'supplier'
            ? ($activation && $activation['status'] === 'active'
                ? 'Your company is verified and your Verified Supplier membership is active.'
                : 'Your company passed verification. Complete the Verified Supplier membership payment (invoice issued) to activate your badge and premium features.')
            : 'Your buyer account is verified. Enhanced sourcing features are now available.';
        service('notifications')->notifyCompany((int) $company['id'], 'verification.approved', 'Verification approved', $msg, $company['company_type'] . '/verification');
        service('audit')->log('verification.approved', ['entity_type' => 'verification_application', 'entity_id' => (int) $app['id'], 'company_id' => (int) $company['id'], 'description' => (string) $notes]);
    }

    public function reject(array $app, int $userId, string $notes, bool $allowResubmit = true): void
    {
        if (trim($notes) === '') {
            throw new BusinessRuleException('Please give the applicant a reason.');
        }
        if (! in_array($app['status'], ['submitted', 'in_review'], true)) {
            throw new BusinessRuleException('Only submitted applications can be rejected.');
        }
        $status = $allowResubmit ? 'changes_requested' : 'rejected';
        $this->apps->update($app['id'], ['status' => $status, 'decided_at' => date('Y-m-d H:i:s'), 'decided_by' => $userId, 'decision_notes' => $notes]);
        model(CompanyModel::class)->update($app['company_id'], ['verification_status' => $allowResubmit ? 'unverified' : 'rejected']);
        if ($allowResubmit) {
            // Applicant fixes and resubmits within the same cycle: reopen failed stages.
            $this->stages->where('application_id', $app['id'])->where('status', 'failed')->set(['status' => 'pending'])->update();
        }
        $this->event((int) $app['id'], null, $status, $userId, $notes);
        $company = model(CompanyModel::class)->find($app['company_id']);
        service('notifications')->notifyCompany((int) $app['company_id'], 'verification.rejected', $allowResubmit ? 'Verification needs changes' : 'Verification rejected', $notes, $company['company_type'] . '/verification');
        service('audit')->log('verification.' . $status, ['entity_type' => 'verification_application', 'entity_id' => (int) $app['id'], 'company_id' => (int) $app['company_id'], 'description' => $notes]);
    }

    /**
     * Suspends a verified company (e.g. expired documents, compliance breach).
     */
    public function suspend(array $company, int $userId, string $reason): void
    {
        model(CompanyModel::class)->update($company['id'], ['verification_status' => 'suspended', 'status' => 'suspended', 'suspension_reason' => $reason]);
        service('entitlements')->flush();
        service('notifications')->notifyCompany((int) $company['id'], 'account.suspended', 'Account suspended', $reason, $company['company_type'] . '/dashboard');
        service('audit')->log('company.suspended', ['entity_type' => 'company', 'entity_id' => (int) $company['id'], 'company_id' => (int) $company['id'], 'description' => $reason, 'severity' => 'warning']);
    }

    public function reactivate(array $company, int $userId): void
    {
        $app      = $this->current((int) $company['id']);
        $verified = $app && $app['status'] === 'approved';
        model(CompanyModel::class)->update($company['id'], ['status' => 'active', 'verification_status' => $verified ? 'verified' : 'unverified', 'suspension_reason' => null]);
        service('entitlements')->flush();
        service('notifications')->notifyCompany((int) $company['id'], 'account.reactivated', 'Account reactivated', 'Your BearingCave account is active again.', $company['company_type'] . '/dashboard');
        service('audit')->log('company.reactivated', ['entity_type' => 'company', 'entity_id' => (int) $company['id'], 'company_id' => (int) $company['id']]);
    }

    public function event(int $appId, ?string $stage, string $action, ?int $userId = null, ?string $notes = null): void
    {
        model(VerificationEventModel::class)->insert([
            'application_id' => $appId, 'stage_key' => $stage, 'action' => $action,
            'user_id' => $userId ?? (auth()->loggedIn() ? (int) auth()->id() : null), 'notes' => $notes,
        ]);
    }
}
