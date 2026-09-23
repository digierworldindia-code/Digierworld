# 11 — Brand configuration

Where the company's name, short name and location live, what is safe to change,
and what is deliberately frozen.

---

## 1. The single source of truth

`packages/brand/src/index.ts` exports one object, `BRAND`. Everything that
renders the company name — both Next.js apps, the API, the seed script, SVG
alt text, e-mail defaults, the TOTP issuer — reads from it.

```ts
import { BRAND } from '@polyfix/brand';

BRAND.name           // "POLYFIX MATTRESS"   full legal/display name
BRAND.shortName      // "POLYFIX"            headings, wordmark, tight spaces
BRAND.legalName      // "POLYFIX MATTRESS"   schema.org legalName
BRAND.wordmark       // { lead: "POLY", trail: "FIX" }  two-tone logotype
BRAND.location.line  // "Manesar, Noranpur Chowk, Haryana, India"
BRAND.web            // default site/app URLs and locale
BRAND.serialPrefix   // "CLF" — see §4, this is NOT a display value
```

The package has no dependencies and reads no environment variables at import
time, so it is safe in a browser bundle, on the server, in the seed script and
in tests alike.

**Do not add a second constant holding the same value.** There is no
`COMPANY_NAME`, `SITE_NAME` or `APP_NAME` elsewhere; `apps/web/src/lib/site.ts`
derives from `BRAND` rather than repeating it.

---

## 2. Three tiers — which one does a value belong in?

| Tier | Lives in | Changing it needs | Use for |
|---|---|---|---|
| **Structural** | `packages/brand/src/index.ts` | a code change + deploy | name, short name, wordmark split, head-office location |
| **Per-deployment** | environment variables | an env change + restart | `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_APP_URL`, `NEXT_PUBLIC_SITE_NAME`, `MAIL_FROM`, `NEXT_PUBLIC_CONSOLE_NAME` |
| **Runtime, editable by an administrator** | `system_settings` table | the admin console, no deploy | public phone number, support e-mail, postal address shown on the site, social links |

Rule of thumb: if a non-developer might reasonably want to change it on a
Tuesday afternoon, it belongs in `system_settings`. If changing it means the
build output changes, it belongs in `packages/brand`.

Environment variables **override** the brand defaults where both exist —
`site.ts` reads `process.env.NEXT_PUBLIC_SITE_NAME ?? BRAND.name`. That is how
a staging deployment can carry a different hostname without a code change.

---

## 3. Renaming the company again — the checklist

1. Edit `packages/brand/src/index.ts` (`name`, `shortName`, `legalName`,
   `wordmark`, `location`, `web.defaultSiteUrl`, `web.defaultAppUrl`).
2. Update `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_APP_URL`, `NEXT_PUBLIC_SITE_NAME`,
   `MAIL_FROM` and `NEXT_PUBLIC_CONSOLE_NAME` in every deployment's environment.
3. Rename the brand-named SVGs under `apps/web/public/images/` and update the
   two `website_pages.hero.image.src` values that point at them.
4. Write a data migration for stored content: `system_settings`, `products`,
   `seo_metadata` (including the lower-case `keywords` array), `website_pages`,
   `website_products`, `faqs`, `warehouses.name`. Capture every pre-change value
   into `brand_rename_backup` first, and ship an executable down path. Migration
   `0006` and `0007` are the worked examples, with rollback scripts in
   `scripts/sql/`.
5. Add a `301` redirect in `apps/web/next.config.ts` for any renamed URL.
6. Rebuild both Next apps from a clean `.next` — the fetch cache persists
   across builds and will happily serve the old name otherwise.
7. Re-run the API test suite and re-scan the rendered HTML, not just the source.

**Do not** touch `audit_logs`, `record_versions`, `notifications`,
`mattress_events`, `claim_events`, `failed_login_attempts`, `users.email`, or
any customer, dealer, sales or warranty row. Those are records of what actually
happened or credentials people log in with.

---

## 4. Deliberately frozen — and why

These still carry the previous company name. Each one is an identifier or a
historical fact, not branding, and each was left alone on purpose.

| Thing | Where | Why it stays |
|---|---|---|
| `CLF` serial prefix | `mattresses.serial_number`, `BRAND.serialPrefix` | Printed on physical labels already in the field and inside QR codes. It is referenced by warranties, claims, dispatches and replacements, and guarded by a CHECK constraint. Changing it would orphan every mattress manufactured to date. A future prefix change means a new prefix for *new* production only, plus an update to `colifees_next_serial()` — never a rewrite of existing serials. |
| Database name `colifees` | connection strings | Renaming a database means coordinated downtime, new connection strings in every secret store, and new backup paths, to change a string no customer ever sees. |
| Roles `colifees_owner`, `colifees_app`, `colifees_readonly`, `colifees_backup` | `scripts/sql/01_roles.sql`, `02_grants.sql`, `03_verify_privileges.sql`, `04_sync_sequences.sql`, `scripts/backup.sh` | Renaming a PostgreSQL role rewrites every grant, default ACL and RLS policy that names it, and invalidates every stored credential at once. High risk, zero user-visible benefit. |
| Functions `colifees_next_serial()`, `colifees_next_batch_code()`, `colifees_next_dealer_code()`, `colifees_next_claim_number()`, `colifees_next_dispatch_code()`, `colifees_verify_audit_chain()`, trigger `colifees_audit_seal` | migrations `0001`–`0003`, `apps/api/src/lib/ids.ts`, `apps/api/src/routes/ops/system.ts` | Applied migrations are history. Renaming them means a new migration that drops and recreates each function and re-points every trigger — DDL churn against the audit seal, to rename something only a DBA reads. |
| Migrations `0001`–`0005` | `packages/database/prisma/migrations/` | Already applied. An applied migration is a historical record; corrections go in a *new* migration. |
| `brand_rename_backup` contents | database | It exists to hold the pre-rename values. |
| `/why-colifees` | `apps/web/next.config.ts` | An intentional `301` to `/why-polyfix`, so inbound links and indexed URLs keep working. |

The GUC used for row-level security is `app.dealer_id` — brand-neutral already.

---

## 5. Where the wordmark is rendered

`apps/app/src/components/ui.tsx` exports `<Wordmark />`, which renders
`BRAND.wordmark.lead` followed by `BRAND.wordmark.trail` in a `<span>` so the
two halves can be styled differently. The console shell, the login page, and
the forgot/reset-password pages all use it — there is no hand-written copy of
the split logotype anywhere.

---

## 6. Things that changing the brand does *not* break

- **Existing TOTP enrolments.** `BRAND.shortName` is the TOTP issuer label shown
  in authenticator apps. It is cosmetic; the shared secret is unchanged, so
  existing enrolments keep working and simply display the new label on re-scan.
- **Refresh tokens and sessions.** Refresh tokens are opaque and database-backed.

And one it *does*: the JWT `issuer`/`audience` claims in
`packages/auth/src/jwt.ts` became `polyfix.auth` / `polyfix.api`. Access tokens
issued under the old identifiers fail verification, so the first deploy carrying
that change signs every active session out once. Nothing is lost — clients
refresh and get a new access token — but expect a spike of re-authentications.
