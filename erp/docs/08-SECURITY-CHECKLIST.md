# DGR Office — Security review checklist

Status key: ✔ implemented and covered by automated tests · ◐ implemented,
verified manually · ⚠ deployment responsibility / recommendation.

## 1. Deployment

| # | Item | How | Status |
| --- | --- | --- | --- |
| 1.1 | Only `public/` is web-exposed | Document-root instructions in 04-DEPLOYMENT; `writable/.htaccess` denies all as a second line of defence | ⚠ verify `https://…/.env` and `/writable/` are not served |
| 1.2 | HTTPS everywhere | `app.forceGlobalSecureRequests`, `cookie.secure`, HSTS header sent on HTTPS | ⚠ enable after SSL is installed |
| 1.3 | Production mode hides errors | `CI_ENVIRONMENT = production`; debug toolbar disabled in `Config/Filters.php` | ⚠ |
| 1.4 | Secrets only in `.env` | DB, SMTP, encryption key, AI key, Google credentials path; nothing secret in git, the database UI or `.env.example`; Integrations page shows status only (`RecurringIntegrationsTest::testIntegrationsShowStatusWithoutSecretsAndNeedPermission`) | ✔ |
| 1.5 | Encryption key backed up separately | Needed to read encrypted bank numbers/CIF | ⚠ |
| 1.6 | Diagnostics warn about insecure settings | Admin → Diagnostics / `dgr:diagnostics` (HTTP, dev mode, missing key, cron, backups) | ◐ |
| 1.7 | Dependencies pinned | `composer.lock`; release archive built from it, dev packages excluded | ✔ |

## 2. Authentication and sessions

| # | Item | How | Status |
| --- | --- | --- | --- |
| 2.1 | Strong passwords | Shield validators, minimum 10 characters, similarity check against the user's details | ✔ |
| 2.2 | Login throttling | Shield `auth-rates` filter on login routes | ◐ |
| 2.3 | Temporary passwords must be changed | `must_change_password` enforced on every request by `SessionGuardFilter` | ✔ |
| 2.4 | Sessions can be revoked immediately | `user_sessions` checked on every request; Admin → Sessions and Profile → Sessions | ◐ |
| 2.5 | Disabled accounts are logged out at the next request | `SessionGuardFilter` | ◐ |
| 2.6 | Service accounts cannot log in interactively | `SessionGuardFilter` blocks `is_service_account`; AI tokens only work on `/api/ai/*` | ✔ |
| 2.7 | Optional e-mail 2FA, remember-me, magic links | Off by default (`auth.*` in `.env`) | ⚠ enable 2FA for owners once SMTP works |
| 2.8 | CSRF failures are explained, never looped | `App\Filters\CsrfFilter`: when the browser sent no session cookie (secure cookie on http, wrong address, blocked cookies) the sign-in page says why (`?session=missing`) and the server logs it (`LoginLandingTest`) | ✔ |
| 2.9 | Secrets are not kept in the session after a failed form | `forget_secret_input()` drops passwords, bank account numbers, CIF and keys from the input kept to refill forms (`BankSetupTest`) | ✔ |
| 2.10 | Landing page after sign-in respects permissions | `App\Libraries\Landing`: a role's landing page is used only if the user may open it; the role editor offers only real pages (`LoginLandingTest`) | ✔ |
| 2.8 | CSRF protection on every form | Global `csrf` filter (except the token-authenticated AI API) | ✔ |
| 2.9 | Signed-out requests | Every route needs a session except sign-in/out, the AI token API, `health`, `share/*` and `brand/logo` (the official logo shown on the sign-in page). `SignedOutRequestFilter` answers signed-out images and script calls with 401 so only real page visits become the post-sign-in destination (`SignInFlowTest`) | ✔ |

## 3. Authorization (deny by default)

| # | Item | How | Status |
| --- | --- | --- | --- |
| 3.1 | Every module route needs a named permission | `perm:` filter on each route; the only routes without one are self-scoped (own profile, own notifications, permission-filtered search, file download via `FileAccessPolicy`, logo for signed-in users) | ✔ |
| 3.2 | Permissions are data, not role names | DB roles/permissions editable in Admin → Roles; per-user allow/deny overrides; deny wins (`RbacAdminTest`) | ✔ |
| 3.3 | Record-level scopes (own / assigned / team / all) | `BillingAccess`, `LeadAccess`, `CandidateAccess` applied in queries; foreign ids return 404 (`CrmAccessReminderTest`, `BillingUiPdfTest`) | ✔ |
| 3.4 | No indirect leakage via dashboards, counts, search, exports | Widget-level permissions; dashboard JSON omits finance/payroll series without permission (`RbacAdminTest`); search filtered per module; every export checks its own permission | ✔ |
| 3.5 | Dangerous grants need password re-entry and are audited | `RbacAdminTest::testDangerousGrantNeedsPasswordAndExpiredRoleGrantsNothing` | ✔ |
| 3.6 | Time-limited roles | `user_roles.expires_at` | ✔ |
| 3.7 | Last Super Admin cannot be removed/deactivated | `RbacAdminTest::testLastSuperAdminCannotBeRemovedOrDeactivated` | ✔ |
| 3.8 | Separation of duties | No self-approval of expenses, leaves, incentives; reviewer ≠ requester (`ExpensesUiTest`, `HrPayrollTest`) | ✔ |
| 3.9 | Field-level isolation | Recruiters never see salaries; telecallers never see finance/GST/payroll; billing operators get only allowed customer fields (`RecruitmentAiTest`, `CrmAccessReminderTest`, `BillingUiPdfTest`) | ✔ |

## 4. Sensitive data

| # | Item | How | Status |
| --- | --- | --- | --- |
| 4.1 | Bank account numbers, CIF and employee bank details encrypted at rest | CI4 encrypter (key from `.env`); only last 4 digits stored in clear for display | ✔ |
| 4.2 | Full account number shown only on purpose | Separate permission + audited “reveal” action | ✔ |
| 4.3 | CIF never leaves the app | Absent from PDFs, XLSX, e-mails, Sheets and AI payloads (`AcceptanceEvidenceTest` scans every generated file) | ✔ |
| 4.4 | Private files outside the web root | `writable/uploads`, served by `FilesController` after `FileAccessPolicy`; random file names; SHA-256 recorded | ✔ |
| 4.5 | Upload validation | Per-profile extension/MIME/size allowlists; CVs parsed server-side, never executed | ✔ |
| 4.6 | Official logo preserved byte-for-byte | Stored as uploaded; PDF uses the original bytes (`AcceptanceEvidenceTest`) | ✔ |
| 4.7 | Applicant consent and retention | Consent required at intake; daily purge anonymises and deletes files after the retention period (`dgr:cron` hr task) | ✔ |
| 4.8 | Payslips and salary registers | `hr.payroll.*` / `hr.salary.*` permissions; bank masked (••••last4); employees see only their own (`HrPayrollTest`) | ✔ |
| 4.9 | Google Sheets receive only mapped CRM fields; DND numbers masked | `CrmLeadSyncTest::testDndLeadsAreMaskedInSheetsAndCallsAreBlocked` | ✔ |
| 4.10 | Browser caching of private pages | `Cache-Control: no-store` for signed-in responses | ✔ |
| 4.11 | Backups | Admin-only, audited downloads, strict file-name pattern (no path traversal) | ✔ · ⚠ copy off-server, encrypted |

## 5. Integrity and audit

| # | Item | How | Status |
| --- | --- | --- | --- |
| 5.1 | Tamper-evident audit log | Append-only `audit_logs` with SHA-256 hash chain; preserved by backup/restore and demo purge | ✔ |
| 5.2 | Finalized documents are immutable | Edit/delete refused; corrections by credit/debit note, void with reason | ✔ |
| 5.3 | Statutory numbering under concurrency | Row lock on the sequence; 6 parallel finalizations never collide (`BillingAccountingTest`) | ✔ |
| 5.4 | Every money movement is a balanced journal | `LedgerService` refuses unbalanced entries; trial balance tests | ✔ |
| 5.5 | Payroll lock | Locked periods reject changes; post-lock adjustments need a reason, post their own journal and a new payslip version, and are audited | ✔ |
| 5.6 | Incentives paid once | Unique source references + row locks; clawbacks for reversed sales | ✔ |

## 6. Web application hardening

| # | Item | How | Status |
| --- | --- | --- | --- |
| 6.1 | Strict Content-Security-Policy | `default-src 'self'`; `script-src 'self'` and `style-src 'self'` (no inline scripts or styles, no `unsafe-inline`), `font-src 'self'`, `connect-src 'self'`, `frame-ancestors 'none'`, `object-src 'none'`, `form-action 'self'`, `base-uri 'self'`; `ThemeComplianceTest` fails on any inline script/style in a view | ✔ |
| 6.1a | Self-hosted, verified frontend libraries | No CDN at runtime. Bootstrap 5.3.8, Bootstrap Icons 1.13.2 and Chart.js 4.5.1 are official npm-registry files, unmodified, pinned in `public/assets/vendor/MANIFEST.json` (sha512 tarball + sha384 per file). `VendorAssetsTest` checks the hashes offline; `scripts/verify-vendor-assets.sh` re-downloads, compares byte-for-byte and runs `npm audit` (0 vulnerabilities, 2026-10-20). No jQuery. See [09-UI-THEME.md](09-UI-THEME.md) | ✔ |
| 6.2 | Other headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `COOP` | ✔ |
| 6.3 | SQL injection | Query Builder / bound parameters throughout; no user input concatenated into SQL | ◐ code review |
| 6.4 | XSS | Views escape output with `esc()`; untrusted CV/Sheet text is never rendered as HTML | ◐ code review |
| 6.5 | Invalid characters | CI4 `invalidchars` filter globally | ✔ |
| 6.6 | Exact money arithmetic | `brick/math` decimals (no floats) for tax, ledger and payroll | ✔ |
| 6.7 | Strict SQL mode | The app's own connection sets `STRICT_ALL_TABLES` (`strictOn` in `Config\Database`), so invalid data is rejected instead of silently truncated, whatever the server default | ✔ |
| 6.8 | Errors shown safely | Business-rule errors that no controller catches are shown as their message (form → back with input, script call → JSON 422, page → themed page); every other exception keeps the generic error page in production and is logged. Missing uploads are logged at ERROR level | ✔ |
| 6.9 | App folder inside `public_html` | The root `.htaccess` serves only `public/`; `.env`, `app/`, `writable/`, `vendor/`, `tests/` answer 403/404 (verified with Apache in a subfolder). Diagnostics fails if that `.htaccess` is missing in this layout | ✔ |

## 7. AI boundary (details in 06-AI-BOUNDARY.md)

| # | Item | Status |
| --- | --- | --- |
| 7.1 | All capabilities off by default; cost ceiling 0 = blocked | ✔ |
| 7.2 | Field allowlist, consent, scope, rate limit and budget checked before any data leaves | ✔ |
| 7.3 | No SQL/ORM/generic endpoints; unknown `/api/ai/*` paths → 404 and logged | ✔ |
| 7.4 | Tokens hashed, scoped, expiring, revocable, shown once | ✔ |
| 7.5 | Prompt-injection neutralised, protected traits redacted, names/contacts removed | ✔ |
| 7.6 | Replies validated (verbatim evidence, server-computed score, protected-trait replies discarded); failures shown as “Analysis unavailable” | ✔ |
| 7.7 | No automated hiring decision or silent rejection; payroll AI read-only | ✔ |

## 7a. Bank details (1.3.0 review)

| # | Item | How | Status |
| --- | --- | --- | --- |
| 7a.1 | Only `settings.bank.edit` can change bank accounts | Route filter + test (`BankSetupTest::testOnlyAuthorisedUsersCanChangeBanks`) | ✔ |
| 7a.2 | Account number and CIF encrypted, never put back into the form | Blank = keep; masked last 4 digits only; CIF never shown (`BankSetupTest`) | ✔ |
| 7a.3 | Every change audited without secrets | `bank_account.created/updated` with `account_number(_enc)` and `cif(_enc)` redacted (`BankSetupTest`) | ✔ |
| 7a.4 | CIF never printed | Snapshot never reads CIF; PDF audit test checks all document types (`DocumentPdfAuditTest`) | ✔ |
| 7a.5 | Issued invoices keep the bank details they were issued with | Immutable snapshot; drafts and new invoices use the current details (`BankSetupTest`) | ✔ |

## 8. Residual risks and recommendations

* No web-application firewall or antivirus scanning of uploads — use the
  host's WAF / ClamAV if available.
* Login throttling is per Shield's defaults; consider fail2ban on a VPS.
* Backups on the server are not encrypted at rest; protect the server
  account and keep encrypted off-site copies.
* E-mail 2FA depends on SMTP; enable it for owner and finance roles.
* SQL injection and XSS are addressed by framework conventions and
  review, not by an automated scanner; run a DAST scan (e.g. OWASP ZAP)
  against a staging copy before go-live.
* Live Google Sheets and Anthropic calls were not exercised with real
  credentials during development.
