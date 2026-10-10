# Entity–relationship diagram

Generated from the live MySQL schema (`information_schema`) after running all migrations — not hand-drawn — so it matches the code. Regenerate after schema changes with `DB_PASS=… python3 tools/er_diagram.py`.

Tables: 81 · foreign keys: 146. Shield auth tables (`auth_*`) are owned by CodeIgniter Shield.

## Identity & companies

```mermaid
erDiagram
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    auth_identities {
        int id PK
        int user_id FK
        varchar type
        varchar name
        varchar secret
        varchar secret2
    }
    auth_groups_users {
        int id PK
        int user_id FK
        varchar group
        datetime created_at
    }
    auth_permissions_users {
        int id PK
        int user_id FK
        varchar permission
        datetime created_at
    }
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    company_members {
        int id PK
        int company_id FK
        int user_id FK
        enum member_role
        varchar job_title
        enum status
        datetime created_at
    }
    company_member_permissions {
        int id PK
        int company_member_id FK
        varchar permission_key
    }
    company_directors {
        int id PK
        int company_id FK
        varchar full_name
        varchar designation
        char nationality
        varchar id_document_type
    }
    buyer_profiles {
        int id PK
        int company_id FK
        varchar buyer_category
        varchar annual_purchase_volume
        text interested_categories
        char preferred_currency
    }
    supplier_profiles {
        int id PK
        int company_id FK
        enum supplier_type
        text main_categories
        text brands_handled
        varchar production_capacity
    }
    company_country_rules {
        int id PK
        int company_id FK
        char country_code FK
        enum rule
    }
    countries {
        int id PK
        char iso2 UK
        varchar name
        varchar region
        tinyint is_enabled
    }
    currencies {
        int id PK
        char code UK
        varchar name
        varchar symbol
        tinyint is_enabled
        tinyint is_base
    }
    users ||--o{ auth_groups_users : "user_id"
    users ||--o{ auth_identities : "user_id"
    users ||--o{ auth_permissions_users : "user_id"
    companies ||--o{ buyer_profiles : "company_id"
    users ||--o{ companies : "account_manager_user_id"
    countries ||--o{ companies : "country_code"
    companies ||--o{ company_country_rules : "company_id"
    countries ||--o{ company_country_rules : "country_code"
    companies ||--o{ company_directors : "company_id"
    company_members ||--o{ company_member_permissions : "company_member_id"
    companies ||--o{ company_members : "company_id"
    users ||--o{ company_members : "user_id"
    companies ||--o{ supplier_profiles : "company_id"
```

## Verification (12 modules)

```mermaid
erDiagram
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    verification_applications {
        int id PK
        int company_id FK
        enum application_type
        smallint cycle
        enum status
        varchar current_stage
        int decided_by FK
    }
    verification_stages {
        int id PK
        int application_id FK
        varchar stage_key
        smallint sort_order
        enum status
        int assigned_to FK
        int reviewed_by FK
        datetime reviewed_at
    }
    verification_events {
        int id PK
        int application_id FK
        varchar stage_key
        varchar action
        int user_id FK
        text notes
        datetime created_at
    }
    kyc_records {
        int id PK
        int company_id FK
        varchar gst_number
        varchar pan_number
        varchar cin_number
        varchar vat_number
        int reviewed_by FK
    }
    compliance_certifications {
        int id PK
        int company_id FK
        enum cert_type
        varchar cert_name
        varchar cert_number
        varchar issuer
        int document_id FK
        int reviewed_by FK
    }
    financial_assessments {
        int id PK
        int company_id FK
        varchar fiscal_year
        char currency
        decimal annual_turnover
        decimal net_profit
        int reviewed_by FK
    }
    risk_assessments {
        int id PK
        int company_id FK
        int application_id FK
        tinyint financial_risk
        tinyint operational_risk
        tinyint compliance_risk
        tinyint geographic_risk
        int assessed_by FK
    }
    quality_assessments {
        int id PK
        int company_id FK
        tinyint has_inspection_system
        enum ppap
        enum apqp
        enum fmea
        int reviewed_by FK
    }
    site_audits {
        int id PK
        int company_id FK
        date scheduled_on
        date conducted_on
        int auditor_user_id FK
        varchar external_auditor
        text site_address
        int report_document_id FK
    }
    sanctions_screenings {
        int id PK
        int company_id FK
        enum subject_type
        varchar subject_name
        varchar provider
        varchar lists_checked
        int screened_by FK
    }
    approved_vendors {
        int id PK
        int company_id FK
        enum scope_type
        int scope_id
        enum status
        date valid_until
        int approved_by FK
    }
    supplier_performance {
        int id PK
        int company_id FK
        date period_start
        date period_end
        int orders_count
        decimal on_time_delivery_pct
    }
    documents {
        int id PK
        char uuid UK
        int company_id FK
        int uploaded_by FK
        varchar entity_type
        int entity_id
        varchar doc_type
        varchar title
        int previous_version_id FK
        int reviewed_by FK
    }
    supplier_master_fields {
        int id PK
        varchar source_heading
        varchar field_key UK
        varchar field_group
        varchar target
        enum data_type
    }
    supplier_master_attributes {
        int id PK
        int company_id FK
        varchar field_key
        text value
        int import_job_id
        datetime created_at
    }
    categories {
        int id PK
        int parent_id FK
        varchar name
        varchar slug UK
        text description
        varchar icon
        int sort_order
    }
    brands {
        int id PK
        varchar name
        varchar slug UK
        char country_code
        enum brand_type
        varchar logo_path
    }
    products {
        int id PK
        char uuid UK
        int company_id FK
        int category_id FK
        int brand_id FK
        varchar sku
        varchar name
        varchar slug UK
        varchar part_number
        varchar part_number_norm
        int approved_by FK
    }
    users ||--o{ approved_vendors : "approved_by"
    companies ||--o{ approved_vendors : "company_id"
    users ||--o{ companies : "account_manager_user_id"
    companies ||--o{ compliance_certifications : "company_id"
    documents ||--o{ compliance_certifications : "document_id"
    users ||--o{ compliance_certifications : "reviewed_by"
    companies ||--o{ documents : "company_id"
    users ||--o{ documents : "reviewed_by"
    users ||--o{ documents : "uploaded_by"
    companies ||--o{ financial_assessments : "company_id"
    users ||--o{ financial_assessments : "reviewed_by"
    companies ||--o{ kyc_records : "company_id"
    users ||--o{ kyc_records : "reviewed_by"
    users ||--o{ products : "approved_by"
    brands ||--o{ products : "brand_id"
    categories ||--o{ products : "category_id"
    companies ||--o{ products : "company_id"
    companies ||--o{ quality_assessments : "company_id"
    users ||--o{ quality_assessments : "reviewed_by"
    verification_applications ||--o{ risk_assessments : "application_id"
    users ||--o{ risk_assessments : "assessed_by"
    companies ||--o{ risk_assessments : "company_id"
    companies ||--o{ sanctions_screenings : "company_id"
    users ||--o{ sanctions_screenings : "screened_by"
    users ||--o{ site_audits : "auditor_user_id"
    companies ||--o{ site_audits : "company_id"
    documents ||--o{ site_audits : "report_document_id"
    companies ||--o{ supplier_master_attributes : "company_id"
    companies ||--o{ supplier_performance : "company_id"
    companies ||--o{ verification_applications : "company_id"
    users ||--o{ verification_applications : "decided_by"
    verification_applications ||--o{ verification_events : "application_id"
    users ||--o{ verification_events : "user_id"
    verification_applications ||--o{ verification_stages : "application_id"
    users ||--o{ verification_stages : "assigned_to"
    users ||--o{ verification_stages : "reviewed_by"
```

## Membership & billing

```mermaid
erDiagram
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    membership_plans {
        int id PK
        varchar code UK
        varchar name
        enum audience
        decimal annual_fee
        char currency
    }
    plan_entitlements {
        int id PK
        int plan_id FK
        varchar feature_key
        tinyint is_enabled
        int limit_value
        datetime created_at
    }
    subscriptions {
        int id PK
        int company_id FK
        int plan_id FK
        enum status
        date starts_on
        date ends_on
        int invoice_id
        int renewal_of_id FK
        int created_by FK
    }
    invoices {
        int id PK
        varchar invoice_number UK
        int company_id FK
        enum invoice_type
        varchar reference_type
        int reference_id
        char currency
        int created_by FK
    }
    payments {
        int id PK
        int company_id FK
        int invoice_id FK
        int order_id
        varchar provider
        varchar provider_reference
        varchar idempotency_key UK
        varchar method
        int recorded_by FK
    }
    payment_webhook_events {
        int id PK
        varchar provider
        varchar event_id
        varchar event_type
        json payload
    }
    orders {
        int id PK
        varchar order_number UK
        int buyer_company_id FK
        int supplier_company_id FK
        int quotation_id FK
        int rfq_id FK
        enum source
        enum status
        enum payment_status
        enum shipment_status
        int created_by FK
    }
    users ||--o{ companies : "account_manager_user_id"
    companies ||--o{ invoices : "company_id"
    users ||--o{ invoices : "created_by"
    companies ||--o{ orders : "buyer_company_id"
    users ||--o{ orders : "created_by"
    companies ||--o{ orders : "supplier_company_id"
    companies ||--o{ payments : "company_id"
    invoices ||--o{ payments : "invoice_id"
    users ||--o{ payments : "recorded_by"
    membership_plans ||--o{ plan_entitlements : "plan_id"
    companies ||--o{ subscriptions : "company_id"
    users ||--o{ subscriptions : "created_by"
    membership_plans ||--o{ subscriptions : "plan_id"
```

## Catalog & inventory

```mermaid
erDiagram
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    categories {
        int id PK
        int parent_id FK
        varchar name
        varchar slug UK
        text description
        varchar icon
        int sort_order
    }
    brands {
        int id PK
        varchar name
        varchar slug UK
        char country_code
        enum brand_type
        varchar logo_path
    }
    products {
        int id PK
        char uuid UK
        int company_id FK
        int category_id FK
        int brand_id FK
        varchar sku
        varchar name
        varchar slug UK
        varchar part_number
        varchar part_number_norm
        int approved_by FK
    }
    part_numbers {
        int id PK
        int product_id FK
        enum number_type
        varchar part_number
        varchar part_number_norm
        varchar brand_name
    }
    cross_references {
        int id PK
        int product_id FK
        varchar ref_brand
        varchar ref_part_number
        varchar ref_part_number_norm
        enum relation
        int verified_by FK
    }
    fitments {
        int id PK
        int product_id FK
        varchar make
        varchar model
        varchar variant
        varchar engine
    }
    product_specifications {
        int id PK
        int product_id FK
        varchar spec_name
        varchar spec_value
        varchar unit
        int sort_order
    }
    product_images {
        int id PK
        int product_id FK
        varchar path
        varchar alt_text
        int sort_order
        tinyint is_primary
    }
    pricing_tiers {
        int id PK
        int product_id FK
        int min_qty
        int max_qty
        decimal unit_price
        datetime created_at
    }
    product_country_rules {
        int id PK
        int product_id FK
        char country_code FK
        enum rule
    }
    inventory_lots {
        int id PK
        int product_id FK
        varchar lot_code
        int quantity
        date received_on
        date manufactured_on
    }
    inventory_movements {
        int id PK
        int product_id FK
        int lot_id FK
        enum movement_type
        int quantity
        int on_hand_after
        int reserved_after
        int user_id FK
    }
    import_jobs {
        int id PK
        char uuid UK
        enum import_type
        int company_id FK
        int created_by FK
        varchar original_name
        varchar stored_path
        enum status
    }
    import_job_rows {
        int id PK
        int import_job_id FK
        int row_number
        json data
        json errors
        enum status
    }
    saved_products {
        int id PK
        int user_id FK
        int product_id FK
        datetime created_at
    }
    product_enquiries {
        int id PK
        int product_id FK
        int buyer_company_id FK
        int user_id FK
        int quantity
        text message
        enum status
        text response
        int responded_by FK
    }
    search_logs {
        int id PK
        int user_id
        int company_id
        varchar query
        json filters
    }
    users ||--o{ companies : "account_manager_user_id"
    products ||--o{ cross_references : "product_id"
    users ||--o{ cross_references : "verified_by"
    products ||--o{ fitments : "product_id"
    import_jobs ||--o{ import_job_rows : "import_job_id"
    companies ||--o{ import_jobs : "company_id"
    users ||--o{ import_jobs : "created_by"
    products ||--o{ inventory_lots : "product_id"
    inventory_lots ||--o{ inventory_movements : "lot_id"
    products ||--o{ inventory_movements : "product_id"
    users ||--o{ inventory_movements : "user_id"
    products ||--o{ part_numbers : "product_id"
    products ||--o{ pricing_tiers : "product_id"
    products ||--o{ product_country_rules : "product_id"
    companies ||--o{ product_enquiries : "buyer_company_id"
    products ||--o{ product_enquiries : "product_id"
    users ||--o{ product_enquiries : "responded_by"
    users ||--o{ product_enquiries : "user_id"
    products ||--o{ product_images : "product_id"
    products ||--o{ product_specifications : "product_id"
    users ||--o{ products : "approved_by"
    brands ||--o{ products : "brand_id"
    categories ||--o{ products : "category_id"
    companies ||--o{ products : "company_id"
    products ||--o{ saved_products : "product_id"
    users ||--o{ saved_products : "user_id"
```

## Procurement & orders

```mermaid
erDiagram
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    products {
        int id PK
        char uuid UK
        int company_id FK
        int category_id FK
        int brand_id FK
        varchar sku
        varchar name
        varchar slug UK
        varchar part_number
        varchar part_number_norm
        int approved_by FK
    }
    rfqs {
        int id PK
        varchar rfq_number UK
        int buyer_company_id FK
        int created_by FK
        varchar title
        char destination_country FK
        varchar delivery_terms
        text delivery_requirements
        date required_by
        int reviewed_by FK
    }
    rfq_items {
        int id PK
        int rfq_id FK
        int product_id FK
        int category_id FK
        int brand_id FK
        varchar brand_text
        varchar part_number
        varchar part_number_norm
        varchar description
    }
    rfq_matches {
        int id PK
        int rfq_id FK
        int supplier_company_id FK
        decimal match_score
        json match_reasons
        json matched_product_ids
        enum status
        int invited_by FK
    }
    quotations {
        int id PK
        varchar quotation_number UK
        int rfq_id FK
        int supplier_company_id FK
        int rfq_match_id FK
        smallint revision
        int previous_id FK
        tinyint is_current
        enum status
        char currency
        int submitted_by FK
    }
    quotation_items {
        int id PK
        int quotation_id FK
        int rfq_item_id FK
        int product_id FK
        varchar description
        varchar part_number
        int quantity
        decimal unit_price
    }
    quotation_messages {
        int id PK
        int quotation_id FK
        enum sender_side
        int user_id FK
        text message
        decimal proposed_total
        datetime created_at
    }
    carts {
        int id PK
        int user_id FK
        int company_id FK
        enum status
        datetime created_at
        datetime updated_at
    }
    cart_items {
        int id PK
        int cart_id FK
        int product_id FK
        int quantity
        datetime created_at
        datetime updated_at
    }
    orders {
        int id PK
        varchar order_number UK
        int buyer_company_id FK
        int supplier_company_id FK
        int quotation_id FK
        int rfq_id FK
        enum source
        enum status
        enum payment_status
        enum shipment_status
        int created_by FK
    }
    order_items {
        int id PK
        int order_id FK
        int product_id FK
        int quotation_item_id FK
        varchar description
        varchar part_number
        int quantity
        decimal unit_price
    }
    order_status_history {
        int id PK
        int order_id FK
        varchar from_status
        varchar to_status
        int user_id FK
        text note
        datetime created_at
    }
    carts ||--o{ cart_items : "cart_id"
    products ||--o{ cart_items : "product_id"
    companies ||--o{ carts : "company_id"
    users ||--o{ carts : "user_id"
    users ||--o{ companies : "account_manager_user_id"
    orders ||--o{ order_items : "order_id"
    products ||--o{ order_items : "product_id"
    quotation_items ||--o{ order_items : "quotation_item_id"
    orders ||--o{ order_status_history : "order_id"
    users ||--o{ order_status_history : "user_id"
    companies ||--o{ orders : "buyer_company_id"
    users ||--o{ orders : "created_by"
    quotations ||--o{ orders : "quotation_id"
    rfqs ||--o{ orders : "rfq_id"
    companies ||--o{ orders : "supplier_company_id"
    users ||--o{ products : "approved_by"
    companies ||--o{ products : "company_id"
    products ||--o{ quotation_items : "product_id"
    quotations ||--o{ quotation_items : "quotation_id"
    rfq_items ||--o{ quotation_items : "rfq_item_id"
    quotations ||--o{ quotation_messages : "quotation_id"
    users ||--o{ quotation_messages : "user_id"
    rfqs ||--o{ quotations : "rfq_id"
    rfq_matches ||--o{ quotations : "rfq_match_id"
    users ||--o{ quotations : "submitted_by"
    companies ||--o{ quotations : "supplier_company_id"
    products ||--o{ rfq_items : "product_id"
    rfqs ||--o{ rfq_items : "rfq_id"
    users ||--o{ rfq_matches : "invited_by"
    rfqs ||--o{ rfq_matches : "rfq_id"
    companies ||--o{ rfq_matches : "supplier_company_id"
    companies ||--o{ rfqs : "buyer_company_id"
    users ||--o{ rfqs : "created_by"
    users ||--o{ rfqs : "reviewed_by"
```

## Services, disputes & platform

```mermaid
erDiagram
    companies {
        int id PK
        char uuid UK
        enum company_type
        varchar legal_name
        varchar trade_name
        varchar public_alias
        varchar slug UK
        char country_code FK
        int account_manager_user_id FK
    }
    users {
        int id PK
        varchar username UK
        varchar status
        varchar status_message
        tinyint active
        datetime last_active
    }
    orders {
        int id PK
        varchar order_number UK
        int buyer_company_id FK
        int supplier_company_id FK
        int quotation_id FK
        int rfq_id FK
        enum source
        enum status
        enum payment_status
        enum shipment_status
        int created_by FK
    }
    inspection_requests {
        int id PK
        varchar request_number UK
        int company_id FK
        int requested_by FK
        int order_id FK
        int product_id FK
        enum inspection_type
        json scope
        char location_country
        varchar location_city
        int inspector_user_id FK
        int invoice_id FK
    }
    inspection_reports {
        int id PK
        int inspection_request_id FK
        enum result
        date inspected_on
        int quantity_expected
        int quantity_verified
        int document_id FK
        int uploaded_by FK
    }
    logistics_requests {
        int id PK
        varchar request_number UK
        int company_id FK
        int requested_by FK
        int order_id FK
        enum mode
        varchar consolidation_ref
        char pickup_country
        varchar pickup_city
        int assigned_to FK
        int invoice_id FK
    }
    shipments {
        int id PK
        int logistics_request_id FK
        int order_id FK
        varchar carrier
        varchar tracking_number
        enum transport_mode
        enum status
        int created_by FK
    }
    shipment_events {
        int id PK
        int shipment_id FK
        varchar status
        varchar location
        varchar description
        datetime event_at
        int created_by FK
    }
    disputes {
        int id PK
        varchar dispute_number UK
        int order_id FK
        int raised_by_company_id FK
        int against_company_id FK
        int raised_by FK
        enum category
        varchar subject
        text description
        text desired_resolution
        int assigned_to FK
    }
    dispute_messages {
        int id PK
        int dispute_id FK
        int user_id FK
        enum sender_side
        text message
        tinyint is_internal
        datetime created_at
    }
    notifications {
        int id PK
        int user_id FK
        varchar type
        varchar title
        text body
        varchar link
    }
    email_outbox {
        int id PK
        int user_id FK
        varchar to_email
        varchar subject
        mediumtext body_html
        enum status
    }
    audit_logs {
        int id PK
        int user_id
        int company_id
        varchar event
        enum severity
    }
    platform_settings {
        int id PK
        varchar setting_key UK
        text setting_value
        enum value_type
        varchar setting_group
        varchar label
    }
    cms_pages {
        int id PK
        varchar slug UK
        varchar title
        mediumtext body
        varchar meta_title
        varchar meta_description
    }
    contact_leads {
        int id PK
        varchar name
        varchar company_name
        varchar email
        varchar phone
    }
    number_sequences {
        varchar seq_key PK
        smallint seq_year PK
        int counter
    }
    users ||--o{ companies : "account_manager_user_id"
    disputes ||--o{ dispute_messages : "dispute_id"
    users ||--o{ dispute_messages : "user_id"
    companies ||--o{ disputes : "against_company_id"
    users ||--o{ disputes : "assigned_to"
    orders ||--o{ disputes : "order_id"
    users ||--o{ disputes : "raised_by"
    companies ||--o{ disputes : "raised_by_company_id"
    users ||--o{ email_outbox : "user_id"
    inspection_requests ||--o{ inspection_reports : "inspection_request_id"
    users ||--o{ inspection_reports : "uploaded_by"
    companies ||--o{ inspection_requests : "company_id"
    users ||--o{ inspection_requests : "inspector_user_id"
    orders ||--o{ inspection_requests : "order_id"
    users ||--o{ inspection_requests : "requested_by"
    users ||--o{ logistics_requests : "assigned_to"
    companies ||--o{ logistics_requests : "company_id"
    orders ||--o{ logistics_requests : "order_id"
    users ||--o{ logistics_requests : "requested_by"
    users ||--o{ notifications : "user_id"
    companies ||--o{ orders : "buyer_company_id"
    users ||--o{ orders : "created_by"
    companies ||--o{ orders : "supplier_company_id"
    users ||--o{ shipment_events : "created_by"
    shipments ||--o{ shipment_events : "shipment_id"
    users ||--o{ shipments : "created_by"
    logistics_requests ||--o{ shipments : "logistics_request_id"
    orders ||--o{ shipments : "order_id"
```

## Critical relationships

- **companies** is the tenant boundary. Every buyer/supplier record (products, RFQs, quotations, orders, documents, verification) carries a `company_id` / `*_company_id`; controllers load private records through `BaseController::owned()` which 404s and writes a security audit event on mismatch.
- **company_members** links Shield `users` to companies with a member role (owner/admin/member) and **company_member_permissions** grants employees granular rights (`App\Libraries\CompanyPermissions`).
- **products → inventory_lots → inventory_movements**: stock is received in dated lots (inventory age = oldest open lot), every change writes a movement row; `products.stock_on_hand/stock_reserved` are denormalised counters protected by CHECK constraint `chk_products_stock` and conditional UPDATEs (no overselling).
- **part_numbers / cross_references / fitments** hold normalised part numbers, supplier-declared cross references (`relation` distinguishes declared vs verified interchange) and vehicle applications.
- **product_country_rules / company_country_rules** implement allow/deny country visibility; `VisibilityService` is the single enforcement point used by web, search, API, RFQ matching, exports and sitemap.
- **rfqs → rfq_items → rfq_matches → quotations → quotation_items**: matches store score + reasons; quotations are per supplier with revision numbers; acceptance creates an **orders** row with **order_items**.
- **orders → invoices → payments**: proforma invoices and payments are separate; **payment_webhook_events** enforces idempotency (unique provider + event id).
- **membership_plans → plan_entitlements**, **subscriptions** (date-ranged) determine features via `EntitlementService`; verification status lives on `companies`, independent of payment.
- **verification_applications → verification_stages → verification_events**: one application per company, one row per configured stage, append-only event history.
- **supplier_master_fields / supplier_master_attributes**: the 86 Supplier List headings are field definitions (EAV for headings without a native column); headings that map to native tables (KYC, certifications, risk, etc.) are written there.
