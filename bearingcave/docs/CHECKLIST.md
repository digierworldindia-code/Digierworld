# Completed and pending checklist

Status as of this delivery. "Done" means the feature is implemented, wired into the UI and exercised by an automated test, the E2E script or the authenticated crawl (see `TESTING_REPORT.md`).

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
| 9 Testing and deployment | Done | 22 PHPUnit tests, E2E (110 checks), crawl (451 pages), install and deployment docs |

## Deliverables (prompt §34)

| # | Deliverable | Location |
|---|---|---|
| 1–7 | Source, frontend, dashboards, migrations, seeders | `bearingcave/` |
| 8 | Sample demo records, clearly marked | `DemoSeeder` (`[SAMPLE]`, `is_sample=1`, SAMPLE DATA badge) |
| 9 | Import templates | Supplier → Bulk upload → template (XLSX); Admin → Supplier master data → template |
| 10–11 | Auth and permissions, connected workflows | see `04`, `03` |
| 12–13 | Install instructions, `.env.example` | `INSTALL.md`, `.env.example` |
| 14 | Deployment | `DEPLOYMENT.md` |
| 15–17 | Admin, supplier and buyer guides | `guides/` |
| 18 | API documentation | `API.md` |
| 19 | Testing report | `TESTING_REPORT.md` |
| 20 | Traceability matrix | `01_REQUIREMENTS_TRACEABILITY.md` |
| 21 | Pending integrations | `07_PENDING_INTEGRATIONS.md` |
| 22 | Pending business decisions | `06_CLIENT_DECISIONS_REGISTER.md` |
| 23 | ZIP package | `build/bearingcave-source.zip`, built by `tools/package.sh` (excludes `vendor/`, `.env`, uploads, logs, demo credentials) |

## Remaining work (exact)

### Blocked on the client

- [ ] Share the Google Sheet (`.xlsx`). Reconcile the traceability matrix and confirm the 86 Supplier Master headings (D-01, D-02).
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
