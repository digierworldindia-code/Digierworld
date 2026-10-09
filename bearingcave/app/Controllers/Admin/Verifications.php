<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CompanyDirectorModel;
use App\Models\CompanyModel;
use App\Models\ComplianceCertificationModel;
use App\Models\DocumentModel;
use App\Models\FinancialAssessmentModel;
use App\Models\KycRecordModel;
use App\Models\QualityAssessmentModel;
use App\Models\SanctionsScreeningModel;
use App\Models\SiteAuditModel;
use App\Models\VerificationApplicationModel;
use App\Services\RiskService;
use App\Services\VerificationService;

/**
 * Verification workbench: each of the 12 verification modules has its own
 * form here, every action is persisted and recorded in the audit trail.
 */
class Verifications extends AdminController
{
    public function index(): string
    {
        $status = $this->request->getGet('status') ?: 'open';
        $b = db_connect()->table('verification_applications a')
            ->select('a.*, c.legal_name, c.company_type, c.country_code, c.is_sample, (SELECT COUNT(*) FROM verification_stages s WHERE s.application_id = a.id AND s.status IN (\'passed\',\'waived\',\'not_applicable\')) AS done_stages, (SELECT COUNT(*) FROM verification_stages s WHERE s.application_id = a.id) AS total_stages', false)
            ->join('companies c', 'c.id = a.company_id');
        if ($status === 'open') {
            $b->whereIn('a.status', ['submitted', 'in_review']);
        } elseif ($status !== 'all') {
            $b->where('a.status', $status);
        }
        if ($t = $this->request->getGet('type')) {
            $b->where('a.application_type', $t);
        }
        if ($this->request->getGet('mine')) {
            $b->where('EXISTS (SELECT 1 FROM verification_stages s WHERE s.application_id = a.id AND s.assigned_to = ' . $this->userId() . ')', null, false);
        }

        return $this->page('verifications', ['title' => 'Verification queue', 'rows' => $b->orderBy('a.submitted_at', 'ASC')->limit(200)->get()->getResultArray(), 'status' => $status]);
    }

    private function app(int $id): array
    {
        return model(VerificationApplicationModel::class)->find($id) ?? $this->notFound();
    }

    public function show(int $id): string
    {
        $app = $this->app($id);
        $cid = (int) $app['company_id'];
        $db  = db_connect();
        $sensitive = $this->can('kyc.sensitive');
        $kyc = model(KycRecordModel::class)->where('company_id', $cid)->first();
        if ($kyc && $sensitive && $kyc['bank_account_number_enc']) {
            try {
                $kyc['bank_account_plain'] = service('encrypter')->decrypt(base64_decode($kyc['bank_account_number_enc'], true) ?: '');
            } catch (\Throwable) {
                $kyc['bank_account_plain'] = '[cannot decrypt — encryption key changed?]';
            }
            service('audit')->log('kyc.sensitive_viewed', ['entity_type' => 'company', 'entity_id' => $cid, 'company_id' => $cid, 'severity' => 'warning']);
        }
        $directors = model(CompanyDirectorModel::class)->where('company_id', $cid)->findAll();
        foreach ($directors as &$d) {
            $d['id_plain'] = null;
            if ($sensitive && $d['id_document_number_enc']) {
                try {
                    $d['id_plain'] = service('encrypter')->decrypt(base64_decode($d['id_document_number_enc'], true) ?: '');
                } catch (\Throwable) {
                    $d['id_plain'] = '[cannot decrypt]';
                }
            }
        }

        return $this->page('verification', [
            'title' => 'Verification #' . $id, 'app' => $app, 'c' => model(CompanyModel::class)->find($cid),
            'stages' => service('verification')->stagesOf($id), 'stageNames' => VerificationService::STAGES, 'blocking' => service('verification')->blockingStages($app),
            'kyc' => $kyc, 'directors' => $directors, 'sensitive' => $sensitive,
            'certs' => model(ComplianceCertificationModel::class)->where('company_id', $cid)->orderBy('id', 'DESC')->findAll(),
            'financial' => model(FinancialAssessmentModel::class)->where('company_id', $cid)->orderBy('id', 'DESC')->first(),
            'quality' => model(QualityAssessmentModel::class)->where('company_id', $cid)->orderBy('id', 'DESC')->first(),
            'audits' => model(SiteAuditModel::class)->where('company_id', $cid)->orderBy('id', 'DESC')->findAll(),
            'risks' => $db->table('risk_assessments')->where('company_id', $cid)->orderBy('id', 'DESC')->get()->getResultArray(),
            'screenings' => model(SanctionsScreeningModel::class)->where('company_id', $cid)->orderBy('id', 'DESC')->findAll(),
            'documents' => model(DocumentModel::class)->where('company_id', $cid)->where('entity_type', 'company')->orderBy('id', 'DESC')->findAll(),
            'events' => $db->table('verification_events e')->select('e.*, ai.secret AS email')->join('auth_identities ai', "ai.user_id = e.user_id AND ai.type = 'email_password'", 'left')->where('e.application_id', $id)->orderBy('e.id', 'DESC')->get()->getResultArray(),
            'officers' => $this->staffOptions(['verification_officer', 'procurement_manager', 'inspection_manager']),
            'riskFactors' => RiskService::FACTORS, 'sanctionsProvider' => policy('sanctions.provider', 'none'),
            'canApprove' => $this->can('verification.approve'),
        ]);
    }

    public function assign(int $id)
    {
        $app = $this->app($id);

        return $this->attempt(fn () => service('verification')->assign($app, (string) $this->request->getPost('stage_key'), (int) $this->request->getPost('user_id') ?: null, $this->userId()), 'Stage assigned.');
    }

    public function stage(int $id)
    {
        $app = $this->app($id);

        return $this->attempt(fn () => service('verification')->reviewStage($app, (string) $this->request->getPost('stage_key'), (string) $this->request->getPost('status'), $this->request->getPost('comments') ?: null, $this->userId()), 'Stage updated.');
    }

    public function kyc(int $id)
    {
        $app = $this->app($id);
        $kyc = model(KycRecordModel::class)->where('company_id', $app['company_id'])->first();
        if (! $kyc) {
            return redirect()->back()->with('error', 'The applicant has not entered KYC details yet.');
        }
        $ok = ['pending', 'verified', 'validated', 'failed'];
        $data = ['reviewer_notes' => $this->request->getPost('reviewer_notes'), 'reviewed_by' => $this->userId(), 'reviewed_at' => date('Y-m-d H:i:s')];
        foreach (['tax_verified', 'address_verified', 'owner_verified', 'ubo_verified', 'bank_validated'] as $f) {
            $v = (string) $this->request->getPost($f);
            if (in_array($v, $ok, true)) {
                $data[$f] = $f === 'bank_validated' ? ($v === 'verified' ? 'validated' : $v) : ($v === 'validated' ? 'verified' : $v);
            }
        }
        model(KycRecordModel::class)->update($kyc['id'], $data);
        foreach ((array) $this->request->getPost('directors') as $did => $st) {
            if (in_array($st, ['pending', 'verified', 'rejected'], true)) {
                model(CompanyDirectorModel::class)->where('id', (int) $did)->where('company_id', $app['company_id'])->set(['verification_status' => $st])->update();
            }
        }
        service('verification')->event($id, 'kyc', 'kyc_reviewed', $this->userId(), (string) $this->request->getPost('reviewer_notes'));

        return redirect()->back()->with('success', 'KYC review saved. Mark the KYC stage passed when all checks are complete.');
    }

    public function financial(int $id)
    {
        $app = $this->app($id);
        $fa  = model(FinancialAssessmentModel::class)->where('company_id', $app['company_id'])->orderBy('id', 'DESC')->first();
        if (! $fa) {
            return redirect()->back()->with('error', 'No financial information submitted.');
        }
        $st = (string) $this->request->getPost('status');
        model(FinancialAssessmentModel::class)->update($fa['id'], ['status' => in_array($st, ['pending', 'verified', 'rejected'], true) ? $st : 'pending', 'credit_rating' => $this->request->getPost('credit_rating') ?: $fa['credit_rating'], 'reviewer_notes' => $this->request->getPost('reviewer_notes'), 'reviewed_by' => $this->userId(), 'reviewed_at' => date('Y-m-d H:i:s')]);
        service('verification')->event($id, 'financial', 'financial_' . $st, $this->userId(), (string) $this->request->getPost('reviewer_notes'));

        return redirect()->back()->with('success', 'Financial review saved.');
    }

    public function risk(int $id)
    {
        $app = $this->app($id);

        return $this->attempt(function () use ($app, $id) {
            $r = service('risk')->record((int) $app['company_id'], $id, $this->request->getPost(), $this->userId());
            service('verification')->event($id, 'risk', 'risk_assessed', $this->userId(), "Risk {$r['risk_level']} ({$r['weighted_score']})");
        }, 'Risk assessment recorded.');
    }

    public function quality(int $id)
    {
        $app = $this->app($id);
        $qa  = model(QualityAssessmentModel::class)->where('company_id', $app['company_id'])->orderBy('id', 'DESC')->first();
        $st  = (string) $this->request->getPost('status');
        $row = ['status' => in_array($st, ['pending', 'passed', 'failed'], true) ? $st : 'pending', 'score' => is_numeric($this->request->getPost('score')) ? $this->request->getPost('score') : null,
            'reviewer_notes' => $this->request->getPost('reviewer_notes'), 'reviewed_by' => $this->userId(), 'reviewed_at' => date('Y-m-d H:i:s')];
        $qa ? model(QualityAssessmentModel::class)->update($qa['id'], $row) : model(QualityAssessmentModel::class)->insert($row + ['company_id' => $app['company_id']]);
        service('verification')->event($id, 'quality', 'quality_' . $row['status'], $this->userId(), (string) $row['reviewer_notes']);

        return redirect()->back()->with('success', 'Quality assessment saved.');
    }

    public function siteAudit(int $id)
    {
        $app = $this->app($id);
        if ($r = $this->invalid(['result' => 'required|in_list[scheduled,pass,conditional,fail,cancelled]', 'scheduled_on' => 'permit_empty|valid_date[Y-m-d]', 'conducted_on' => 'permit_empty|valid_date[Y-m-d]'])) {
            return $r;
        }

        return $this->attempt(function () use ($app, $id) {
            $row = ['company_id' => $app['company_id'], 'scheduled_on' => $this->request->getPost('scheduled_on') ?: null, 'conducted_on' => $this->request->getPost('conducted_on') ?: null,
                'auditor_user_id' => (int) $this->request->getPost('auditor_user_id') ?: null, 'external_auditor' => $this->request->getPost('external_auditor') ?: null,
                'site_address' => $this->request->getPost('site_address') ?: null, 'findings' => $this->request->getPost('findings') ?: null, 'result' => $this->request->getPost('result')];
            foreach (['manufacturing_capability', 'production_capacity', 'machinery_condition', 'warehouse_management', 'calibration', 'workforce_capability', 'quality_processes'] as $f) {
                $v = $this->request->getPost($f);
                $row[$f] = $v === '' || $v === null ? null : max(1, min(5, (int) $v));
            }
            if (in_array($row['result'], ['pass', 'conditional', 'fail'], true) && ! $row['conducted_on']) {
                throw new \App\Exceptions\BusinessRuleException('Enter the date the audit was conducted.');
            }
            $file = $this->request->getFile('report');
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $doc = service('documents')->store($file, ['company_id' => (int) $app['company_id'], 'entity_type' => 'company', 'entity_id' => (int) $app['company_id'], 'doc_type' => 'site_audit_report', 'title' => 'Site audit report', 'review_status' => 'approved'], ['pdf', 'jpg', 'jpeg', 'png']);
                $row['report_document_id'] = $doc['id'];
            }
            model(SiteAuditModel::class)->insert($row);
            service('verification')->event($id, 'site_audit', 'site_audit_' . $row['result'], $this->userId(), $row['findings']);
        }, 'Site audit recorded.');
    }

    public function sanctions(int $id)
    {
        $app = $this->app($id);
        if ($r = $this->invalid(['subject_type' => 'required|in_list[company,director]', 'subject_name' => 'required|max_length[191]', 'lists_checked' => 'required|max_length[191]', 'result' => 'required|in_list[clear,potential_match,confirmed_match,error]', 'notes' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        model(SanctionsScreeningModel::class)->insert([
            'company_id' => $app['company_id'], 'subject_type' => $this->request->getPost('subject_type'), 'subject_name' => $this->request->getPost('subject_name'),
            'provider' => policy('sanctions.provider', 'none') === 'none' ? 'manual' : policy('sanctions.provider'), 'lists_checked' => $this->request->getPost('lists_checked'),
            'result' => $this->request->getPost('result'), 'provider_reference' => $this->request->getPost('provider_reference') ?: null, 'notes' => $this->request->getPost('notes') ?: null,
            'screened_by' => $this->userId(), 'screened_at' => date('Y-m-d H:i:s'),
        ]);
        service('verification')->event($id, 'sanctions', 'screening_' . $this->request->getPost('result'), $this->userId(), (string) $this->request->getPost('subject_name'));
        if ($this->request->getPost('result') === 'confirmed_match') {
            service('audit')->log('sanctions.confirmed_match', ['severity' => 'security', 'entity_type' => 'company', 'entity_id' => (int) $app['company_id'], 'company_id' => (int) $app['company_id'], 'description' => (string) $this->request->getPost('subject_name')]);
        }

        return redirect()->back()->with('success', 'Screening result recorded.');
    }

    public function reviewDocument(int $id, int $docId)
    {
        $app = $this->app($id);
        $doc = model(DocumentModel::class)->where('company_id', $app['company_id'])->find($docId) ?? $this->notFound();
        $st  = (string) $this->request->getPost('review_status');
        if (! in_array($st, ['approved', 'rejected', 'pending'], true) || ($st === 'rejected' && trim((string) $this->request->getPost('review_comment')) === '')) {
            return redirect()->back()->with('error', 'Choose a decision and add a comment when rejecting.');
        }
        model(DocumentModel::class)->update($docId, ['review_status' => $st, 'review_comment' => $this->request->getPost('review_comment') ?: null, 'reviewed_by' => $this->userId(), 'reviewed_at' => date('Y-m-d H:i:s')]);
        service('verification')->event($id, 'documents', 'document_' . $st, $this->userId(), $doc['title']);
        if ($st === 'rejected') {
            $c = model(CompanyModel::class)->find($app['company_id']);
            service('notifications')->notifyCompany((int) $app['company_id'], 'document.rejected', 'Document needs replacing: ' . $doc['title'], (string) $this->request->getPost('review_comment'), $c['company_type'] . '/verification#documents');
        }

        return redirect()->back()->with('success', 'Document review saved.');
    }

    public function reviewCertification(int $id, int $certId)
    {
        $app  = $this->app($id);
        $cert = model(ComplianceCertificationModel::class)->where('company_id', $app['company_id'])->find($certId) ?? $this->notFound();
        $st   = (string) $this->request->getPost('status');
        if (! in_array($st, ['verified', 'rejected', 'pending', 'expired'], true)) {
            return redirect()->back()->with('error', 'Invalid status.');
        }
        model(ComplianceCertificationModel::class)->update($certId, ['status' => $st, 'reviewer_notes' => $this->request->getPost('reviewer_notes') ?: null, 'reviewed_by' => $this->userId(), 'reviewed_at' => date('Y-m-d H:i:s')]);
        service('verification')->event($id, 'compliance', 'certification_' . $st, $this->userId(), $cert['cert_type']);

        return redirect()->back()->with('success', 'Certification updated.');
    }

    public function approve(int $id)
    {
        $app = $this->app($id);

        return $this->attempt(fn () => service('verification')->approve($app, $this->userId(), $this->request->getPost('notes') ?: null), 'Application approved.');
    }

    public function reject(int $id)
    {
        $app = $this->app($id);

        return $this->attempt(fn () => service('verification')->reject($app, $this->userId(), (string) $this->request->getPost('notes'), $this->request->getPost('final') !== '1'), 'Decision recorded and the applicant notified.');
    }
}
