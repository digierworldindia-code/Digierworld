<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Platform settings = configurable business policy.
 *
 * approval_status "pending_approval" marks defaults that still need a client
 * decision (see docs/08-client-decisions-register.md). Re-running the seeder
 * never overwrites values an administrator has changed.
 */
class SettingsSeeder extends Seeder
{
    public static function definitions(): array
    {
        $P = 'pending_approval';
        $A = 'approved';

        // key => [value, type, group, label, description, approval]
        return [
            'platform.name'              => ['BearingCave', 'string', 'platform', 'Platform name', 'Shown in titles and emails.', $A],
            'platform.support_email'     => ['support@bearingcave.com', 'string', 'platform', 'Support email', 'Public support address (confirm mailbox exists).', $P],
            'platform.support_phone'     => ['', 'string', 'platform', 'Support phone', 'Public support phone number.', $P],
            'platform.default_currency'  => ['INR', 'string', 'platform', 'Default currency', 'Base currency for fees and reports.', $A],
            'platform.blocked_countries' => ['[]', 'json', 'platform', 'Blocked registration countries', 'ISO-2 codes that cannot register (sanctions / legal). JSON list.', $P],
            'platform.settlement_currency_rules' => ['Orders are invoiced in the currency of the accepted quotation or listing. No automatic FX conversion.', 'text', 'platform', 'Commercial settlement currency rules', 'Displayed on order terms.', $P],

            'membership.verified_plan_code' => ['supplier_verified', 'string', 'membership', 'Verified supplier plan code', 'Plan offered after verification approval.', $A],
            'membership.reminder_days'   => ['[30,7]', 'json', 'membership', 'Renewal reminder days', 'Days before expiry to remind suppliers. JSON list.', $A],
            'buyer.verified_plan_code'   => ['buyer_verified', 'string', 'membership', 'Verified buyer plan code', 'Entitlement plan applied to verified buyers.', $A],

            'tax.subscription_rate_pct'  => ['18', 'decimal', 'tax', 'Tax % on membership fee', 'GST/VAT treatment of the ₹50,000 annual verification fee — confirm with tax advisor.', $P],
            'tax.inspection_rate_pct'    => ['18', 'decimal', 'tax', 'Tax % on inspection fees', 'Confirm with tax advisor.', $P],
            'tax.logistics_rate_pct'     => ['18', 'decimal', 'tax', 'Tax % on logistics fees', 'Confirm with tax advisor.', $P],
            'tax.proforma_rate_pct'      => ['0', 'decimal', 'tax', 'Tax % on proforma (goods) invoices', 'Goods tax depends on supplier, HSN code and destination; 0 until rules are approved.', $P],
            'billing.invoice_due_days'   => ['15', 'int', 'billing', 'Invoice due days', 'Payment terms for platform invoices.', $P],
            'billing.refund_policy'      => ['Refunds and cancellations are handled case-by-case by BearingCave finance until a policy is approved.', 'text', 'billing', 'Cancellation & refund rules', 'Shown on invoices and checkout.', $P],

            'verification.supplier_stages' => ['["registration","documents","kyc","compliance","financial","risk","quality","site_audit","sanctions","management_approval"]', 'json', 'verification', 'Supplier verification stages', 'Stages required before management approval.', $P],
            'verification.buyer_stages'  => ['["registration","documents","kyc","sanctions","management_approval"]', 'json', 'verification', 'Buyer verification stages', 'Stages required for verified buyer status.', $P],
            'verification.document_reminder_days' => ['[30,7]', 'json', 'verification', 'Document expiry reminder days', 'JSON list of days before expiry.', $A],
            'verification.suspend_on_expired_docs' => ['0', 'bool', 'verification', 'Auto-suspend on expired mandatory documents', 'Supplier suspension policy (pending).', $P],

            'risk.weights'               => ['{"financial_risk":1,"operational_risk":1,"compliance_risk":1,"geographic_risk":1,"supply_risk":1,"esg_risk":1}', 'json', 'risk', 'Risk factor weights', 'Relative weight of each 1–5 factor score.', $P],
            'risk.thresholds'            => ['{"low_max":2.0,"medium_max":3.5}', 'json', 'risk', 'Risk level thresholds', 'Weighted score ≤ low_max = LOW, ≤ medium_max = MEDIUM, else HIGH.', $P],
            'sanctions.provider'         => ['none', 'string', 'risk', 'Sanctions screening provider', 'none = manual screening recorded by officers. Integrate an authorised provider before automated checks.', $P],

            'catalog.review_policy'      => ['all', 'string', 'catalog', 'Listing review policy', 'all | unverified | none — which supplier listings need admin approval.', $P],
            'catalog.guest_can_see_prices' => ['0', 'bool', 'catalog', 'Guests can see prices', 'If off, visitors must sign in to see prices.', $P],
            'catalog.max_compare'        => ['4', 'int', 'catalog', 'Max products in comparison', '', $A],
            'buyer.restricted_access_policy' => ['Verified buyers are granted restricted inventory access individually by a Verification Officer.', 'text', 'catalog', 'Restricted inventory access policy', 'Shown to buyers.', $P],
            'supplier.contact_disclosure_policy' => ['Supplier contact details are shown only for verified suppliers who opt in, to signed-in buyers, and never on confidential listings.', 'text', 'catalog', 'Supplier contact disclosure conditions', '', $P],

            'inspection.in_house_rate_pct' => ['10', 'decimal', 'services', 'In-house inspection % of invoice value', 'From the requirements spreadsheet (10%).', $A],
            'inspection.in_house_min_fee'  => ['0', 'decimal', 'services', 'In-house inspection minimum fee', '0 = no minimum. Pending client decision.', $P],
            'logistics.platform_fee_pct'   => ['10', 'decimal', 'services', 'BearingCave-managed logistics charge %', 'From the spreadsheet (optional additional 10%).', $A],
            'logistics.platform_fee_basis' => ['manual_quote', 'string', 'services', 'Logistics charge calculation base', 'manual_quote | goods_value | freight_cost. Base not defined in spreadsheet — pending.', $P],
            'logistics.consolidation_policy' => ['Consolidation is available to verified buyers on request; pricing quoted per shipment.', 'text', 'services', 'Shipping/consolidation privileges for verified buyers', '', $P],

            'rfq.auto_match_on_submit'   => ['1', 'bool', 'rfq', 'Auto-run supplier matching on submission', 'Matching only suggests suppliers; distribution stays admin-controlled.', $A],
            'rfq.auto_distribute'        => ['0', 'bool', 'rfq', 'Auto-distribute to top matches', 'Off = BearingCave admin reviews and distributes each RFQ.', $P],
            'rfq.min_match_score'        => ['20', 'decimal', 'rfq', 'Minimum match score', 'Suppliers below this score are not suggested.', $P],
            'rfq.max_auto_invites'       => ['10', 'int', 'rfq', 'Max suppliers per auto distribution', '', $P],
            'rfq.matching_weights'       => ['{"part_number":50,"oem_number":40,"cross_reference":25,"brand":15,"category":10,"stock_sufficient":10,"avl":10,"performance_max":10}', 'json', 'rfq', 'Matching weights', 'Points per matching signal.', $P],
            'rfq.max_revisions'          => ['5', 'int', 'rfq', 'Max quotation revisions', '', $P],
            'rfq.default_deadline_days'  => ['7', 'int', 'rfq', 'Default RFQ deadline (days)', '', $A],
            'rfq.distribution_criteria'  => ['RFQs go to verified suppliers with active membership whose listed stock, brands, categories or AVL approvals match.', 'text', 'rfq', 'RFQ distribution criteria', '', $P],

            'ranking.performance_weight' => ['1', 'decimal', 'ranking', 'Performance weight in ranking', 'Ranking = performance × weight + plan boost.', $P],
            'performance.window_days'    => ['365', 'int', 'ranking', 'Performance window (days)', '', $A],

            'payments.bank_transfer_enabled' => ['1', 'bool', 'payments', 'Bank transfer enabled', 'Finance confirms receipt manually.', $A],
            'payments.bank_instructions' => ['', 'text', 'payments', 'Bank remittance instructions', 'Account details shown to payers. Leave empty until finance confirms.', $P],
            'payments.sandbox_enabled'   => [ENVIRONMENT === 'production' ? '0' : '1', 'bool', 'payments', 'Sandbox gateway enabled (non-production only)', 'Test payments that move no money.', $A],
            'payments.escrow_provider'   => ['none', 'string', 'payments', 'Escrow / payment partner', 'No escrow is simulated. Integrate a licensed provider once selected.', $P],

            'email.delivery_enabled'     => ['0', 'bool', 'notifications', 'Email delivery enabled', 'Enable after SMTP is configured in .env. Until then emails stay in the outbox as "disabled".', $P],
            'email.send_immediately'     => ['1', 'bool', 'notifications', 'Send emails immediately', 'Otherwise run: php spark bearingcave:outbox', $A],
            'notifications.email_disabled_types' => ['[]', 'json', 'notifications', 'Notification types without email', 'JSON list of notification types.', $A],
            'notifications.sms_provider' => ['none', 'string', 'notifications', 'SMS provider', 'Integration point only; no SMS is sent.', $P],
            'notifications.whatsapp_provider' => ['none', 'string', 'notifications', 'WhatsApp provider', 'Integration point only; no WhatsApp messages are sent.', $P],

            'documents.max_upload_mb'    => ['10', 'int', 'platform', 'Max upload size (MB)', '', $A],
            'imports.max_rows'           => ['5000', 'int', 'platform', 'Max rows per import', '', $A],
            'cart.max_items'             => ['50', 'int', 'platform', 'Max basket lines', '', $A],

            'seo.default_title'          => ['BearingCave — Global B2B Automotive Surplus Marketplace', 'string', 'seo', 'Default page title', '', $P],
            'seo.default_description'    => ['Buy and sell genuine surplus, obsolete and slow-moving automotive inventory with verified manufacturers and suppliers worldwide.', 'string', 'seo', 'Default meta description', '', $P],
            'seo.indexing_enabled'       => ['0', 'bool', 'seo', 'Allow search engine indexing', 'Keep off on staging. When off, robots.txt disallows everything and pages send noindex.', $P],
            'seo.google_site_verification' => ['', 'string', 'seo', 'Google Search Console verification token', '', $A],

            'branding.primary_color'     => ['#0B1F3A', 'string', 'branding', 'Primary colour (provisional)', 'Deep navy until official brand assets are approved.', $P],
            'branding.accent_color'      => ['#1F5FBF', 'string', 'branding', 'Accent colour (provisional)', 'Premium blue.', $P],
            'legal.warranty_terms'       => ['Product quality and warranty obligations remain with the supplier and buyer under their agreed terms.', 'text', 'legal', 'Warranty & returns terms', '', $P],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $key => [$value, $type, $group, $label, $desc, $approval]) {
            if ($this->db->table('platform_settings')->where('setting_key', $key)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('platform_settings')->insert([
                'setting_key' => $key, 'setting_value' => $value, 'value_type' => $type, 'setting_group' => $group,
                'label' => $label, 'description' => $desc, 'approval_status' => $approval,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
