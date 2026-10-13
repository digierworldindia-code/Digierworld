# Database guide

MySQL 8, InnoDB, `utf8mb4_unicode_ci`, strict mode. **81 tables, 146 foreign keys.** The full diagram, generated from the live schema, is in `ER_DIAGRAM.md`.

## Golden rules (data preservation)

1. **Never** run `migrate:refresh`, `migrate:rollback` or `db:seed DemoSeeder` against a database that holds real records.
2. Take a backup before every migration (see the commands below).
3. Schema changes are **additive, incremental migrations**: new nullable or defaulted columns, new tables and indexes. Existing tables are never dropped or renamed, and existing columns are never removed in normal upgrades.
4. Every migration has a working `down()`. Rollback was exercised on the development database (000010/000011: rollback, then re-migrate, with sample data intact).
5. Seeders are **idempotent**. They insert missing reference rows and never overwrite values an administrator changed. `DemoSeeder` refuses to run in production.
6. PHPUnit uses the separate database `bearingcave_test`, which is wiped on every run. Never point `database.tests.*` at a real database.

## Migrations

| Migration | Purpose | Type |
|---|---|---|
| Shield (CodeIgniter\Shield) | `users`, `auth_*` | framework |
| 000001 CreateReferenceTables | countries, currencies | base |
| 000002 CreateCompanyTables | companies, members and permissions, directors, profiles, documents, country rules, supplier master fields and attributes | base |
| 000003 CreateVerificationTables | applications, stages, events, KYC, compliance, financial, risk, quality, site audits, sanctions, AVL, performance | base |
| 000004 CreateMembershipBillingTables | plans, entitlements, subscriptions, invoices, payments, webhook events | base |
| 000005 CreateCatalogTables | categories, brands, products (CHECK `chk_products_stock`, FULLTEXT), part numbers, cross refs, fitments, specs, images, tiers, lots, movements, imports, enquiries, saved items, search logs | base |
| 000006 CreateProcurementTables | RFQs, items, matches, quotations, carts, orders, order history | base |
| 000007 CreateServiceTables | inspections, logistics, shipments, disputes | base |
| 000008 CreatePlatformTables | settings, notifications, outbox, audit, CMS, contact leads | base |
| 000009 CreateNumberSequences | gap-free document numbers | base |
| **000010 AddSheetAlignmentColumns** | workbook alignment: `supplier_master_fields.is_active`; `product_enquiries.routing/forwarded_at/forwarded_by`; `products.clearance_*`, `pre_clearance_*`; `supplier_performance.compliance_score`; `buyer_profiles.services_bonus_score/overall_score`; `disputes.bc_intervention_eligible/inspection_request_id`; `inspection_requests.requested_at_checkout`; 2 indexes | **additive** (2026-10-13) |
| **000011 AlignDataWithClientWorkbook** | activates the 86 real Supplier List headings; deactivates (does not delete) earlier proposals; free plan `direct_enquiries` off | **data**, reversible |
| **000012 AddWorkbookScoringSettings** | adds `buyer.score_weights`, `buyer.services_bonus_per_use` (pending approval) when missing | **data**, reversible |
| **000013 AddSiteAuditOutputs** | `site_audits.audit_score`, `corrective_actions`, `recommendation` | **additive**, reversible |

All four new migrations check the **live** schema (`SHOW COLUMNS` / `SHOW INDEX`) before acting, so they are safe to re-run. CodeIgniter's `fieldExists()` caches column names per connection, and that cache gave wrong answers during combined rollback and migrate runs. Using the live schema avoids it.

## Upgrading an existing installation

```bash
cd /var/www/bearingcave
mysqldump --single-transaction --routines --no-tablespaces -u USER -p bearingcave | gzip > ~/bearingcave-$(date +%F-%H%M)-pre-upgrade.sql.gz
php spark migrate:status          # review what will run
php spark migrate --all           # applies only migrations not yet run
php spark migrate:status          # all should show a batch number
```

To **revert this upgrade only** (non-destructive for business data; new columns are removed):

```bash
php spark migrate:rollback        # rolls back the last batch
```

In the development environment, the backup taken before this work is kept as `writable/backups/*-pre-sheet-audit.sql.gz` (git-ignored).

Restore from a backup:

```bash
gunzip -c backup.sql.gz | mysql -u USER -p bearingcave
```

## Fresh installation

```bash
php spark migrate --all
php spark db:seed DatabaseSeeder          # reference data, 63 settings, plans, categories, CMS drafts, 86 supplier-master fields
php spark bearingcave:create-admin you@company.com
# optional, non-production only:
php spark db:seed DemoSeeder
```

## Key relationships

- `companies` is the tenant boundary. Every private record carries a company id; access goes through `BaseController::owned()` and `VisibilityService`.
- Products, lots and movements: stock is protected by conditional updates and a CHECK constraint, and every change is logged in `inventory_movements`.
- RFQs, matches, quotations and orders, then invoices and payments (idempotent webhooks).
- Supplier master: `supplier_master_fields` (86 active sheet headings plus any later-registered ones) and `supplier_master_attributes` (one value per company and field). Bank account values are encrypted into `kyc_records.bank_account_number_enc`; the attribute keeps only a masked value.
- Enquiry routing: `product_enquiries.routing` is `direct` or `platform`. `forwarded_at` is set when staff pass a platform enquiry to the supplier; only then does the supplier see it.
- Clearance: `products.clearance_pct` and `clearance_started_at` with `pre_clearance_unit_price` / `pre_clearance_lot_price`, so a markdown can be reversed exactly. Quantity tiers stay stored unchanged and get the same percentage at price time.

## Sensitive columns

| Column | Protection |
|---|---|
| `kyc_records.bank_account_number_enc` | CI Encryption (key in `.env`), decrypted only for `kyc.sensitive` |
| `supplier_master_attributes.value` for "Bank account" | Masked (`••••••••1234`) |
| `import_job_rows.data` for sensitive columns | Masked during validation; the encrypted value is carried forward |
| `users` passwords | Shield (bcrypt/argon) |

Back up `.env` (`encryption.key`) together with the database. Encrypted fields cannot be read without it.
