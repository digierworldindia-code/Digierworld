# Completed and pending checklist

Status as of this delivery. "Done" means the feature is implemented, wired into the UI and exercised by an automated test, the E2E script or the authenticated crawl (see `TEST_REPORT.md`).

## Phases

| Phase | Status | Notes |
|---|---|---|
| 1 Analysis and architecture | Done, with limits | Google Sheet and competitor sites were inaccessible (see `01`, `02`). Traceability, ER diagram, permissions, sitemap, workflows and decisions register are delivered. |
| 2 Foundation | Done | CI4.7, Shield, 9 migrations (81 tables, 146 FKs), seeders, design system, layouts |
| 3 Public website | Done | Home, marketplace, product, compare, suppliers, membership, 7 content pages, CMS pages, contact, registration, SEO |
| 4 Users and verification | Done | Companies, employees, 12 verification modules, documents, approval workflow, AVL, membership and billing |
| 5 Inventory marketplace | Done | Categories, brands, search, product pages, lots and movements, pricing tiers, country rules, bulk import |
| 6 Procurement | Done | RFQ, matching, distribution, quotations and revisions, comparison, cart, orders |
| 7 Additional services | Done, providers pending | Inspection, logistics, disputes, payment abstraction. Escrow provider not selected |
| 8 Enterprise admin | Done | 32 admin modules, reports with CSV/XLSX export, settings, CMS, SEO, outbox, audit |
| 9 Testing and deployment | Done | 31 PHPUnit tests, E2E (133 checks), authenticated crawl, install and deployment docs |
| 10 Workbook audit and gap closure (2026-10-13) | Done | 13 gaps closed; see `DEVELOPMENT_PROGRESS.md` |
| 11 Self-contained package and clean-install test (2026-10-13) | Done | `vendor/` included; frontend assets fixed; Apache + MySQL clean install verified |

## Deliverables (prompt §34)

| # | Deliverable | Location |
|---|---|---|
| 1–7 | Source, frontend, dashboards, migrations, seeders | `bearingcave/` |
| 8 | Sample demo records, clearly marked | `DemoSeeder` (`[SAMPLE]`, `is_sample=1`, SAMPLE DATA badge) |
| 9 | Import templates | Supplier → Bulk upload → template (XLSX); Admin → Supplier master data → template |
| 10–11 | Auth and permissions, connected workflows | see `04`, `03` |
| 12–13 | Install instructions, `.env.example` | `INSTALLATION.md`, `.env.example` |
| 14 | Deployment | `DEPLOYMENT.md` |
| 15–17 | Admin, supplier and buyer guides | `ADMIN_MANUAL.md`, `USER_MANUAL.md` |
| 18 | API documentation | `API.md` |
| 19 | Testing report | `TEST_REPORT.md` |
| 20 | Traceability matrix | `01_REQUIREMENTS_TRACEABILITY.md` |
| 21 | Pending integrations | `07_PENDING_INTEGRATIONS.md` |
| 22 | Pending business decisions | `06_CLIENT_DECISIONS_REGISTER.md` |
| 23 | ZIP package | `BEARINGCAVE_COMPLETE_WITH_VENDOR_AND_ALL_DEPENDENCIES.zip`, built by `tools/package_complete.sh`: source **plus production `vendor/`** and frontend libraries; never includes `.env`, uploads, logs, backups or demo credentials |

## Remaining work (exact)

### Blocked on the client

- [x] ~~Share the workbook; reconcile requirements; confirm the 86 Supplier Master headings (D-01, D-02).~~ Done 2026-10-13.
- [ ] Confirm the workbook interpretations D-24 and D-30 to D-32 and the client-score weights.
- [ ] Decide D-03 to D-29 in the decisions register, at minimum: tax on fees, logistics base, escrow partner, refund/warranty terms, legal pages.
- [ ] Final branding: logo and colours.

### Blocked on a provider or account

- [ ] SMTP account and DNS (SPF, DKIM, DMARC). Then enable email delivery and, optionally, Email-2FA.
- [ ] Licensed escrow/payment gateway. Implement its `PaymentGatewayInterface` adapter.
- [ ] Sanctions/PEP screening provider adapter.
- [ ] SMS/WhatsApp provider (optional).
- [ ] Production hosting, HTTPS, cron, backups (`DEPLOYMENT.md`).

### Engineering follow-ups (not started; recommended before launch)

- [ ] End-to-end assertion tests for disputes and supplier-master import. Today they are covered only by the crawl.
- [ ] Cross-browser runs (Firefox, WebKit) and an automated accessibility audit (axe).
- [ ] Load test of search and checkout. Add query caching if needed; no application cache exists yet.
- [ ] External penetration test.
- [ ] Optional: TOTP MFA, antivirus scanning of uploads, object storage for multi-server deployments.
- [ ] Move BearingCave to its own repository, or restrict the root GitHub Pages workflow, before merging to `main` (see `DEPLOYMENT.md`).
