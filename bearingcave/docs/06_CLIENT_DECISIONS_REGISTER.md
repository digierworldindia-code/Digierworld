# 06 — Client decisions register

No business rule was invented. Where the prompt left a decision open, the software stores a **configurable default** in `platform_settings`, marks it `pending_approval`, and does **not** switch on real-money or legally sensitive behaviour. Pending settings show a "Pending approval" badge in Admin → Platform settings. Approving a decision means setting the value and changing its status to *Approved*. No code change is needed.

| ID | Decision needed | Where configured | Current safe default | Effect until decided |
|---|---|---|---|---|
| D-01 | Provide the BearingCave Google Sheet (it was not accessible during the build) | — | Requirements taken from the written prompt | Spreadsheet-only requirements may be missing (see traceability doc) |
| D-02 | Confirm the real 86 Supplier List column headings | Admin → Supplier master data (`supplier_master_fields.is_confirmed`) | 86 proposed fields, all unconfirmed | Template and import work; headings may differ from the sheet |
| D-03 | **Minimum charge for inspection fees** (prompt §31) | `inspection.in_house_min_fee` | `0` (no minimum) | Fee = 10 % of invoice value (`inspection.in_house_rate_pct`, approved per the prompt) |
| D-04 | **Taxes on the ₹50,000 annual verification fee** (GST rate, place of supply, export of services) | `tax.subscription_rate_pct` (and `tax.inspection_rate_pct`, `tax.logistics_rate_pct`, `tax.proforma_rate_pct`) | 18 % GST on platform fees, 0 % on goods proformas | Invoices show tax as a separate line. **Needs a tax adviser's confirmation**; no GST e-invoicing integration exists |
| D-05 | Membership fee itself (₹50,000) final approval | Admin → Membership plans → Verified Supplier | ₹50,000 / 12 months, `pending_approval` | Invoices are issued at this amount |
| D-06 | **Logistics 10 % calculation base** (invoice value? freight cost? goods value excluding tax?) | `logistics.platform_fee_basis` (`manual_quote` / `goods_value` / `freight_cost`), `logistics.platform_fee_pct` = 10 | `manual_quote` | **No automatic 10 % charge.** Logistics managers quote manually; the UI shows "quoted by BearingCave" |
| D-07 | **Shipping and consolidation privileges for verified buyers** | `logistics.consolidation_policy`; entitlement `consolidation_services` | Verified buyers may request consolidation; priced per shipment | Unverified buyers cannot choose consolidation |
| D-08 | **Exact supplier contact disclosure conditions** | `supplier.contact_disclosure_policy`; supplier toggle `show_contact_public`; entitlement `public_contact` | Contact shown only for verified suppliers who opt in, only to signed-in buyers, never on confidential listings | As stated |
| D-09 | **Buyer access to sensitive inventory** | `buyer.restricted_access_policy`; entitlement `restricted_inventory_access`; per-company grant by staff with `verification.approve` | Verified buyers granted individually | No buyer sees verified-buyer-only listings without an explicit grant |
| D-10 | **Warranty and return terms** | `legal.warranty_terms`; CMS page drafts | Quality and warranty stay between buyer and supplier | Shown at checkout and on the raise-dispute page as provisional text |
| D-11 | **Supplier suspension policy** (grounds, notice, automatic triggers) | `verification.suspend_on_expired_docs` (off); manual suspend/reactivate in Admin → Companies | Manual only, with a mandatory reason (audited) | Expired certificates are flagged and suppliers notified; no automatic suspension |
| D-12 | **RFQ distribution criteria** | `rfq.distribution_criteria`, `rfq.matching_weights`, `rfq.min_match_score`, `rfq.max_auto_invites`, `rfq.auto_distribute` | Admin reviews and distributes manually; automatic matching suggests candidates (score ≥ 20) | Only verified suppliers with premium leads are eligible |
| D-13 | **Escrow / payment partner** | `payments.escrow_provider` = `none`; `payments.bank_instructions` | Bank transfer with manual reconciliation; sandbox gateway outside production only | **No escrow and no held funds.** Checkout, billing and How it works state that escrow arrives only with a licensed partner |
| D-14 | **Commercial settlement currency rules** (FX, which currency is binding) | `platform.settlement_currency_rules`; `currencies` table | Invoice in the currency of the listing or quotation; no FX conversion | Multi-currency amounts are not converted or aggregated |
| D-15 | **Cancellation and refund rules** | `billing.refund_policy`; CMS page `refund-policy` (draft) | Case by case, handled by finance | Cancellation allowed only in early order states (`OrderService::TRANSITIONS`); refunds recorded manually |
| D-16 | Sanctions screening provider (UN, OFAC, PEP, AML) | `sanctions.provider` = `none` | Manual screening records with source and reference | Nothing is shown as "cleared" without a recorded check |
| D-17 | SMS / WhatsApp provider | `notifications.sms_provider`, `notifications.whatsapp_provider` = `none` | Not implemented | In-app and email only |
| D-18 | Email delivery go-live (SMTP account, sender domain, SPF/DKIM) | `.env` `email.*`; `email.delivery_enabled` = 0 | Emails queued in the outbox with status `disabled` | Password-reset links cannot be delivered until enabled (D-19) |
| D-19 | MFA policy (optional or mandatory, for which roles) | Shield `Auth::$actions['login']` (Email-2FA available) | Off | Depends on D-18 |
| D-20 | Risk scoring weights and LOW/MEDIUM/HIGH thresholds | `risk.weights`, `risk.thresholds` | Equal weights; each factor scored 1 (low) to 5 (high); weighted average ≤ 2.0 = LOW, ≤ 3.5 = MEDIUM, otherwise HIGH | Officers enter factor scores with evidence notes |
| D-21 | Verification stages for suppliers and buyers | `verification.supplier_stages`, `verification.buyer_stages` | Supplier: 10 stages; buyer: 5 | Applied to new applications |
| D-22 | Listing review policy (review all, or only unverified suppliers) | `catalog.review_policy` | `all` (every listing reviewed before publishing) | Procurement managers approve listings |
| D-23 | Guests see prices? | `catalog.guest_can_see_prices` | Off | Guests see "Sign in for price" |
| D-24 | What "direct enquiries" means for the free plan | entitlement `direct_enquiries` | On for both plans (TEST 2 requires enquiries to unverified suppliers) | Turning it off makes buyers use RFQs for that plan |
| D-25 | Brand colours, logo and final branding | `branding.primary_color`, `branding.accent_color` | Navy #0B1F3A, blue #1F5FBF (provisional) | Provisional styling |
| D-26 | Legal pages (terms, privacy, refund, disclaimer) and cross-border trade and privacy review (GDPR, DPDP Act 2023, export controls) | Admin → CMS | Drafts marked pending | **Legal review required before launch**; compliance is not claimed |
| D-27 | Search engine indexing go-live | `seo.indexing_enabled` | Off (`noindex` and robots disallow) | Site is not indexed |
| D-28 | Blocked registration countries (sanctions or export policy) | `platform.blocked_countries` | Empty | All countries can register |
| D-29 | Invoice payment terms | `billing.invoice_due_days` | 15 days | Shown on invoices |

## How to approve

Admin → Platform settings → edit the value → set *Approval status* to **Approved** → Save. The change is audited with the old and new values.
