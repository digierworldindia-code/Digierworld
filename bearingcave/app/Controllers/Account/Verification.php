<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyDirectorModel;
use App\Models\ComplianceCertificationModel;
use App\Models\FinancialAssessmentModel;
use App\Models\KycRecordModel;
use App\Models\QualityAssessmentModel;
use App\Services\VerificationService;

/**
 * Applicant side of verification: KYC, directors/UBO, certifications,
 * financials, quality information, documents and submission.
 */
class Verification extends AreaController
{
    public function index(): string
    {
        $c    = $this->company();
        $vs   = service('verification');
        $app  = $vs->current((int) $c['id']);
        $db   = db_connect();
        $kyc  = model(KycRecordModel::class)->where('company_id', $c['id'])->first();

        return $this->page('account/verification', [
            'title'      => 'Company verification',
            'app'        => $app,
            'stages'     => $app ? $vs->stagesOf((int) $app['id']) : [],
            'stageNames' => VerificationService::STAGES,
            'required'   => $vs->requiredStages($c['company_type']),
            'missing'    => $vs->missingItems($c),
            'kyc'        => $kyc,
            'directors'  => model(CompanyDirectorModel::class)->where('company_id', $c['id'])->findAll(),
            'certs'      => model(ComplianceCertificationModel::class)->where('company_id', $c['id'])->orderBy('id', 'DESC')->findAll(),
            'financial'  => model(FinancialAssessmentModel::class)->where('company_id', $c['id'])->orderBy('id', 'DESC')->first(),
            'quality'    => model(QualityAssessmentModel::class)->where('company_id', $c['id'])->orderBy('id', 'DESC')->first(),
            'documents'  => $db->table('documents')->where('company_id', $c['id'])->where('entity_type', 'company')->where('deleted_at', null)->orderBy('id', 'DESC')->get()->getResultArray(),
            'events'     => $app ? $db->table('verification_events')->where('application_id', $app['id'])->orderBy('id', 'DESC')->limit(20)->get()->getResultArray() : [],
            'editable'   => ! $app || in_array($app['status'], ['draft', 'changes_requested', 'rejected', 'withdrawn'], true) || ($app['status'] === 'approved'),
            'restrictedPolicy' => policy('buyer.restricted_access_policy'),
        ]);
    }

    public function saveKyc()
    {
        if ($r = $this->invalid([
            'gst_number' => 'permit_empty|max_length[30]', 'pan_number' => 'permit_empty|max_length[20]', 'cin_number' => 'permit_empty|max_length[30]',
            'vat_number' => 'permit_empty|max_length[40]', 'tax_id' => 'permit_empty|max_length[60]', 'bank_account_name' => 'permit_empty|max_length[191]',
            'bank_account_number' => 'permit_empty|regex_match[/^[A-Za-z0-9 \-]{4,40}$/]', 'bank_name' => 'permit_empty|max_length[191]',
            'bank_ifsc_swift' => 'permit_empty|max_length[30]', 'bank_country' => 'permit_empty|exact_length[2]',
        ])) {
            return $r;
        }
        $m    = model(KycRecordModel::class);
        $row  = $m->where('company_id', $this->cid())->first();
        $data = $this->request->getPost(['gst_number', 'pan_number', 'cin_number', 'vat_number', 'tax_id', 'bank_account_name', 'bank_name', 'bank_ifsc_swift', 'bank_country']);
        $data = array_map(static fn ($v) => ($v === '' ? null : strtoupper(trim((string) $v))), $data);
        $data['bank_account_name'] = $this->request->getPost('bank_account_name') ?: null;
        $data['bank_name']         = $this->request->getPost('bank_name') ?: null;
        $acct = preg_replace('/\s+/', '', (string) $this->request->getPost('bank_account_number'));
        if ($acct !== '') {
            $data['bank_account_number_enc'] = base64_encode(service('encrypter')->encrypt($acct));
            $data['bank_account_last4']      = substr($acct, -4);
            $data['bank_validated']          = 'pending';
        }
        // Any change to KYC data re-opens its review.
        $data += ['tax_verified' => 'pending', 'address_verified' => $row['address_verified'] ?? 'pending'];
        if ($row) {
            $m->update($row['id'], $data);
        } else {
            $m->insert($data + ['company_id' => $this->cid()]);
        }
        service('audit')->log('kyc.updated', ['entity_type' => 'company', 'entity_id' => $this->cid(), 'company_id' => $this->cid()]);

        return redirect()->to(site_url($this->area() . '/verification#kyc'))->with('success', 'KYC details saved. Bank details are stored encrypted.');
    }

    public function addDirector()
    {
        if ($r = $this->invalid(['full_name' => 'required|max_length[191]', 'designation' => 'permit_empty|max_length[100]', 'nationality' => 'permit_empty|exact_length[2]',
            'id_document_type' => 'permit_empty|max_length[60]', 'id_document_number' => 'permit_empty|max_length[60]', 'ownership_percent' => 'permit_empty|decimal|less_than_equal_to[100]'])) {
            return $r;
        }
        $num = trim((string) $this->request->getPost('id_document_number'));
        model(CompanyDirectorModel::class)->insert([
            'company_id' => $this->cid(), 'full_name' => $this->request->getPost('full_name'), 'designation' => $this->request->getPost('designation') ?: null,
            'nationality' => $this->request->getPost('nationality') ?: null, 'id_document_type' => $this->request->getPost('id_document_type') ?: null,
            'id_document_number_enc' => $num !== '' ? base64_encode(service('encrypter')->encrypt($num)) : null,
            'ownership_percent' => $this->request->getPost('ownership_percent') ?: null, 'is_ubo' => $this->request->getPost('is_ubo') === '1' ? 1 : 0,
            'is_pep_declared' => $this->request->getPost('is_pep_declared') === '1' ? 1 : 0,
        ]);

        return redirect()->to(site_url($this->area() . '/verification#directors'))->with('success', 'Director / owner added.');
    }

    public function deleteDirector(int $id)
    {
        $this->owned(model(CompanyDirectorModel::class), $id);
        model(CompanyDirectorModel::class)->delete($id);

        return redirect()->to(site_url($this->area() . '/verification#directors'))->with('success', 'Removed.');
    }

    public function addCertification()
    {
        if ($r = $this->invalid(['cert_type' => 'required|in_list[ISO9001,IATF16949,AS9100,ISO14001,ISO45001,RoHS,REACH,ESG,INSURANCE,LABOR,OTHER]', 'cert_number' => 'permit_empty|max_length[100]',
            'issuer' => 'permit_empty|max_length[191]', 'issued_on' => 'permit_empty|valid_date[Y-m-d]', 'expires_on' => 'permit_empty|valid_date[Y-m-d]', 'cert_name' => 'permit_empty|max_length[191]'])) {
            return $r;
        }

        return $this->attempt(function () {
            $docId = null;
            $file  = $this->request->getFile('certificate');
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $doc   = service('documents')->store($file, ['company_id' => $this->cid(), 'entity_type' => 'company', 'entity_id' => $this->cid(), 'doc_type' => 'certificate_' . strtolower((string) $this->request->getPost('cert_type')), 'title' => (string) $this->request->getPost('cert_type') . ' certificate', 'expires_on' => $this->request->getPost('expires_on') ?: null, 'issued_on' => $this->request->getPost('issued_on') ?: null], ['pdf', 'jpg', 'jpeg', 'png']);
                $docId = $doc['id'];
            }
            model(ComplianceCertificationModel::class)->insert([
                'company_id' => $this->cid(), 'cert_type' => $this->request->getPost('cert_type'), 'cert_name' => $this->request->getPost('cert_name') ?: null,
                'cert_number' => $this->request->getPost('cert_number') ?: null, 'issuer' => $this->request->getPost('issuer') ?: null,
                'issued_on' => $this->request->getPost('issued_on') ?: null, 'expires_on' => $this->request->getPost('expires_on') ?: null, 'document_id' => $docId, 'status' => 'pending',
            ]);
        }, 'Certification added for review.', $this->area() . '/verification#compliance');
    }

    public function saveFinancial()
    {
        if ($r = $this->invalid(['fiscal_year' => 'permit_empty|max_length[9]', 'currency' => 'required|exact_length[3]', 'annual_turnover' => 'permit_empty|decimal', 'net_profit' => 'permit_empty|decimal',
            'total_assets' => 'permit_empty|decimal', 'total_liabilities' => 'permit_empty|decimal', 'credit_rating' => 'permit_empty|max_length[20]', 'credit_agency' => 'permit_empty|max_length[100]',
            'banking_reference' => 'permit_empty|max_length[2000]', 'legal_cases_count' => 'permit_empty|is_natural', 'legal_case_notes' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        $data = $this->request->getPost(['fiscal_year', 'currency', 'annual_turnover', 'net_profit', 'total_assets', 'total_liabilities', 'credit_rating', 'credit_agency', 'banking_reference', 'legal_cases_count', 'legal_case_notes']);
        $data = array_map(static fn ($v) => $v === '' ? null : $v, $data);
        $data['bankruptcy_history'] = $this->request->getPost('bankruptcy_history') === '1' ? 1 : 0;
        $m = model(FinancialAssessmentModel::class);
        $existing = $m->where('company_id', $this->cid())->where('status', 'pending')->orderBy('id', 'DESC')->first();
        if ($existing) {
            $m->update($existing['id'], $data);
        } else {
            $m->insert($data + ['company_id' => $this->cid(), 'status' => 'pending']);
        }

        return redirect()->to(site_url($this->area() . '/verification#financial'))->with('success', 'Financial information saved for review.');
    }

    public function saveQuality()
    {
        $yn = 'in_list[yes,no,partial,na]';
        if ($r = $this->invalid(['ppap' => $yn, 'apqp' => $yn, 'fmea' => $yn, 'capa' => $yn, 'qc_process_notes' => 'permit_empty|max_length[3000]', 'rejection_rate_pct' => 'permit_empty|decimal|less_than_equal_to[100]', 'quality_certificates' => 'permit_empty|max_length[2000]'])) {
            return $r;
        }
        $data = $this->request->getPost(['ppap', 'apqp', 'fmea', 'capa', 'qc_process_notes', 'rejection_rate_pct', 'quality_certificates']);
        $data['rejection_rate_pct']    = $data['rejection_rate_pct'] === '' ? null : $data['rejection_rate_pct'];
        $data['has_inspection_system'] = $this->request->getPost('has_inspection_system') === '1' ? 1 : 0;
        $m = model(QualityAssessmentModel::class);
        $existing = $m->where('company_id', $this->cid())->where('status', 'pending')->orderBy('id', 'DESC')->first();
        if ($existing) {
            $m->update($existing['id'], $data);
        } else {
            $m->insert($data + ['company_id' => $this->cid(), 'status' => 'pending']);
        }

        return redirect()->to(site_url($this->area() . '/verification#quality'))->with('success', 'Quality information saved for review.');
    }

    public function uploadDocument()
    {
        if ($r = $this->invalid(['doc_type' => 'required|in_list[registration_certificate,gst_certificate,pan_card,tax_registration,bank_letter,address_proof,director_id,financial_statement,quality_manual,insurance,other]',
            'title' => 'required|max_length[191]', 'expires_on' => 'permit_empty|valid_date[Y-m-d]', 'replace_id' => 'permit_empty|is_natural_no_zero'])) {
            return $r;
        }

        return $this->attempt(function () {
            $prev = null;
            if ($rid = (int) $this->request->getPost('replace_id')) {
                $prev = db_connect()->table('documents')->where('id', $rid)->where('company_id', $this->cid())->get()->getRowArray();
                if (! $prev) {
                    throw new BusinessRuleException('Document to replace not found.');
                }
            }
            service('documents')->store($this->request->getFile('file'), [
                'company_id' => $this->cid(), 'entity_type' => 'company', 'entity_id' => $this->cid(), 'doc_type' => (string) $this->request->getPost('doc_type'),
                'title' => (string) $this->request->getPost('title'), 'expires_on' => $this->request->getPost('expires_on') ?: null, 'previous_version_id' => $prev['id'] ?? null,
            ], ['pdf', 'jpg', 'jpeg', 'png']);
        }, 'Document uploaded securely. It is visible only to your company and BearingCave reviewers.', $this->area() . '/verification#documents');
    }

    public function submit()
    {
        return $this->attempt(fn () => service('verification')->submit($this->company(), $this->userId()), 'Verification submitted. We will notify you of each review decision.', $this->area() . '/verification');
    }
}
