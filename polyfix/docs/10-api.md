# 10 — API reference

Fastify, JSON only. 103 routes across five trees.

**The browser never calls this API directly.** The public website renders on the
server and calls it over the private network; the console proxies through its
own `/api/bff/*` handler. This reference is for server-side integrators and for
anyone working on the platform.

---

## Conventions

**Base URL** — `API_PUBLIC_URL`, not published to the internet in production.

**Authentication** — cookies, set by `/auth/login`:

| Cookie | `httpOnly` | Path |
|---|---|---|
| `clf_at` | yes | `/` |
| `clf_rt` | yes | `/auth` |
| `clf_csrf` | no | `/` |

All `SameSite=Strict`, `Secure` outside development.

**CSRF** — every unsafe method on a cookie-authenticated route must send
`x-csrf-token` matching the `clf_csrf` cookie, and an `Origin` in the allow-list.
`GET`, `HEAD` and `OPTIONS` are exempt. So are `/public/*`, `/health*`,
`/auth/login`, `/auth/forgot-password` and `/auth/reset-password`, which are not
cookie-authenticated.

**Dealer scope** — applied by PostgreSQL row-level security from the
authenticated session, never from a parameter. A dealer asking for another
dealer's record gets `404`, the same answer as for a record that does not exist.

**Pagination** — `?page=1&pageSize=25` on list endpoints:

```json
{ "items": [], "total": 0, "page": 1, "pageSize": 25 }
```

**Errors** — one shape, always:

```json
{ "error": { "code": "FORBIDDEN", "message": "…", "requestId": "…", "details": {} } }
```

`details` appears only for validation failures. Never returned: password hashes,
secrets, database credentials, stack traces, SQL fragments, internal paths or
private tokens.

| Code | Status | |
|---|---|---|
| `VALIDATION_FAILED` | 422 | field-level `details` |
| `UNAUTHENTICATED` | 401 | no or invalid session |
| `INVALID_CREDENTIALS` | 401 | wrong email or password |
| `ACCOUNT_LOCKED` | 423 | too many failed attempts |
| `MFA_REQUIRED` | 401 | second factor needed |
| `MFA_INVALID` | 401 | wrong TOTP code |
| `PASSWORD_CHANGE_REQUIRED` | 403 | must change before continuing |
| `SESSION_EXPIRED` | 401 | refresh, then retry |
| `FORBIDDEN` | 403 | authenticated, not permitted |
| `NOT_FOUND` | 404 | |
| `CONFLICT` | 409 | |
| `BUSINESS_RULE` | 422 | a domain rule refused it |
| `RATE_LIMITED` | 429 | carries `retryAfter` |
| `PAYLOAD_TOO_LARGE` | 413 | |
| `UNSUPPORTED_MEDIA` | 415 | |
| `INTERNAL` | 500 | quote `requestId` to support |

**Rate limits** — global `RATE_LIMIT_GLOBAL_PER_MINUTE` (300), plus: login
5/min, refresh 30/min, forgot-password 3/15 min, reset-password 5/15 min, MFA
5–10/5 min, change-password 5/5 min, public forms 5/hour, verification 20/min.

---

## Health

| | | |
|---|---|---|
| `GET` | `/health` | liveness, no database access |
| `GET` | `/health/ready` | readiness, round-trips to the database |

Use `/health` for restart decisions and `/health/ready` for load-balancer
membership. Swapping them restarts the API whenever the database hiccups.

---

## `/auth` — session lifecycle

| | | |
|---|---|---|
| `POST` | `/auth/login` | email + password, then `totpCode` if enrolled |
| `POST` | `/auth/refresh` | rotates the refresh token |
| `POST` | `/auth/logout` | revokes the current session |
| `GET` | `/auth/me` | the signed-in user, roles and effective permissions |
| `POST` | `/auth/change-password` | current + new |
| `POST` | `/auth/forgot-password` | always `204`, whether or not the address exists |
| `POST` | `/auth/reset-password` | token + new password |
| `POST` | `/auth/mfa/start` | begins TOTP enrolment, returns the secret once |
| `POST` | `/auth/mfa/confirm` | confirms with a code |
| `POST` | `/auth/mfa/disable` | password + a valid code |
| `GET` | `/auth/sessions` | this user's active sessions |
| `POST` | `/auth/sessions/revoke-others` | keep this one, end the rest |

`/auth/forgot-password` returns `204` regardless, so the endpoint cannot be used
to discover which addresses have accounts.

**Refresh tokens rotate on every use.** Presenting one twice is treated as
theft: the whole session family is revoked and `REFRESH_TOKEN_REUSE` is logged.

---

## `/public` — unauthenticated

Serves the public website. No session, no private data.

| | | |
|---|---|---|
| `POST` | `/public/verify` | warranty lookup by serial |
| `GET` | `/public/content/site` | settings, navigation, footer |
| `GET` | `/public/content/pages/:slug` | a published page |
| `GET` | `/public/content/products` | published catalogue |
| `GET` | `/public/content/products/:slug` | one product |
| `GET` | `/public/dealers` | dealer locator |
| `GET` | `/public/dealers/regions` | regions with dealers |
| `GET` | `/public/sitemap` | sitemap data |
| `POST` | `/public/leads` | contact form |
| `POST` | `/public/dealer-applications` | dealership application |

`/public/verify` returns warranty status, product and dates. It deliberately
omits the customer, the dealer, the sale price, the invoice, claim history and
risk indicators, although the row it reads contains all of them. A serial is not
a credential — anyone can read one off a label — so the answer is written for a
stranger holding the mattress.

---

## `/ops` — staff console

Every route sits behind a `requireStaff` hook registered on the tree's
encapsulated context, so a new route cannot forget it. Dealers get `403` here
regardless of the permissions they hold.

Each route additionally declares its own permission. There is no blanket "admin
area" check.

### Dashboard

| | | |
|---|---|---|
| `GET` | `/ops/dashboard` | `dashboard:view` |

### Manufacturing

| | | |
|---|---|---|
| `GET` | `/ops/warehouses` | `batch:read` |
| `GET` | `/ops/batches` | `batch:read` |
| `POST` | `/ops/batches` | `batch:write` |
| `POST` | `/ops/mattresses/produce` | `mattress:create` |
| `GET` | `/ops/mattresses` | `mattress:read` |
| `POST` | `/ops/mattresses/lookup` | `mattress:read` |
| `GET` | `/ops/mattresses/:id` | `mattress:read` |
| `GET` | `/ops/mattresses/:id/qr` | `mattress:read` — `?format=svg\|png\|json` |
| `DELETE` | `/ops/mattresses/:id` | `mattress:delete` — soft delete, reason required |

The QR code encodes a verification URL carrying an **opaque token**, never the
serial. A printed label therefore reveals neither how many units exist nor a way
to walk the catalogue.

### Logistics

| | | |
|---|---|---|
| `GET` | `/ops/dispatches` | `dispatch:read` |
| `POST` | `/ops/dispatches` | `dispatch:create` |
| `GET` | `/ops/dispatches/:id` | `dispatch:read` |
| `POST` | `/ops/dispatches/:id/send` | `dispatch:update` |
| `POST` | `/ops/dispatches/:id/cancel` | `dispatch:cancel` — reason required |

### Network

| | | |
|---|---|---|
| `GET` | `/ops/dealers` | `dealer:read` |
| `POST` | `/ops/dealers` | `dealer:write` |
| `GET` | `/ops/dealers/:id` | `dealer:read` |
| `PATCH` | `/ops/dealers/:id` | `dealer:write` |
| `GET` | `/ops/applications` | `dealer_application:read` |
| `POST` | `/ops/applications/:id/review` | `dealer_application:review` |
| `GET` | `/ops/users` | `user:read` |
| `POST` | `/ops/users` | `user:write` |
| `PATCH` | `/ops/users/:id` | `user:write` |
| `POST` | `/ops/users/:id/roles` | `user:role:assign` — **MFA-gated** |
| `POST` | `/ops/users/:id/force-password-reset` | `user:reset_password` |
| `GET` | `/ops/roles` | `user:read` |

### Warranty

| | | |
|---|---|---|
| `GET` | `/ops/warranties` | `warranty:read` |
| `POST` | `/ops/warranties/:id/void` | `warranty:void` — reason required |
| `GET` | `/ops/claims` | `claim:read` |
| `GET` | `/ops/claims/:id` | `claim:read` |
| `POST` | `/ops/claims/:id/notes` | `claim:review` |
| `POST` | `/ops/claims/:id/request-information` | `claim:review` |
| `POST` | `/ops/claims/:id/media` | `claim:review` |
| `POST` | `/ops/claims/:id/recompute-risk` | `risk:view` |
| `POST` | `/ops/claims/:id/decision` | `claim:decide` — reason required |
| `POST` | `/ops/claims/:id/replacement` | `claim:replace` |

Note that deciding, replacing and reviewing are three separate permissions. A
reviewer who may request information cannot approve.

### Content and SEO

| | | |
|---|---|---|
| `GET` | `/ops/products` | `product:read` |
| `POST` | `/ops/products` | `product:write` |
| `GET` | `/ops/products/:id` | `product:read` |
| `PATCH` | `/ops/products/:id` | `product:write` |
| `POST` | `/ops/products/:id/variants` | `product:write` |
| `POST` | `/ops/products/:id/publish` | `product:publish` |
| `DELETE` | `/ops/products/:id` | `product:delete` — soft delete, reason required |
| `GET` | `/ops/pages` | `cms:read` |
| `GET` | `/ops/pages/:slug` | `cms:read` |
| `PUT` | `/ops/pages/:slug` | `cms:write` |
| `POST` | `/ops/pages/:slug/publish` | `cms:publish` |
| `GET` | `/ops/faqs` | `cms:read` |
| `POST` | `/ops/faqs` | `cms:write` |
| `PATCH` | `/ops/faqs/:id` | `cms:write` |
| `GET` | `/ops/seo` | `seo:read` |
| `PUT` | `/ops/seo/:scope/:key` | `seo:write` |
| `GET` | `/ops/leads` | `lead:read` |
| `PATCH` | `/ops/leads/:id` | `lead:update` |

### System

| | | |
|---|---|---|
| `GET` | `/ops/system/audit` | `audit:read` |
| `GET` | `/ops/system/audit/verify` | `audit:read` — walks the hash chain |
| `GET` | `/ops/system/history/:entity/:id` | `audit:read` |
| `GET` | `/ops/system/health` | `system:health` |
| `GET` | `/ops/system/settings` | `system:settings:read` |
| `PUT` | `/ops/system/settings/:key` | `system:settings:write` — **MFA-gated** |
| `GET` | `/ops/system/backups` | `system:backup` — **MFA-gated** |
| `POST` | `/ops/system/backups` | `system:backup` — **MFA-gated** |
| `POST` | `/ops/system/query` | `system:sql:read` — **MFA-gated**, `SUPER_ADMIN`, read-only |

There is **no** endpoint that deletes or edits an audit entry. The privilege
does not exist on the application's database connection.

`/ops/system/query` executes read-only SQL only. There is no route to `UPDATE`,
`DELETE` or DDL through this API.

---

## `/dealer` — dealer portal

Row-level security narrows every one of these to the caller's own dealership. A
serial belonging to another dealership returns "not found", identically to one
that does not exist.

| | | |
|---|---|---|
| `GET` | `/dealer/summary` | `dashboard:view` |
| `POST` | `/dealer/scan` | `mattress:read` — by `serialNumber` or `qrToken` |
| `GET` | `/dealer/inventory` | `mattress:read` |
| `GET` | `/dealer/inventory/summary` | `mattress:read` |
| `GET` | `/dealer/incoming` | `dispatch:read` |
| `POST` | `/dealer/receive` | `receipt:create` — per unit: `OK`, `DAMAGED`, `MISSING` |
| `POST` | `/dealer/sales` | `sale:create` — records the sale, activates the warranty |
| `GET` | `/dealer/sales` | `sale:read` |
| `POST` | `/dealer/claims` | `claim:create` |
| `GET` | `/dealer/claims` | `claim:read` |
| `GET` | `/dealer/claims/:id` | `claim:read` |
| `POST` | `/dealer/claims/:id/media` | `claim:create` — multipart |
| `GET` | `/dealer/notifications` | `dashboard:view` |
| `POST` | `/dealer/notifications/:id/read` | `dashboard:view` |

---

## `/media`

| | | |
|---|---|---|
| `GET` | `/media/:id` | `claim:media:view` |

Claim photographs and invoices. Authorised per request, then streamed — the
bucket is private and has no public read. Signed URLs expire after
`MEDIA_SIGNED_URL_TTL_SECONDS` (300 by default).

Uploads: `MAX_UPLOAD_BYTES` (8 MiB) and `MAX_UPLOADS_PER_CLAIM` (8). Content
type is checked server-side; the filename the client sends is never trusted.

---

## Worked example

```bash
# 1. sign in, keeping cookies
curl -c jar.txt -X POST https://api.internal.polyfixmattress.com/auth/login \
  -H 'content-type: application/json' \
  -H 'origin: https://app.polyfixmattress.com' \
  -d '{"email":"admin@polyfixmattress.com","password":"…","totpCode":"123456"}'

# 2. read the CSRF token the login set
CSRF=$(awk '/clf_csrf/ {print $7}' jar.txt)

# 3. a safe request needs no CSRF header
curl -b jar.txt https://api.internal.polyfixmattress.com/ops/dashboard

# 4. an unsafe one needs both the header and an allowed Origin
curl -b jar.txt -X POST https://api.internal.polyfixmattress.com/ops/batches \
  -H "x-csrf-token: $CSRF" \
  -H 'origin: https://app.polyfixmattress.com' \
  -H 'content-type: application/json' \
  -d '{"warehouseId":"…","plannedQuantity":100}'
```

Omit the `Origin` header, or send one not in the allow-list, and step 4 returns
`403 FORBIDDEN` — `CSRF_ORIGIN_REJECTED` in the log.

---

## Server-to-server

The public website sends `x-internal-token` (`INTERNAL_API_TOKEN`) on its calls
to `/public/*`. The header is allowed through CORS and redacted from logs.

**The API does not currently verify it.** `/public/*` is reachable by any
anonymous caller, which is deliberate for a public website — the endpoints back
indexed pages and a warranty lookup anyone can perform from a label — and the
security suite asserts that anonymous access works. The token is plumbing for a
control that is not yet switched on. See
[07 — Security](07-security.md#known-gaps).

Do not rely on it as an authorisation boundary. It is not a user session and
grants no permission. It must still never appear in a browser bundle;
`apps/web/src/lib/api.ts` starts with `import 'server-only'` so that importing it
from a client component is a build error.

---

## Changing the API

- Every new route declares its permission explicitly. Inheriting is not a thing.
- Add the permission to `packages/auth/src/rbac.ts` first — that file seeds the
  database and drives the console's navigation.
- A route that touches a dealer-scoped table goes through `request.db(…)`, which
  sets the row-level-security scope from the session. Do not bypass it.
- Validate with a Zod schema from `@polyfix/validation`. Never trust a body, a
  query parameter or a path parameter.
- Return an `AppError`. Anything else becomes a generic 500, which is correct
  but unhelpful.
