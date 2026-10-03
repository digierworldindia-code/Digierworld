# QMS – Paperless Quality Inspection System

A CodeIgniter 4 / MySQL 8 web application that replaces the paper inspection sheets of a manufacturing plant.
Operators fill Setup Change Approval, Product Parameter Inspection and In-Process Inspection reports on a
tablet. Readings are checked against LSL/USL instantly, every report is stored in MySQL first and copied to Google
Sheets, approvals are e-signed, reports print as A4 sheets that look like the paper ones, and every action is audited.

**Status: Phase 1 – architecture for approval.** No application code has been generated yet. After sign-off,
the modules in [05 – Modules](docs/architecture/05-modules.md) are built one by one (M0 → M13), each with
migration, model, service, validation, controller, routes, views, JavaScript, authorization and tests.

## Architecture deliverables

| # | Document | Content |
|---|---|---|
| 1 | [Project architecture](docs/architecture/01-project-architecture.md) | Stack, key decisions, layers, submit sequence, transactions, time zones, deployment, NFRs |
| 2 | [Folder structure](docs/architecture/02-folder-structure.md) | Complete CI4 project tree and conventions |
| 3 | [ER diagram](docs/architecture/03-er-diagram.md) | Mermaid ER diagrams, inspection aggregate, paper-field → table mapping |
| 4 | [MySQL schema](docs/architecture/04-database-schema.md) | Table catalogue, design decisions, PASS/FAIL rules, numbering, triggers, indexes, volumes |
| 5 | [Module list](docs/architecture/05-modules.md) | 14 modules, build order, per-module deliverables |
| 6 | [Roles & permissions](docs/architecture/06-roles-permissions.md) | 6 roles × 35 permissions matrix, data scope, segregation of duties |
| 7 | [Workflow](docs/architecture/07-workflow.md) | State machine, transitions, IPR rounds, e-signatures, Sheets events |
| 8 | [Google Sheets integration](docs/architecture/08-google-sheets-integration.md) | Outbox, worker, back-off, de-duplication, capacity, service-account security |
| 9 | [Security architecture](docs/architecture/09-security-architecture.md) | Threat model, OWASP mapping, auth, CSRF, headers, uploads, DB/server hardening, backups |
| 10 | [UI page list](docs/architecture/10-ui-pages.md) | Design tokens, components, 46 pages, entry flow, offline UX, A4 print layouts |

## Database artefacts (reviewable now)

| File | |
|---|---|
| [`database/schema/qms_schema.sql`](database/schema/qms_schema.sql) | Complete MySQL 8 DDL: 34 tables, 56 FKs, 36 CHECKs, 130 indexes, 27 integrity triggers |
| [`database/schema/qms_reference_data.sql`](database/schema/qms_reference_data.sql) | Roles, permissions, matrix, report types, shifts, units, methods, gauge types, settings |
| [`database/samples/qms_demo_templates.sql`](database/samples/qms_demo_templates.sql) | The three paper formats modelled as templates (placeholder specs, demo only) |
| [`database/security/qms_db_users.sql`](database/security/qms_db_users.sql) | Least-privilege MySQL accounts (`qms_app`, `qms_migrator`, `qms_backup`) |
| [`database/verify/verify_schema.php`](database/verify/verify_schema.php) | Loads all of the above into a scratch DB and checks 112 integrity rules |

```bash
# NON-production MySQL 8 server only (creates and drops a scratch database and test accounts)
QMS_VERIFY_USER=root QMS_VERIFY_PASSWORD=... php database/verify/verify_schema.php --with-grants
# → 112 passed, 0 failed   (MySQL 8.0.46, binary log on)
```

## Decisions to confirm before code generation

1. **Repository visibility.** This repository is public. No secret will ever be committed, but a private repository is recommended for the production QMS source.
2. **Google Sheets detail level.** Default `ALL` copies every reading. At roughly 15 machines that fills a detail spreadsheet every 3–4 months (Google's 10 M cell limit), so the admin rotates it when the in-app capacity alert appears. `OOS_ONLY` copies only failed readings and never needs rotation.
3. **Workflow defaults.** SCA and PPI use Production → Quality → QA. IPR skips Production Verification. Approvals need password re-entry, and one person cannot sign two stages of the same report. All of this is configurable; confirm it suits night-shift staffing.
4. **Hosting.** On-premises LAN server (HTTPS with an internal CA or Let's Encrypt DNS-01) or a cloud VPS reachable over VPN.
5. **Optional extras, not in the current scope:** TOTP two-factor login for admin roles, e-mail notifications, Hindi UI strings.

## Repository notes

- The website's GitHub Pages workflow excludes `qms/`, so none of this is published with the marketing site.
- Production web root is `qms/public/` only. Secrets come from the server environment (see `.env.example` once M0 lands).
