# Brand update note — COLIFEES → POLYFIX MATTRESS

Written for the next developer who opens this repository and wonders why some
things say POLYFIX and some things still say `colifees`.

**Date:** 2026-09-23
**Approach:** audit → identify → correct → test. Nothing was rebuilt, reset,
recreated or dropped. No table was dropped, no schema recreated, no production
record deleted, and no row count fell.

---

## 1. Old branding

| | |
|---|---|
| Company name | COLIFEES |
| Short name | COLIFEES (no separate short form) |
| Wordmark | `COLI` + `FEES` |
| Site URL | `https://colifees.com` |
| Console URL | `https://app.colifees.com` |
| Console name | "COLIFEES Control" |
| Location | not previously recorded as a structured value |

## 2. New branding

| | |
|---|---|
| Company name | **POLYFIX MATTRESS** |
| Short name | **POLYFIX** |
| Legal name | POLYFIX MATTRESS |
| Wordmark | `POLY` + `FIX` |
| Site URL | `https://polyfixmattress.com` |
| Console URL | `https://app.polyfixmattress.com` |
| Console name | "POLYFIX MATTRESS Control" |

## 3. Location

**Manesar, Noranpur Chowk, Haryana, India**
(locality Manesar, region Haryana, country India / `IN`)

This is the **company's own business location only**. It was written to:

- `BRAND.location` in `packages/brand/src/index.ts`
- the `company.address` / registered-address rows in `system_settings`
- the schema.org `Organization.address` (`PostalAddress`) block on the public site

It was **not** written over any customer address, dealer address, delivery
address, historical transaction address, or the Jaipur plant's address in
`warehouses`. The plant is still in Sitapura, Jaipur — the head-office rename
does not move a factory.

---

## 4. Where brand configuration lives

`packages/brand/src/index.ts` — a new, dependency-free package exporting a
single `BRAND` object. It is the only place the company's name, short name,
wordmark split and location are written down. Everything else derives from it.

There is deliberately **no** second constant holding the same value: no
`COMPANY_NAME`, no `SITE_NAME`, no `APP_NAME`. `apps/web/src/lib/site.ts` reads
`BRAND` and lets environment variables override the per-deployment parts
(`NEXT_PUBLIC_SITE_NAME`, `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_APP_URL`).

Runtime-editable values an administrator can change without a deploy — public
phone number, support e-mail, displayed postal address, social links — stay in
the existing `system_settings` table. No new settings mechanism was introduced.

Full detail, including the renaming checklist: **`docs/11-brand-configuration.md`**.

---

## 5. Did the database change?

**Yes — data only. The schema did not change.** No `DROP`, no `DELETE`, no
`TRUNCATE`, no column added or removed on any business table, no row created or
destroyed. Only text inside existing rows was rewritten.

### Migrations created

| Migration | What it does |
|---|---|
| `packages/database/prisma/migrations/20260923090000_0006_brand_rename_polyfix/migration.sql` | Rewrites brand presentation text in `system_settings`, `products`, `seo_metadata`, `website_pages`, `website_products`, `faqs`. 60 values changed. |
| `packages/database/prisma/migrations/20260923120000_0007_brand_rename_residuals/migration.sql` | The three residual classes verification caught afterwards: lower-case brand tokens in `seo_metadata.keywords` (3 rows), stale renamed image paths in `website_pages.hero.image.src` (2 rows), and `warehouses.name` (1 row). 6 values changed. |

Both are reversible. Every overwritten value is copied into a
`brand_rename_backup` table **before** the update, and each has an executable
down path:

- `scripts/sql/rollback-0006-brand-rename.sql`
- `scripts/sql/rollback-0007-brand-rename-residuals.sql`

Run them newest-first. Both start with `SET app.dealer_id = 'ALL'` — without it
the fail-closed row-level-security policy from migration `0005` silently matches
nothing and the rollback commits having changed zero rows.

A reverse find-and-replace would **not** work as a down path: after the rename
there is no way to tell which `POLYFIX` was once `COLIFEES` and which was
`COLIFEES MATTRESS`. Restoring from the captured values is exact.

### Historical and personal data — deliberately untouched

| Table | Why |
|---|---|
| `audit_logs` (580 rows) | Append-only, hash-chained, historically accurate by definition. A claim approved under the old name *was* approved under the old name. |
| `record_versions` | Point-in-time snapshots. |
| `notifications` (10 rows carry the old name) | Messages already delivered to real people. Rewriting them would misrepresent what was sent. |
| `mattress_events`, `claim_events` | Lifecycle history. |
| `failed_login_attempts` (343 rows) | Records what somebody actually typed at a failed login. Rewriting a security log falsifies it. |
| `users.email` (108 rows on the old domain) | An e-mail address is a login credential. Changing one silently locks that person out. Account renames are a separate, communicated exercise. |
| `customers`, `sales`, `warranties`, `warranty_claims`, `replacements`, `dealers` | Customer and dealer data. None of it was ever branding. |
| `mattresses.serial_number` (157 rows, all `CLF…`) | The prefix is printed on physical labels in the field and encoded in QR codes, and is referenced by warranties, claims and dispatches. See §8. |

---

## 6. Which files changed

Roughly 100 files. Grouped:

**New**
- `packages/brand/` — the `BRAND` source of truth
- `packages/database/prisma/migrations/20260923090000_0006_brand_rename_polyfix/`
- `packages/database/prisma/migrations/20260923120000_0007_brand_rename_residuals/`
- `scripts/sql/rollback-0006-brand-rename.sql`, `scripts/sql/rollback-0007-brand-rename-residuals.sql`
- `docs/11-brand-configuration.md`, this note

**Renamed on disk** — the workspace scope `@colifees/*` → `@polyfix/*` across
every `package.json`, the project directory, four brand-named SVG assets
(`polyfix-logo.svg`, `og/polyfix-default.svg`, `hero/polyfix-hero.svg`,
`about/polyfix-factory.svg`), and the route `/why-colifees` → `/why-polyfix`.

**Public website** (`apps/web`) — `lib/site.ts`, `lib/seo.ts`,
`lib/structured-data.ts`, `next.config.ts`, `app/sitemap.ts`, the layout, all
ten page components, header, footer and verify form.

**Private console** (`apps/app`) — `components/ui.tsx` (new shared
`<Wordmark />`), `components/shell.tsx`, the layout, login, forgot-password,
reset-password, and four admin/dealer screens.

**API** (`apps/api`) — `services/auth.service.ts`, `services/verification.service.ts`,
`routes/auth`, `routes/dealer`, `routes/media.ts`, `lib/logger.ts`, the three
Fastify plugin registration names, `server.ts`.

**Packages** — `auth/src/jwt.ts` (see §7), `auth/src/totp.ts`,
`auth/src/password.ts`, `config/src/index.ts`, `validation/src/primitives.ts`,
`database/prisma/schema.prisma` (header comment only), `database/seed/`.

**Environment** — `.env.example` and `.env`: `NEXT_PUBLIC_SITE_URL`,
`NEXT_PUBLIC_SITE_NAME`, `NEXT_PUBLIC_APP_URL`, `MAIL_FROM`,
`NEXT_PUBLIC_CONSOLE_NAME`. Connection strings were **not** touched — see §8.

**Eleven SVG assets** had their embedded text updated.

Every occurrence was classified before it was changed. There was no global
find-and-replace.

---

## 7. One deployment consequence

`packages/auth/src/jwt.ts` now signs tokens with issuer `polyfix.auth` and
audience `polyfix.api`. **Access tokens issued under the old identifiers fail
verification, so the first deploy carrying this change signs every active
session out once.** Nothing is lost: refresh tokens are opaque and
database-backed, so clients refresh and continue. Expect a one-off spike of
re-authentications; warn staff and dealers before deploying.

By contrast, the TOTP issuer label (`BRAND.shortName`) is cosmetic — existing
authenticator enrolments keep working and simply show the new label on re-scan.

---

## 8. What still says `colifees`, and why

Each of these is an identifier or a historical fact, not branding.

- **`CLF` serial prefix.** Printed on physical labels already in customers'
  homes, encoded in QR codes, guarded by a CHECK constraint, referenced by
  warranties, claims, dispatches and replacements. Changing it would orphan
  every mattress manufactured to date.
- **Database name `colifees`** and the connection strings that name it.
  Renaming means coordinated downtime and new credentials in every secret store,
  to change a string no customer ever sees.
- **Roles `colifees_owner` / `colifees_app` / `colifees_readonly` /
  `colifees_backup`** and the scripts that grant to them. Renaming a PostgreSQL
  role rewrites every grant, default ACL and RLS policy naming it.
- **Functions `colifees_next_serial()`, `colifees_next_batch_code()`,
  `colifees_next_dealer_code()`, `colifees_next_claim_number()`,
  `colifees_next_dispatch_code()`, `colifees_verify_audit_chain()`** and the
  trigger `colifees_audit_seal`. Renaming means DDL churn against the audit seal
  to rename something only a DBA reads.
- **Migrations `0001`–`0005`.** Already applied. An applied migration is
  history; corrections go in a new migration, which is exactly what `0006` and
  `0007` are.
- **`brand_rename_backup` contents.** It exists to hold the pre-rename values.
- **`/why-colifees` in `apps/web/next.config.ts`.** An intentional `301` to
  `/why-polyfix` so indexed URLs and inbound links keep working.

Everything in the application's own runtime that was brand-derived but carried
no external contract *was* renamed: the Fastify plugin names, the log `service`
field (`colifees-api` → `polyfix-api`), the media download filename prefix, and
the brand token in the password-policy ban list.

---

## 9. Tests performed

| Check | Result |
|---|---|
| API test suite (`vitest`, real PostgreSQL) | **86 / 86 passed** |
| TypeScript across the whole workspace (`tsc --noEmit`) | **clean** |
| `next build` — public website | **clean**, 20 routes generated |
| `next build` — private console | **clean** |
| Rendered HTML scanned for the old name, case-insensitive, 13 public routes + 4 console routes | **0 occurrences** |
| `<title>`, `og:title`, `keywords`, canonical | correct, and a pre-existing double-brand-suffix bug in `lib/seo.ts` was fixed |
| schema.org `Organization` | `legalName` "POLYFIX MATTRESS", `addressLocality` "Manesar", `streetAddress` "Manesar, Noranpur Chowk, Haryana, India" |
| `sitemap.xml` / `robots.txt` | all `<loc>` on `https://polyfixmattress.com` |
| `/why-colifees` → `/why-polyfix` | **301** |
| Brand-named image assets reachable | all **200** |
| Audit hash chain (`colifees_verify_audit_chain`) over 580 rows | **0 problems** |
| Row counts, all 40 tables, before vs after | **no table lost a row** |
| Rollback `0006` | executed against the populated database, all 60 values restored byte for byte, then the corrected migration re-applied |
| Rollback `0007` | executed inside a transaction, all 6 values restored, its own assertions passed, then rolled back so the corrected state stands |

One pre-existing issue was found and **not** fixed, as it is unrelated to the
rename: `next lint` has no ESLint configuration in either app and prompts
interactively. Type checking runs as part of `next build` and passes.

### Defects this work found and fixed

1. **Double brand suffix.** Product pages rendered `… | POLYFIX | POLYFIX
   MATTRESS` — CMS titles already end with the brand and the root layout applied
   a `%s | BRAND` template on top. Fixed with `title: { absolute: title }` in
   `apps/web/src/lib/seo.ts`. Pre-existing; the rename only made it visible.
2. **Wrong legal name in migration 0006.** The bare-token replacement rule
   mapped `COLIFEES` to the short form, so `company.legal_name` became "POLYFIX"
   instead of "POLYFIX MATTRESS". Caught by the migration's own verification
   block, fixed with an explicit update plus an assertion.
3. **Broken hero images.** The hero and factory SVGs were renamed on disk but
   `website_pages.hero.image.src` still pointed at the old filenames, so the
   home and about heroes requested assets that no longer existed. Fixed in
   migration `0007`.
4. **Stale Next.js fetch cache.** `.next/cache` persists across builds and kept
   serving the old name after the database was already correct. Both apps must
   be rebuilt from a clean `.next` after a content rename.
