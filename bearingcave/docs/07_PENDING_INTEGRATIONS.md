# 07 — Pending integration checklist

Nothing below is simulated in production. Each item has an integration point in the code. Until it is configured, the platform says plainly that the feature is not active.

| # | Integration | Integration point | What exists now | To go live |
|---|---|---|---|---|
| I-1 | **SMTP / transactional email** | `.env` `email.*`, `NotificationService`, `bearingcave:outbox` | Every email is written to `email_outbox`; status `disabled` while `email.delivery_enabled=0`; the mailer's real result is recorded | Choose a provider (SES, SendGrid, Postmark or the client's SMTP), set the `.env` values, configure SPF/DKIM/DMARC for bearingcave.com, set `email.delivery_enabled=1`, run `php spark bearingcave:outbox` from cron |
| I-2 | **Escrow / payment partner** | `App\Libraries\Payments\PaymentGatewayInterface`; register the gateway in `BillingService::gateways()`; webhook `POST /webhooks/payments/{code}` | `bank_transfer` (live, manual reconciliation by finance) and `sandbox` (blocked in production) | Select a licensed provider (D-13). Implement `createPayment`, `parseWebhook` (signature check) and `supportsEscrow`, then add the secret to `.env`. Idempotency and reconciliation tables already exist |
| I-3 | **Sanctions / PEP / AML screening** | `sanctions_screenings` table, `sanctions.provider` setting, the verification sanctions stage | Officers record manual checks (lists checked, result, reference, evidence document) | Select a provider (Refinitiv World-Check, Dow Jones, ComplyAdvantage or similar) and add a service that writes the same rows with `provider` set |
| I-4 | **SMS / WhatsApp** | `notifications.sms_provider`, `notifications.whatsapp_provider` | Not implemented | Approve a provider (D-17), then add a channel in `NotificationService` that records the provider's acceptance id |
| I-5 | **MFA** | Shield `Auth::$actions['login'] = \CodeIgniter\Shield\Authentication\Actions\Email2FA::class` | Off | Requires I-1. Decide the policy (D-19). TOTP would need an extra package |
| I-6 | **Password reset delivery** | Shield magic link (`/login/magic-link`) | Works, but the link is emailed, so it needs I-1 | Enable I-1. Until then an admin with `users.manage` can use **Issue temporary password** in Admin → Users → user (one-time password, audited) |
| I-7 | **FX rates** | `currencies` table | No conversion; reports group amounts by currency | Only if the client decides on conversion (D-14) |
| I-8 | **GST e-invoicing / tax engine** | `invoices`, `tax.*` settings | Invoices carry tax lines from settings | Tax adviser review (D-04); integrate with the GSTN IRP if B2B e-invoicing applies |
| I-9 | **KYC/KYB data providers** (GST, PAN, CIN lookup, bank penny-drop) | `kyc_records.*_verified` flags plus verification notes | Officers verify manually against uploaded evidence | Optional provider integration |
| I-10 | **Object storage / CDN** | `DocumentService` (local `writable/uploads/private`), `public/uploads/products` | Local disk | For multi-server deployments, move to S3-compatible storage (private bucket for documents) |
| I-11 | **Error monitoring and uptime** | CI logger (`writable/logs`) | File logs | Sentry or similar; uptime monitor on `/` and `/api/v1/categories` |
| I-12 | **Google Search Console** | `seo.google_site_verification` setting, `/sitemap.xml` | Ready | Paste the verification token, set `seo.indexing_enabled=1` at launch |
| I-13 | **Malware scanning of uploads** | `DocumentService::store()` (MIME, extension and size validated; SHA-256 stored) | No antivirus scan | Optional ClamAV hook before saving |
| I-14 | **Backups** | — | Documented in `DEPLOYMENT.md` | Schedule `mysqldump` and copies of `writable/uploads`; test restores |
