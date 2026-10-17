<?php

namespace App\Database\Seeds;

use App\Services\Billing\FinancialYearService;
use CodeIgniter\Database\Seeder;
use Config\Permissions;

/**
 * Base configuration data. Idempotent (INSERT IGNORE / exists checks).
 * Tax rates, SAC codes and ITC rules are seeded as SUGGESTIONS flagged for
 * accountant review — nothing here is a legal determination.
 * No company identity, GSTIN or bank details are seeded.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->companyRow();
        $this->ledgerAccounts();
        $this->taxTreatments();
        $this->taxRates();
        $this->templates();
        $this->cashAccount();
        (new FinancialYearService($this->db))->current();
        $this->services();
        $this->crm();
        $this->hr();
        $this->expenses();
        $this->ai();
        $this->googleSync();
        $this->appSettings();
    }

    private function companyRow(): void
    {
        $this->db->query("INSERT IGNORE INTO company_settings (id, trade_name, website, fy_start_month, currency, timezone) VALUES (1, 'DIGIE R WORLD', 'www.digierworld.com', 4, 'INR', 'Asia/Kolkata')");
    }

    private function ledgerAccounts(): void
    {
        $accounts = [
            // code, name, group, sub_type
            ['1300', 'Accounts Receivable (Debtors)', 'asset', 'receivable'],
            ['1400', 'Input CGST', 'asset', 'input_cgst'],
            ['1401', 'Input SGST', 'asset', 'input_sgst'],
            ['1402', 'Input UTGST', 'asset', 'input_utgst'],
            ['1403', 'Input IGST', 'asset', 'input_igst'],
            ['1410', 'Employee Advances', 'asset', 'employee_advance'],
            ['2100', 'Accounts Payable (Creditors)', 'liability', 'payable'],
            ['2200', 'Output CGST', 'liability', 'output_cgst'],
            ['2201', 'Output SGST', 'liability', 'output_sgst'],
            ['2202', 'Output UTGST', 'liability', 'output_utgst'],
            ['2203', 'Output IGST', 'liability', 'output_igst'],
            ['2204', 'Output Cess', 'liability', 'output_cess'],
            ['2300', 'Customer Advances (Unapplied Receipts)', 'liability', 'customer_advance'],
            ['2400', 'Salary Payable', 'liability', 'salary_payable'],
            ['2411', 'PF Payable', 'liability', 'pf_payable'],
            ['2412', 'ESI Payable', 'liability', 'esi_payable'],
            ['2413', 'Professional Tax Payable', 'liability', 'pt_payable'],
            ['2414', 'TDS Payable', 'liability', 'tds_payable'],
            ['2420', 'Employee Reimbursements Payable', 'liability', 'reimbursement_payable'],
            ['3100', "Owner's Capital", 'equity', 'capital'],
            ['3200', 'Opening Balance Equity', 'equity', 'opening_equity'],
            ['4100', 'Sales — Services (Taxable)', 'income', 'sales'],
            ['4110', 'Sales — Exempt / Nil / Non-GST', 'income', 'sales_exempt'],
            ['4120', 'Sales — Zero-rated / Export', 'income', 'sales_zero_rated'],
            ['4200', 'Sales Returns / Credit Notes', 'income', 'sales_returns'],
            ['4900', 'Round-off', 'income', 'round_off'],
            ['5100', 'Salary Expense', 'expense', 'salary_expense'],
            ['5110', 'Sales Incentive Expense', 'expense', 'incentive_expense'],
            ['5120', 'Employer Statutory Contributions', 'expense', 'employer_statutory'],
            ['5200', 'Office Rent', 'expense', 'expense'],
            ['5210', 'Internet & Telephone', 'expense', 'expense'],
            ['5220', 'Software & SaaS Subscriptions', 'expense', 'expense'],
            ['5230', 'Hosting & Domains', 'expense', 'expense'],
            ['5240', 'Client Ad Spend (Pass-through)', 'expense', 'expense'],
            ['5250', 'Travel & Conveyance', 'expense', 'expense'],
            ['5260', 'Office Expenses & Supplies', 'expense', 'expense'],
            ['5270', 'Electricity', 'expense', 'expense'],
            ['5280', 'Freelancer / Contractor Charges', 'expense', 'expense'],
            ['5290', 'Professional & Legal Fees', 'expense', 'expense'],
            ['5300', 'Bank Charges', 'expense', 'expense'],
            ['5310', 'Own Marketing & Advertising', 'expense', 'expense'],
            ['5320', 'Repairs & Maintenance', 'expense', 'expense'],
            ['5330', 'Staff Welfare', 'expense', 'expense'],
            ['5390', 'Miscellaneous Expenses', 'expense', 'expense'],
        ];
        foreach ($accounts as [$code, $name, $group, $sub]) {
            $this->db->query(
                'INSERT IGNORE INTO ledger_accounts (code, name, account_group, sub_type, is_system, is_active) VALUES (?, ?, ?, ?, 1, 1)',
                [$code, $name, $group, $sub]
            );
        }
    }

    private function taxTreatments(): void
    {
        $rows = [
            ['regular', 'Regular taxable', 1, 'B2B / B2CL / B2CS', 0, 10, 'GST charged at the line rate.'],
            ['exempt', 'Exempt supply', 0, 'Nil/Exempt/Non-GST (exempt)', 1, 20, 'No GST; requires reason + accountant review.'],
            ['nil_rated', 'Nil-rated (0%)', 0, 'Nil/Exempt/Non-GST (nil)', 1, 30, 'Taxable at 0% rate per notification; requires reason.'],
            ['non_gst', 'Non-GST / Out of scope', 0, 'Nil/Exempt/Non-GST (non-GST)', 1, 40, 'Outside GST (e.g. reimbursement as pure agent); requires reason + review.'],
            ['zero_rated_lut', 'Zero-rated export / SEZ — under LUT (no IGST)', 0, 'EXP-WOPAY / SEZWOP', 1, 50, 'Requires valid LUT number in company settings.'],
            ['zero_rated_igst', 'Zero-rated export / SEZ — with IGST payment', 1, 'EXP-WPAY / SEZWP', 1, 60, 'IGST charged; refund claim is outside this app.'],
            ['reverse_charge', 'Reverse charge (recipient pays)', 0, 'B2B (RCM)', 1, 70, 'Tax shown for information; not collected by supplier. Accountant review required.'],
        ];
        foreach ($rows as [$code, $label, $charges, $section, $reason, $order, $notes]) {
            $this->db->query(
                'INSERT IGNORE INTO tax_treatments (code, label, charges_tax, gstr1_section, requires_reason, is_enabled, notes, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                [$code, $label, $charges, $section, $reason, $notes, $order]
            );
        }
    }

    private function taxRates(): void
    {
        if ($this->db->table('tax_rates')->countAllResults() > 0) {
            return;
        }
        foreach ([['GST 0%', '0'], ['GST 5%', '5'], ['GST 12%', '12'], ['GST 18%', '18'], ['GST 28%', '28']] as [$name, $rate]) {
            $this->db->table('tax_rates')->insert([
                'name' => $name, 'rate' => $rate, 'cess_rate' => '0', 'effective_from' => '2017-07-01',
                'review_status' => 'suggested', 'notes' => 'Seeded suggestion — confirm applicability with your accountant.', 'is_active' => 1,
            ]);
        }
    }

    private function templates(): void
    {
        $base = [
            'show_logo' => true, 'logo_position' => 'top_left', 'show_bank' => true, 'show_upi_qr' => true,
            'show_signature' => true, 'show_stamp' => true, 'show_terms' => true, 'show_tax_summary' => true,
            'show_amount_words' => true, 'show_page_numbers' => true, 'font_size' => 9.5,
            'margin_mm' => 12, 'footer_note' => '',
        ];
        $templates = [
            ['classic', 'Classic Professional', 'classic', $base + ['accent' => '#222222']],
            ['minimal', 'Minimal Corporate', 'minimal', $base + ['accent' => '#555555']],
            ['premium', 'Premium DGR Black & Gold', 'premium', $base + ['accent' => '#B08D57']],
            ['memo_a5', 'Cash Memo A5 (compact)', 'minimal', ['paper' => 'A5'] + $base + ['accent' => '#222222', 'font_size' => 8.5, 'margin_mm' => 8]],
        ];
        foreach ($templates as [$code, $name, $layout, $settings]) {
            $paper = $settings['paper'] ?? 'A4';
            unset($settings['paper']);
            $this->db->query(
                'INSERT IGNORE INTO document_templates (code, name, version, layout, paper, settings, is_default, is_active) VALUES (?, ?, 1, ?, ?, ?, ?, 1)',
                [$code, $name, $layout, $paper, json_encode($settings), $code === 'premium' ? 1 : 0]
            );
        }
        $default = $this->db->table('document_templates')->where('is_default', 1)->get()->getRowArray();
        if ($default !== null) {
            $this->db->query('UPDATE company_settings SET default_template_id = ? WHERE id = 1 AND default_template_id IS NULL', [$default['id']]);
        }
    }

    private function cashAccount(): void
    {
        if ($this->db->table('bank_cash_accounts')->where('code', 'CASH')->countAllResults() > 0) {
            return;
        }
        $this->db->table('ledger_accounts')->insert([
            'code' => '1100', 'name' => 'Cash in Hand', 'account_group' => 'asset', 'sub_type' => 'cash', 'is_system' => 1,
        ]);
        $ledgerId = (int) $this->db->insertID();
        $this->db->table('bank_cash_accounts')->insert([
            'code' => 'CASH', 'account_kind' => 'cash', 'ownership' => 'company', 'display_name' => 'Cash in Hand (Office)',
            'ledger_account_id' => $ledgerId, 'display_priority' => 1, 'opening_balance' => '0.00', 'is_active' => 1,
        ]);
    }

    private function services(): void
    {
        if ($this->db->table('services')->countAllResults() > 0) {
            return;
        }
        $rate18 = $this->db->table('tax_rates')->where('rate', '18')->get()->getRowArray()['id'] ?? null;
        // SAC values are suggestions only (review_status=suggested); blank where classification needs an accountant.
        $svc = [
            ['SMM', 'Social Media Management (Monthly Retainer)', 'Social Media', 'Monthly management of brand pages: content calendar, creatives, posting, community management and monthly report.', '998361', 'Month', '15000', 1],
            ['SEO', 'Search Engine Optimisation (Monthly)', 'SEO', 'Keyword research, on-page optimisation, technical SEO fixes, content recommendations and monthly ranking report.', '998361', 'Month', '12000', 1],
            ['GADS', 'Google Ads Management', 'Paid Ads', 'Campaign setup, ad copy, targeting, bid management, conversion tracking and monthly performance report. Ad spend billed separately.', '998361', 'Month', '10000', 1],
            ['GBP', 'Google Business Profile Optimisation', 'Local SEO', 'Profile setup/verification support, optimisation, posts, review response guidance.', '998361', 'Month', '5000', 1],
            ['WEB', 'Website Design & Development', 'Development', 'Responsive website design and development as per approved scope.', '998314', 'Project', '35000', 0],
            ['APP', 'Mobile App Development', 'Development', 'Mobile application design and development as per approved scope.', '998314', 'Project', '150000', 0],
            ['CRM', 'CRM / Custom Software Development', 'Development', 'Custom CRM/software development as per approved scope document.', '998314', 'Project', '75000', 0],
            ['GFX', 'Graphic Design', 'Creative', 'Logo, brochure, social creatives and other graphic design deliverables.', '998391', 'Job', '3000', 0],
            ['CREATIVE', 'Campaign Creative Package', 'Creative', 'Campaign creatives set (static/video) as per brief.', '998391', 'Package', '8000', 0],
            ['YT', 'YouTube Channel Management', 'Social Media', 'Channel optimisation, upload management, SEO for videos, analytics report.', '998361', 'Month', '10000', 1],
            ['AIO', 'AI Optimisation / Automation Setup', 'Consulting', 'AI tools/automation setup and optimisation consulting.', '998313', 'Project', '20000', 0],
            ['HOST', 'Website Hosting (Annual)', 'Hosting', 'Annual hosting service.', '998315', 'Year', '6000', 1],
            ['DOMAIN', 'Domain Registration / Renewal', 'Hosting', 'Domain registration/renewal (classification to be confirmed by accountant).', '', 'Year', '1200', 1],
            ['BULKSMS', 'Bulk SMS Service', 'Messaging', 'Bulk SMS credits/service (SAC to be confirmed by accountant).', '', 'Pack', '5000', 0],
            ['WAAPI', 'WhatsApp Business Messaging', 'Messaging', 'WhatsApp Business API messaging service (SAC to be confirmed).', '', 'Pack', '5000', 0],
            ['IVR', 'IVR Service', 'Messaging', 'IVR setup and monthly service (SAC to be confirmed).', '', 'Month', '3000', 1],
        ];
        foreach ($svc as [$code, $name, $cat, $desc, $sac, $unit, $rate, $recurring]) {
            $this->db->table('services')->insert([
                'code' => $code, 'name' => $name, 'category' => $cat, 'description' => $desc, 'sac_code' => $sac ?: null,
                'unit' => $unit, 'default_rate' => $rate, 'default_tax_rate_id' => $rate18, 'default_tax_treatment' => 'regular',
                'pos_rule' => 'default', 'is_recurring_type' => $recurring, 'review_status' => 'suggested', 'is_active' => 1,
            ]);
        }
    }

    private function crm(): void
    {
        $stages = [
            ['new', 'New', 10, 0, 0, 0, 0, '#6c757d'], ['not_contacted', 'Not Contacted', 20, 0, 0, 0, 0, '#adb5bd'],
            ['attempted', 'Attempted', 30, 0, 0, 0, 0, '#ffc107'], ['connected', 'Connected', 40, 0, 0, 0, 0, '#0dcaf0'],
            ['interested', 'Interested', 50, 0, 0, 0, 0, '#20c997'], ['followup', 'Follow-up', 60, 0, 0, 0, 0, '#fd7e14'],
            ['meeting_scheduled', 'Meeting Scheduled', 70, 0, 0, 0, 0, '#6610f2'], ['visit_scheduled', 'Visit Scheduled', 80, 0, 0, 0, 0, '#6f42c1'],
            ['proposal_sent', 'Proposal Sent', 90, 0, 0, 0, 0, '#0d6efd'], ['negotiation', 'Negotiation', 100, 0, 0, 0, 0, '#B08D57'],
            ['won', 'Won', 110, 1, 0, 1, 0, '#198754'], ['lost', 'Lost', 120, 0, 1, 1, 0, '#dc3545'],
            ['not_interested', 'Not Interested', 130, 0, 1, 1, 0, '#a52834'], ['dnc', 'Do Not Call', 140, 0, 1, 1, 1, '#212529'],
        ];
        foreach ($stages as [$code, $name, $order, $won, $lost, $closed, $dnd, $color]) {
            $this->db->query(
                'INSERT IGNORE INTO crm_stages (code, name, sort_order, is_won, is_lost, is_closed, is_dnd, color, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$code, $name, $order, $won, $lost, $closed, $dnd, $color]
            );
        }
        foreach (['Website', 'Google Ads', 'Facebook / Instagram', 'JustDial', 'IndiaMART', 'Referral', 'Walk-in', 'Cold Call / Data', 'WhatsApp', 'Event / Exhibition', 'Other'] as $s) {
            $this->db->query('INSERT IGNORE INTO lead_sources (name, is_active) VALUES (?, 1)', [$s]);
        }
        foreach (['Budget too low', 'Chose competitor', 'No requirement now', 'Not reachable', 'Price negotiation failed', 'Service not offered', 'Duplicate / invalid lead', 'Other'] as $r) {
            $this->db->query('INSERT IGNORE INTO lost_reasons (name, is_active) VALUES (?, 1)', [$r]);
        }
    }

    private function hr(): void
    {
        foreach (['Management', 'Sales & Telecalling', 'Digital Marketing', 'Development', 'Design', 'Accounts', 'HR & Admin'] as $d) {
            $this->db->query('INSERT IGNORE INTO departments (name, is_active) VALUES (?, 1)', [$d]);
        }
        $leaveTypes = [
            ['CL', 'Casual Leave', 1, '12.0', 0, '0.0'], ['SL', 'Sick Leave', 1, '6.0', 0, '0.0'],
            ['EL', 'Earned Leave', 1, '0.0', 1, '15.0'], ['LWP', 'Leave Without Pay', 0, '0.0', 0, '0.0'],
        ];
        foreach ($leaveTypes as [$code, $name, $paid, $quota, $cf, $max]) {
            $this->db->query(
                'INSERT IGNORE INTO leave_types (code, name, is_paid, annual_quota, carry_forward, max_carry, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$code, $name, $paid, $quota, $cf, $max]
            );
        }
        if ($this->db->table('weekly_off_rules')->countAllResults() === 0) {
            $this->db->table('weekly_off_rules')->insert(['weekday' => 0, 'week_numbers' => null, 'effective_from' => '2020-01-01', 'is_active' => 1]);
        }
        $stat = [
            ['PF', 'Provident Fund (EPF)'], ['ESI', 'Employees\' State Insurance'], ['PT', 'Professional Tax'],
            ['TDS', 'TDS on Salary'], ['LWF', 'Labour Welfare Fund'],
        ];
        foreach ($stat as [$code, $label]) {
            $this->db->query(
                "INSERT IGNORE INTO statutory_configs (code, label, enabled, notes) VALUES (?, ?, 0, 'Disabled by default. Enable and configure only after HR/accountant confirms applicability; no universal rates are assumed.')",
                [$code, $label]
            );
        }
    }

    private function expenses(): void
    {
        if ($this->db->table('itc_rules')->countAllResults() === 0) {
            $rules = [
                ['Eligible business input (to be confirmed)', 'Input services/goods used in course of business with valid tax invoice and vendor GSTIN.', 1, '100.00', null],
                ['Blocked credit — Sec 17(5)', 'E.g. food & beverages, outdoor catering, personal consumption, club memberships — confirm with accountant.', 0, '0.00', 'Sec 17(5) CGST Act'],
                ['Not eligible — no valid tax invoice / unregistered vendor', 'No GSTIN or invoice does not meet Rule 36 requirements.', 0, '0.00', 'Rule 36'],
                ['Personal / non-business use', 'Expense not in course or furtherance of business.', 0, '0.00', 'Sec 16(1)'],
            ];
            foreach ($rules as [$name, $desc, $eligible, $pct, $ref]) {
                $this->db->table('itc_rules')->insert([
                    'name' => $name, 'description' => $desc, 'eligible' => $eligible, 'eligible_percent' => $pct,
                    'block_reference' => $ref, 'review_status' => 'suggested', 'is_active' => 1,
                ]);
            }
        }
        $eligible = $this->db->table('itc_rules')->where('eligible', 1)->get()->getRowArray()['id'] ?? null;
        $blocked  = $this->db->table('itc_rules')->like('name', 'Blocked')->get()->getRowArray()['id'] ?? null;
        $cats     = [
            ['RENT', 'Office Rent', '5200', $eligible], ['NET', 'Internet & Telephone', '5210', $eligible],
            ['SAAS', 'Software & SaaS Tools', '5220', $eligible], ['HOSTING', 'Hosting & Domains', '5230', $eligible],
            ['ADSPEND', 'Client Ad Spend (Pass-through)', '5240', $eligible], ['TRAVEL', 'Travel & Conveyance', '5250', null],
            ['OFFICE', 'Office Expenses & Supplies', '5260', $eligible], ['POWER', 'Electricity', '5270', null],
            ['FREELANCE', 'Freelancer / Contractor', '5280', $eligible], ['PROF', 'Professional & Legal Fees', '5290', $eligible],
            ['BANK', 'Bank Charges', '5300', $eligible], ['MKTG', 'Own Marketing & Advertising', '5310', $eligible],
            ['REPAIR', 'Repairs & Maintenance', '5320', $eligible], ['WELFARE', 'Staff Welfare / Food', '5330', $blocked],
            ['MISC', 'Miscellaneous', '5390', null],
        ];
        foreach ($cats as [$code, $name, $ledgerCode, $rule]) {
            $ledger = $this->db->table('ledger_accounts')->where('code', $ledgerCode)->get()->getRowArray();
            $this->db->query(
                'INSERT IGNORE INTO expense_categories (code, name, ledger_account_id, default_itc_rule_id, is_active) VALUES (?, ?, ?, ?, 1)',
                [$code, $name, $ledger['id'], $rule]
            );
        }
    }

    private function ai(): void
    {
        foreach (config(Permissions::class)->aiCapabilities as $code => $cap) {
            $this->db->query(
                'INSERT IGNORE INTO ai_capabilities (code, label, enabled, allowed_fields, share_with_provider, requires_consent, rate_limit_per_hour, monthly_cost_ceiling, retention_days)
                 VALUES (?, ?, 0, ?, 0, 1, 30, 0.00, 180)',
                [$code, $cap['label'], json_encode($cap['fields'])]
            );
        }
        $this->db->query("INSERT IGNORE INTO ai_service_accounts (name, description, is_active) VALUES ('dgr-ai-gateway', 'Server-side AI gateway identity. No interactive login, no database credentials.', 1)");
    }

    private function googleSync(): void
    {
        $worksheets = ['leads' => 'Leads', 'calls' => 'Call Logs', 'followups' => 'Follow Ups', 'visits' => 'Visits', 'conversions' => 'Conversions', 'daily' => 'Daily Summary'];
        $fields     = [
            'leads'       => ['id', 'lead_code', 'company_name', 'mobile', 'address', 'city', 'industry', 'services', 'assigned_to', 'stage', 'next_followup', 'remarks', 'updated_at', 'sync_status'],
            'calls'       => ['id', 'lead_id', 'company_name', 'mobile', 'caller', 'called_at', 'outcome', 'notes', 'next_action_at', 'updated_at', 'sync_status'],
            'followups'   => ['id', 'lead_id', 'company_name', 'owner', 'due_at', 'status', 'title', 'updated_at', 'sync_status'],
            'visits'      => ['id', 'lead_id', 'company_name', 'visitor', 'scheduled_at', 'address', 'purpose', 'status', 'outcome', 'updated_at', 'sync_status'],
            'conversions' => ['id', 'lead_id', 'company_name', 'converted_at', 'attributed_to', 'contract_value', 'updated_at', 'sync_status'],
            'daily'       => ['date', 'telecaller', 'calls', 'connected', 'followups_done', 'meetings', 'visits', 'conversions', 'updated_at'],
        ];
        $this->db->query(
            'INSERT IGNORE INTO google_sync_settings (id, enabled, worksheets, field_map, frequency_minutes, direction) VALUES (1, 0, ?, ?, 5, \'db_to_sheets\')',
            [json_encode($worksheets), json_encode($fields)]
        );
    }

    private function appSettings(): void
    {
        $defaults = [
            ['crm.today_order', json_encode(['due_followups', 'overdue', 'existing', 'new', 'meetings_visits', 'completed']), 'json'],
            ['crm.reminder_minutes_before', '15', 'int'],
            ['crm.retention_days_lost', '730', 'int'],
            ['hr.candidate_retention_days', '365', 'int'],
            ['hr.consent_text_version', 'v1', 'string'],
            ['billing.allow_finalize_without_bank', '1', 'bool'],
            ['security.dangerous_grant_requires_password', '1', 'bool'],
            ['ui.date_format', 'd-m-Y', 'string'],
        ];
        foreach ($defaults as [$key, $value, $type]) {
            $this->db->query('INSERT IGNORE INTO app_settings (setting_key, setting_value, value_type) VALUES (?, ?, ?)', [$key, $value, $type]);
        }
    }
}
