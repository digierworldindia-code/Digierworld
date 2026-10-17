<?php

namespace App\Commands;

use App\Libraries\Gstin;
use App\Services\Hr\HrCalendar;
use App\Services\Settings\SettingsStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark dgr:demo            — load clearly-marked DEMO data into a fresh install
 * php spark dgr:demo --purge    — remove it again before go-live
 *
 * Every demo name starts with "DEMO · ", demo logins use @demo.dgr.local,
 * the company identity is "DEMO COMPANY (NOT REAL)" with a placeholder
 * GSTIN, and the demo bank account is labelled not real. The command
 * refuses to run in production, when a real legal identity is configured,
 * or when non-demo business records exist.
 */
class DemoCommand extends BaseCommand
{
    protected $group       = 'DGR Office';
    protected $name        = 'dgr:demo';
    protected $description = 'Load (or --purge) clearly-marked demo data for training and evaluation on a fresh install.';
    protected $usage       = 'dgr:demo [--purge] [--password "Demo-Pass-123"]';

    private const P = 'DEMO · ';

    /** Business/transaction tables wiped by --purge (configuration, users and audit trail are kept). */
    private const WIPE = [
        'ai_action_logs', 'ai_cv_evaluations', 'ai_access_grants', 'ai_service_tokens', 'recruitment_events', 'interview_scorecards', 'interviews', 'candidate_documents',
        'candidates', 'job_description_versions', 'job_openings', 'payslips', 'payroll_adjustments', 'payroll_payments', 'incentive_entries', 'payroll_item_components',
        'payroll_items', 'payroll_runs', 'incentive_policies', 'employee_advances', 'salary_structure_components', 'salary_structures', 'attendance', 'leave_requests',
        'leave_balances', 'employee_documents', 'employee_bank_details', 'employees', 'task_reminders', 'tasks', 'meetings', 'visits', 'followups', 'call_logs',
        'lead_activities', 'lead_assignments', 'lead_conversion_links', 'leads', 'crm_daily_targets', 'google_sync_jobs', 'google_sync_row_map', 'google_sync_runs',
        'reconciliation_matches', 'bank_import_lines', 'bank_imports', 'expense_files', 'expense_payments', 'expenses', 'recurring_expenses', 'payment_allocations',
        'payment_receipts', 'money_transfers', 'credit_debit_note_links', 'billing_share_links', 'billing_taxes', 'billing_line_items', 'billing_document_snapshots',
        'billing_documents', 'recurring_billing_lines', 'recurring_billing_profiles', 'journal_lines', 'journal_entries', 'contact_files', 'contact_merges',
        'contact_people', 'contact_addresses', 'contacts', 'report_exports', 'draft_autosaves', 'notifications', 'signature_usage_log',
    ];

    private array $users = [];

    private int $owner = 0;

    public function run(array $params)
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Refusing to touch demo data in production (CI_ENVIRONMENT = production).');

            return EXIT_ERROR;
        }
        $owner = (int) (db_connect()->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'super_admin' ORDER BY ur.user_id LIMIT 1")->getRow()->user_id ?? 0);
        if ($owner === 0) {
            CLI::error('Create the owner first: php spark dgr:create-owner');

            return EXIT_ERROR;
        }
        $this->owner = $owner;
        service('authz')->actAs($owner);
        service('audit')->asActor('system', $owner);
        try {
            return CLI::getOption('purge') !== null ? $this->purge() : $this->load($owner, (string) (CLI::getOption('password') ?: 'Train-' . bin2hex(random_bytes(4)) . '-Ledger'));
        } catch (Throwable $e) {
            CLI::error(get_class($e) . ': ' . $e->getMessage());
            CLI::write($e->getFile() . ':' . $e->getLine());

            return EXIT_ERROR;
        }
    }

    // ---------------------------------------------------------------- load

    private function load(int $owner, string $password): int
    {
        $db = db_connect();
        if (service('settingsStore')->get('demo.loaded', false)) {
            CLI::error('Demo data is already loaded. Run php spark dgr:demo --purge first.');

            return EXIT_ERROR;
        }
        $company = $db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
        if (! empty($company['gstin']) && ! str_contains((string) $company['legal_name'], 'DEMO')) {
            CLI::error('A real legal identity/GSTIN is configured. Demo invoices would be issued under it — refusing.');

            return EXIT_ERROR;
        }
        $real = $db->table('contacts')->notLike('name', self::P, 'after')->countAllResults() + $db->table('employees')->notLike('full_name', self::P, 'after')->countAllResults()
            + $db->table('leads')->notLike('company_name', self::P, 'after')->countAllResults();
        if ($real > 0) {
            CLI::error("{$real} non-demo business record(s) exist. Demo data is only for a fresh install.");

            return EXIT_ERROR;
        }

        // all-or-nothing: a failure part-way leaves no demo rows behind
        $db->transBegin();
        try {
            $this->company($company);
            $bank = $this->bank();
            $this->users($password);
            $emps = $this->employees();
            $contacts = $this->contacts();
            $this->billing($contacts, $bank);
            $this->expenses($contacts, $bank, $emps);
            $this->crm($contacts);
            $this->recruitment();
            $this->payroll($emps, $bank);
            service('settingsStore')->set('demo.loaded', '1', 'bool');
            service('audit')->log('demo.loaded', 'system', null, null, null, 'Demo data loaded');
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        CLI::newLine();
        CLI::write('Demo data loaded. Every record is prefixed "' . self::P . '".', 'green');
        CLI::write('Demo logins (password: ' . $password . '):', 'yellow');
        foreach ($this->users as $role => [$id, $email]) {
            CLI::write('  ' . str_pad($role, 18) . $email);
        }
        CLI::write('Remove everything before go-live: php spark dgr:demo --purge', 'yellow');

        return EXIT_SUCCESS;
    }

    private function company(array $c): void
    {
        if (! empty($c['gstin'])) {
            return;
        }
        $base = '08AAAAA0000A1Z';
        db_connect()->table('company_settings')->where('id', 1)->update([
            'legal_name' => 'DEMO COMPANY (NOT REAL)', 'trade_name' => $c['trade_name'] ?: 'DIGIE R WORLD', 'gstin' => $base . Gstin::checkChar($base), 'pan' => 'AAAAA0000A',
            'gst_registration_type' => 'regular', 'state_code' => '08', 'reg_address_line1' => 'Demo address — replace in the Billing Setup Wizard', 'reg_city' => 'Bhiwadi',
            'reg_pincode' => '301019', 'rounding_policy' => $c['rounding_policy'] ?? 'nearest_rupee', 'default_price_mode' => $c['default_price_mode'] ?? 'exclusive',
        ]);
        CLI::write('Company identity set to DEMO COMPANY (NOT REAL) with a placeholder GSTIN.');
    }

    private function bank(): int
    {
        $db = db_connect();
        $db->table('ledger_accounts')->insert(['code' => 'DEMO1', 'name' => self::P . 'Business Current A/c', 'account_group' => 'asset', 'sub_type' => 'bank', 'is_active' => 1]);
        $lid = (int) $db->insertID();
        $db->table('bank_cash_accounts')->insert([
            'code' => 'DEMOBANK', 'account_kind' => 'bank', 'ownership' => 'company', 'display_name' => self::P . 'Current A/c (not a real account)', 'bank_name' => 'DEMO BANK',
            'beneficiary_name' => 'DEMO COMPANY (NOT REAL)', 'account_number_enc' => SettingsStore::encrypt('000000000000'), 'account_last4' => '0000', 'ifsc' => 'DEMO0000000',
            'show_on_invoice' => 1, 'is_default_on_invoice' => 1, 'ledger_account_id' => $lid, 'requires_review' => 0, 'is_active' => 1,
            'opening_balance' => '300000.00', 'opening_date' => date('Y-m-01', strtotime('-4 months')),
        ]);
        $id = (int) $db->insertID();
        service('ledger')->syncOpeningBalance($id);

        return $id;
    }

    private function users(string $password): void
    {
        $defs = [
            'accountant' => ['Anjali Accounts', 'accountant'], 'billing_operator' => ['Bharat Billing', 'billing'], 'crm_manager' => ['Chitra CRM Manager', 'crm.manager'],
            'telecaller' => ['Deepak Telecaller', 'telecaller1'], 'telecaller2' => ['Esha Telecaller', 'telecaller2'], 'hr_manager' => ['Farah HR', 'hr'],
            'hr_recruiter' => ['Gaurav Recruiter', 'recruiter'], 'payroll_manager' => ['Heena Payroll', 'payroll'], 'employee' => ['Imran Designer', 'employee'],
        ];
        foreach ($defs as $key => [$name, $local]) {
            $role  = $key === 'telecaller2' ? 'telecaller' : $key;
            $email = $local . '@demo.dgr.local';
            $id    = service('users')->create($email, self::P . $name, $password, [$role]);
            $this->users[$key] = [$id, $email];
            db_connect()->table('user_profiles')->where('user_id', $id)->update(['must_change_password' => 0]);
        }
        db_connect()->table('user_profiles')->whereIn('user_id', [$this->users['telecaller'][0], $this->users['telecaller2'][0]])->update(['manager_user_id' => $this->users['crm_manager'][0]]);
    }

    /** @return array<string, int> key => employee id */
    private function employees(): array
    {
        $db    = db_connect();
        $dept  = array_column($db->table('departments')->get()->getResultArray(), 'id', 'name');
        $doj   = date('Y-m-d', strtotime('first day of -4 months'));
        $defs  = [
            'accountant' => ['Accounts', 32000], 'billing_operator' => ['Accounts', 22000], 'crm_manager' => ['Sales & Telecalling', 35000], 'telecaller' => ['Sales & Telecalling', 16000],
            'telecaller2' => ['Sales & Telecalling', 15000], 'hr_manager' => ['HR & Admin', 34000], 'hr_recruiter' => ['HR & Admin', 24000], 'payroll_manager' => ['HR & Admin', 30000], 'employee' => ['Design', 26000],
        ];
        $out = [];
        foreach ($defs as $key => [$d, $gross]) {
            $uid = $this->users[$key][0];
            $name = $db->table('user_profiles')->select('full_name')->where('user_id', $uid)->get()->getRow()->full_name;
            $out[$key] = service('employees')->create(['full_name' => $name, 'date_of_joining' => $doj, 'department_id' => (string) ($dept[$d] ?? ''), 'user_id' => (string) $uid,
                'employment_status' => 'active', 'work_email' => $this->users[$key][1], 'incentive_eligible' => str_starts_with($key, 'telecaller') ? '1' : '0']);
            $half = (string) intdiv($gross, 2);
            service('salaries')->save($out[$key], ['effective_from' => $doj, 'monthly_gross' => (string) $gross, 'proration_basis' => 'calendar_days', 'components' => [
                ['code' => 'BASIC', 'label' => 'Basic', 'component_type' => 'earning', 'amount' => $half, 'is_prorated' => '1'],
                ['code' => 'HRA', 'label' => 'House rent allowance', 'component_type' => 'earning', 'amount' => (string) ($gross - (int) $half), 'is_prorated' => '1'],
            ]]);
            service('employees')->saveBank($out[$key], ['bank_name' => 'DEMO BANK', 'account_holder' => $name, 'account_number' => '0000000' . str_pad((string) $uid, 5, '0', STR_PAD_LEFT), 'ifsc' => 'DEMO0000000']);
        }
        foreach (['telecaller', 'telecaller2'] as $k) {
            $db->table('employees')->where('id', $out[$k])->update(['reporting_manager_id' => $out['crm_manager']]);
        }
        CLI::write('Employees, salary structures and (fake) bank details created.');

        return $out;
    }

    /** @return array<string, int> */
    private function contacts(): array
    {
        $svc  = new \App\Services\Contacts\ContactService(db_connect());
        $g    = static fn (string $state, string $pan) => $state . $pan . '1Z' . Gstin::checkChar($state . $pan . '1Z');
        $defs = [
            'clinic' => ['name' => self::P . 'Smile Dental Clinic', 'state_code' => '08', 'gstin' => $g('08', 'AAACD1234D'), 'city' => 'Jaipur', 'industry' => 'Healthcare'],
            'tech' => ['name' => self::P . 'Bengaluru Tech Labs Pvt Ltd', 'state_code' => '29', 'gstin' => $g('29', 'AAACB5678B'), 'city' => 'Bengaluru', 'industry' => 'IT services'],
            'cafe' => ['name' => self::P . 'Corner Cafe', 'state_code' => '08', 'city' => 'Bhiwadi', 'industry' => 'Restaurant / food'],
            'realty' => ['name' => self::P . 'Green Acres Realty', 'state_code' => '08', 'city' => 'Alwar', 'industry' => 'Real estate'],
            'walkin' => ['name' => self::P . 'Walk-in Customer', 'state_code' => '08', 'city' => 'Bhiwadi'],
            'landlord' => ['name' => self::P . 'Office Landlord', 'state_code' => '08', 'is_customer' => 0, 'is_vendor' => 1, 'city' => 'Bhiwadi'],
            'saas' => ['name' => self::P . 'CloudTools SaaS', 'state_code' => '27', 'gstin' => $g('27', 'AAACC9012C'), 'is_customer' => 0, 'is_vendor' => 1, 'city' => 'Pune'],
        ];
        $out = [];
        $n   = 0;
        foreach ($defs as $k => $d) {
            $n++;
            $out[$k] = $svc->create($d + ['is_customer' => 1, 'phone' => '90000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'billing_line1' => 'Demo street ' . $n, 'source' => 'Demo data'], true)['id'];
        }
        CLI::write('Clients and vendors created.');

        return $out;
    }

    private function billing(array $c, int $bank): void
    {
        $line = static fn (string $name, string $amt, string $sac = '998361', string $rate = '18') => ['item_name' => $name, 'qty' => '1', 'unit_rate' => $amt, 'tax_treatment' => 'regular', 'gst_rate' => $rate, 'sac_code' => $sac];
        $d1   = date('Y-m-d', strtotime('-20 days'));
        $d2   = date('Y-m-d', strtotime('-12 days'));
        $d3   = date('Y-m-d', strtotime('-5 days'));
        $issue = function (string $type, int $contact, array $lines, string $gst, string $pos, string $date) {
            $id = service('billing')->saveDraft(['doc_type' => $type, 'contact_id' => $contact, 'issue_date' => $date, 'place_of_supply_state_code' => $pos, 'gst_type' => $gst, 'lines' => $lines, 'notes' => 'DEMO document — not a real supply']);
            service('billing')->finalize($id, []);

            return $id;
        };
        $inv1 = $issue('tax_invoice', $c['clinic'], [$line('SEO monthly retainer', '25000')], 'cgst_sgst', '08', $d1);
        $inv2 = $issue('tax_invoice', $c['tech'], [$line('Website redesign — milestone 1', '60000', '998314')], 'igst', '29', $d2);
        $inv3 = $issue('tax_invoice', $c['cafe'], [$line('Social media management', '12000'), $line('Google Business Profile setup', '3000')], 'cgst_sgst', '08', $d3);
        $cm1  = $issue('cash_memo', $c['walkin'], [$line('Logo design', '5000', '998391')], 'cgst_sgst', '08', $d2);
        $est  = service('billing')->saveDraft(['doc_type' => 'estimate', 'contact_id' => $c['realty'], 'issue_date' => $d3, 'place_of_supply_state_code' => '08', 'gst_type' => 'cgst_sgst', 'lines' => [$line('Lead generation campaign (3 months)', '45000')]]);
        $cash = (int) db_connect()->table('bank_cash_accounts')->where('code', 'CASH')->get()->getRow()->id;
        service('receipts')->create(['contact_id' => $c['clinic'], 'receipt_date' => $d2, 'amount' => '29500', 'mode' => 'upi', 'reference_no' => 'DEMO-UPI-001', 'bank_cash_account_id' => $bank, 'allocations' => [['document_id' => $inv1, 'amount' => '29500']]]);
        service('receipts')->create(['contact_id' => $c['walkin'], 'receipt_date' => $d2, 'amount' => '5900', 'mode' => 'cash', 'bank_cash_account_id' => $cash, 'allocations' => [['document_id' => $cm1, 'amount' => '5900']]]);
        service('receipts')->create(['contact_id' => $c['tech'], 'receipt_date' => $d3, 'amount' => '35400', 'mode' => 'neft', 'reference_no' => 'DEMO-NEFT-002', 'bank_cash_account_id' => $bank, 'allocations' => [['document_id' => $inv2, 'amount' => '35400']]]);
        service('transfers')->create(['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '5900', 'transfer_date' => $d3, 'notes' => 'DEMO cash deposit']);
        CLI::write('Invoices, cash memo, estimate draft, receipts and a cash deposit created (one invoice left unpaid, one part-paid).');
    }

    private function expenses(array $c, int $bank, array $emps): void
    {
        $cats = array_column(db_connect()->table('expense_categories')->get()->getResultArray(), 'id', 'code');
        service('authz')->actAs($this->users['accountant'][0]);
        $ids = [
            service('expenses')->create(['expense_date' => date('Y-m-d', strtotime('-15 days')), 'category_id' => (string) $cats['RENT'], 'vendor_contact_id' => (string) $c['landlord'], 'description' => 'DEMO office rent', 'taxable_amount' => '20000', 'gst_type' => 'none', 'gst_rate' => '0', 'paid_through' => 'unpaid']),
            service('expenses')->create(['expense_date' => date('Y-m-d', strtotime('-10 days')), 'category_id' => (string) $cats['SAAS'], 'vendor_contact_id' => (string) $c['saas'], 'vendor_invoice_no' => 'DEMO-CT-77', 'description' => 'DEMO design tool subscription', 'taxable_amount' => '5000', 'gst_type' => 'igst', 'gst_rate' => '18', 'paid_through' => 'unpaid']),
            service('expenses')->create(['expense_date' => date('Y-m-d', strtotime('-8 days')), 'category_id' => (string) $cats['TRAVEL'], 'description' => 'DEMO client visit cab fare', 'taxable_amount' => '1200', 'gst_type' => 'none', 'gst_rate' => '0', 'paid_through' => 'employee_reimbursable', 'paid_by_employee_id' => (string) $emps['crm_manager']]),
        ];
        service('authz')->actAs($this->owner);
        foreach ($ids as $id) {
            service('expenses')->approve($id);
        }
        service('expenses')->pay($ids[0], ['amount' => '20000', 'payment_date' => date('Y-m-d', strtotime('-14 days')), 'bank_cash_account_id' => (string) $bank, 'mode' => 'neft']);
        CLI::write('Expenses recorded by Accounts and approved by the owner (rent paid, SaaS unpaid, cab fare reimbursable).');
    }

    private function crm(array $c): void
    {
        $tc   = [$this->users['telecaller'][0], $this->users['telecaller2'][0]];
        $biz  = ['Sunrise Physiotherapy', 'Urban Kitchen', 'Royal Jewellers', 'Peak Fitness Gym', 'Little Stars Play School', 'Metro Car Care', 'Bloom Florist', 'Classic Tailors', 'Zen Spa', 'Prime Hardware', 'City Pet Clinic', 'Nova Coaching'];
        $leads = [];
        foreach ($biz as $i => $b) {
            $leads[] = service('leads')->create(['company_name' => self::P . $b, 'contact_person' => 'Demo Contact ' . ($i + 1), 'mobile' => '91' . str_pad((string) (10000000 + $i * 1371), 8, '0', STR_PAD_LEFT),
                'city' => ['Bhiwadi', 'Alwar', 'Jaipur', 'Gurugram'][$i % 4], 'industry' => 'Local business', 'services_interested' => ['SEO', 'Social media'], 'assigned_to' => (string) $tc[$i % 2], 'priority' => $i % 5 === 0 ? 'hot' : 'medium'], true);
        }
        foreach ($leads as $i => $id) {
            service('authz')->actAs($tc[$i % 2]);
            $outcomes = $i === 0 ? ['no_answer', 'busy', 'interested'] : [['connected', 'call_back', 'not_interested', 'no_answer'][$i % 4]];
            foreach ($outcomes as $k => $o) {
                service('leads')->logCall($id, ['outcome' => $o, 'direction' => 'outbound', 'notes' => 'DEMO call note ' . ($k + 1), 'called_at' => date('Y-m-d H:i:s', strtotime('-' . (6 - $k) . ' days 11:00')),
                    'next_action_at' => in_array($o, ['call_back', 'interested', 'no_answer', 'busy'], true) ? ($i % 3 === 0 ? date('Y-m-d H:i:s', time() + 7200) : date('Y-m-d H:i:s', strtotime('+' . (1 + $i % 3) . ' days 12:00'))) : null]);
            }
            if ($i % 3 === 2) {
                // a follow-up that was missed yesterday, to show the overdue lists and reminders
                service('leads')->addFollowup($id, $tc[$i % 2], date('Y-m-d 15:00:00', strtotime('-1 day')), 'Call back about pricing (DEMO overdue)');
            }
        }
        $owner = $this->owner;
        service('authz')->actAs($tc[0]);
        service('leads')->scheduleVisit(['lead_id' => $leads[0], 'visitor_id' => $owner, 'scheduled_at' => date('Y-m-d 16:00', strtotime('+2 days')), 'purpose' => 'Owner to present SEO proposal', 'location' => 'Client office']);
        service('authz')->actAs($owner);
        $conv = service('leads')->convert($leads[1], ['contact_id' => (string) $c['realty'], 'attributed_user_id' => (string) $tc[1], 'contract_value' => '45000']);
        service('incentives')->savePolicy(['name' => self::P . 'Telecaller 10% of collections (net of GST)', 'scope_type' => 'role', 'scope_ref' => 'telecaller', 'basis' => 'collections_net_of_tax',
            'calc_type' => 'percent', 'value' => '10', 'cutoff_day' => '31', 'requires_approval' => '1', 'clawback_on_refund' => '1', 'carry_forward' => '1', 'is_active' => '1', 'effective_from' => date('Y-m-01', strtotime('-3 months'))]);
        CLI::write('12 leads with call history, follow-ups (some overdue), an owner visit, one conversion and an incentive policy created.');
    }

    private function recruitment(): void
    {
        service('authz')->actAs($this->users['hr_manager'][0]);
        $job = service('recruitment')->createVacancy([
            'title' => self::P . 'SEO Executive', 'status' => 'open', 'location' => 'Bhiwadi', 'experience_min' => '1', 'experience_max' => '3', 'salary_min' => '18000', 'salary_max' => '28000',
            'recruiter_user_id' => (string) $this->users['hr_recruiter'][0], 'interviewer_user_id' => (string) $this->users['hr_manager'][0],
            'summary' => 'Plan and execute on-page and off-page SEO for local business clients and report monthly results.',
            'skills' => "Keyword research\nOn-page SEO\nGoogle Search Console\nGA4", 'must_have' => "1+ year doing SEO for clients\nGoogle Search Console", 'nice_to_have' => "Local SEO / Google Business Profile\nBasic HTML",
        ]);
        $cvs = [
            ['DEMO Applicant One', "SEO Executive at Demo Agency (2023 - present): handled on-page SEO and Google Search Console for 9 clients.\nRanked 30 local keywords on page one.", 'resume_screened'],
            ['DEMO Applicant Two', "Content writer (2022 - 2024). Basic knowledge of keyword research. Wrote 200 blog posts.", 'applied'],
            ['DEMO Applicant Three', "Digital Marketing Intern: GA4 reports, Google Business Profile optimisation for 4 clinics, basic HTML edits.", 'hr_screening'],
        ];
        foreach ($cvs as $i => [$name, $body, $status]) {
            $cid = service('recruitment')->createCandidate(['job_opening_id' => (string) $job, 'full_name' => self::P . $name, 'email' => 'applicant' . ($i + 1) . '@demo.dgr.local', 'source' => 'Demo',
                'total_experience_years' => (string) (1 + $i), 'consent_given' => '1', 'ai_share_consent' => $i === 0 ? '1' : '']);
            $pdf = new \Dompdf\Dompdf();
            $pdf->loadHtml('<h2>' . esc($name) . ' (DEMO CV — fictional person)</h2><h3>Experience</h3><p>' . nl2br(esc($body)) . '</p><h3>Education</h3><p>BBA, 2022</p>');
            $pdf->render();
            service('recruitment')->storeCvBytes($cid, $pdf->output(), 'demo-cv-' . ($i + 1) . '.pdf');
            service('cvEvaluation')->evaluate($cid, 'rules');
            if ($status !== 'applied') {
                service('recruitment')->changeStatus($cid, $status, 'DEMO screening note');
            }
            if ($i === 0) {
                $iv = service('recruitment')->scheduleInterview($cid, ['interview_type' => 'technical', 'scheduled_at' => date('Y-m-d 11:00', strtotime('-2 days')), 'interviewer_id' => (string) $this->users['hr_manager'][0]]);
                service('recruitment')->completeInterview($iv, ['status' => 'completed', 'result' => 'pass', 'overall_rating' => '4', 'feedback' => 'DEMO: clear on Search Console; verify link-building approach.',
                    'scorecards' => [['criterion' => 'Role knowledge', 'rating' => '4'], ['criterion' => 'Communication', 'rating' => '4']]]);
            }
        }
        CLI::write('Vacancy with JD, 3 applicants with generated CVs, keyword checks and one interview created (AI stays off).');
    }

    private function payroll(array $emps, int $bank): void
    {
        service('authz')->actAs($this->owner);
        $period = date('Y-m', strtotime('first day of last month'));
        $cal    = service('hrCalendar');
        foreach (HrCalendar::dates($period . '-01', date('Y-m-t', strtotime($period . '-01'))) as $d) {
            if ($cal->dayKind($d) !== null) {
                continue;
            }
            $rows = [];
            foreach ($emps as $k => $e) {
                $rows[$e] = ['status' => ($k === 'employee' && substr($d, 8) === '10') ? 'absent' : 'present', 'check_in' => '09:45', 'check_out' => '18:30'];
            }
            service('attendance')->markDay($d, $rows);
        }
        $cl = db_connect()->table('leave_types')->where('code', 'CL')->get()->getRow()->id;
        $lr = service('leaves')->request($emps['hr_recruiter'], ['leave_type_id' => (string) $cl, 'from_date' => date('Y-m-d', strtotime('+7 days')), 'to_date' => date('Y-m-d', strtotime('+8 days')), 'reason' => 'DEMO family function']);
        $run = service('payroll')->createRun($period);
        service('payroll')->calculate($run);
        service('payroll')->finalize($run);
        service('payroll')->pay($run, null, ['payment_date' => date('Y-m-05'), 'bank_cash_account_id' => (string) $bank, 'mode' => 'neft', 'reference_no' => 'DEMO-SALARY']);
        CLI::write('Attendance for ' . $period . ', payroll finalized and paid, payslips generated, one pending leave request.');
    }

    // ---------------------------------------------------------------- purge

    private function purge(): int
    {
        $db = db_connect();
        if (! service('settingsStore')->get('demo.loaded', false) && $db->table('auth_identities')->like('secret', '@demo.dgr.local', 'before')->countAllResults() === 0) {
            CLI::write('No demo data found.');

            return EXIT_SUCCESS;
        }
        $real = $db->table('contacts')->notLike('name', self::P, 'after')->countAllResults() + $db->table('employees')->notLike('full_name', self::P, 'after')->countAllResults()
            + $db->table('leads')->notLike('company_name', self::P, 'after')->countAllResults();
        if ($real > 0) {
            CLI::error("{$real} non-demo business record(s) exist, so business tables cannot be wiped automatically. Remove demo records manually or restore a pre-demo backup.");

            return EXIT_ERROR;
        }
        $realOwners = (int) $db->query("SELECT COUNT(DISTINCT ur.user_id) n FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN auth_identities i ON i.user_id = ur.user_id AND i.type = 'email_password'
            JOIN users u ON u.id = ur.user_id WHERE r.code = 'super_admin' AND u.active = 1 AND u.deleted_at IS NULL AND i.secret NOT LIKE '%@demo.dgr.local'")->getRow()->n;
        if ($realOwners === 0) {
            CLI::error('Create your own owner login first (php spark dgr:create-owner) — purge removes every @demo.dgr.local account.');

            return EXIT_ERROR;
        }
        if (CLI::prompt('This deletes ALL billing, finance, CRM, HR and payroll records (configuration, owner and audit log are kept). Type PURGE to continue', null) !== 'PURGE') {
            CLI::write('Cancelled.');

            return EXIT_SUCCESS;
        }
        $files = $db->table('stored_files')->whereIn('category', ['cv', 'candidate', 'interview', 'jd', 'employee', 'payslip', 'billing_pdf', 'report_export', 'expense', 'bank_import', 'bank_statement', 'contact'])->get()->getResultArray();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::WIPE as $t) {
                $db->query('DELETE FROM `' . $t . '`');
            }
            foreach ($files as $f) {
                try {
                    @unlink(service('files')->path($f));
                } catch (Throwable) {
                }
            }
            $db->table('stored_files')->whereIn('id', array_column($files, 'id') ?: [0])->delete();
            $demoUsers = array_column($db->table('auth_identities')->select('user_id')->like('secret', '@demo.dgr.local', 'before')->get()->getResultArray(), 'user_id');
            foreach (['user_roles', 'user_profiles', 'user_permission_overrides', 'user_record_scopes', 'user_sessions', 'auth_identities', 'auth_logins', 'auth_remember_tokens', 'auth_groups_users', 'auth_permissions_users'] as $t) {
                if ($demoUsers !== [] && $db->fieldExists('user_id', $t)) {
                    $db->table($t)->whereIn('user_id', $demoUsers)->delete();
                }
            }
            if ($demoUsers !== []) {
                $db->table('users')->whereIn('id', $demoUsers)->delete();
            }
            $demoBank = $db->table('bank_cash_accounts')->where('code', 'DEMOBANK')->get()->getRowArray();
            if ($demoBank) {
                $db->table('bank_cash_accounts')->where('id', $demoBank['id'])->delete();
                $db->table('ledger_accounts')->where('id', $demoBank['ledger_account_id'])->delete();
            }
            $db->table('document_sequences')->update(['next_number' => 1]);
            $db->table('company_settings')->where('id', 1)->like('legal_name', 'DEMO', 'both')->update(['legal_name' => null, 'gstin' => null, 'pan' => null, 'reg_address_line1' => null]);
            $db->table('app_settings')->where('setting_key', 'demo.loaded')->delete();
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        service('audit')->log('demo.purged', 'system', null, null, null, 'Demo data purged');
        CLI::write('Demo data removed. Document numbering restarts at 1. Enter the real company identity in Settings › Setup before issuing documents.', 'green');

        return EXIT_SUCCESS;
    }
}
