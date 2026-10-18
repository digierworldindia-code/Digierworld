# DGR Office — AI boundary

AI in DGR Office is **decision support for one purpose at a time**. It is
a service identity with a short allowlist of *capabilities*; it has no SQL
access, no ORM, no generic query/search/export/file endpoints, and never
sees `.env`, backups, billing, CRM, bank or Google Sheets data. A person
makes every hiring, payroll and data-sharing decision.

Everything is **off by default**. With no API key configured the app
still works: CV screening falls back to a transparent rules-based keyword
check that produces **no score**, and any failed or unavailable AI request
is shown as **“Analysis unavailable”** — never a made-up score.

---

## 1. Capabilities (Admin → AI permissions, `/admin/ai`)

| Code | What it may do | Fields it may receive |
| --- | --- | --- |
| `ai.recruitment.evaluate_cv` | Review one selected CV against one active JD version | `candidate.cv_text_sanitised`, `job.title`, `jd_version.content`, `jd_version.must_have`, `jd_version.nice_to_have`, `rubric` |
| `ai.recruitment.suggest_interview_questions` | Add suggested interview questions to that review | `candidate.cv_text_sanitised`, `jd_version.content` |
| `ai.recruitment.create_timeline_entry` | *Propose* one candidate-timeline event; HR accepts or rejects it | `candidate.id`, `recruitment_event.proposed` |
| `ai.payroll.read_approved_summary` | Read finalized payroll summaries for allow-listed employees and a period | `payroll_item.employee_code`, `payable_days`, `gross_pay`, `total_deductions`, `net_pay`, `payroll_run.period` |
| `ai.payroll.summarize_period` | Read one finalized period's aggregate totals | `payroll_run.period`, `payroll_run.totals` |

Each capability row stores: **enabled** (default off), **share with
external provider** (default off), **requires consent** (always on for
recruitment), **hourly rate limit** (default 30), **monthly cost ceiling
in USD** (default **0 = blocked**), **retention days** (180) and, for
payroll, the **employee allowlist**. Changes are audited
(`ai.capability_updated`). The page needs `ai.permissions.manage`; usage
and the action log need `ai.usage.view`.

## 2. What every AI request passes through (`App\Services\Ai\AiGateway`)

In order — the first failing check denies the request and logs the reason:

1. capability exists and is **enabled** → `capability_disabled`
2. sending data out is allowed (**share with provider**) → `sharing_not_allowed`
3. a provider and API key are configured (in-app requests) → `provider_not_configured`
4. requested fields ⊆ the capability's **field allowlist** → `field_not_allowed`
5. the data subject **consented** (applicant tick-box at intake) → `consent_missing`
6. the record is inside the capability's **scope** (e.g. payroll employee allowlist) → `out_of_scope`
7. a non-zero **monthly cost ceiling** exists → `no_budget`; not yet reached → `budget_exhausted`
8. under the **hourly rate limit** → `rate_limited`

An approved request receives a **grant** (`ai_access_grants`) bound to
one capability, one record and the exact field list, valid for 15
minutes and consumed once. `ai_action_logs` records who/what/when, the
capability, record ids, **field names only** (never content), outcome,
deny reason, model, token counts, estimated cost and duration.

## 3. CV review flow

1. HR uploads the CV (PDF/DOCX). Text is extracted **on the server**
   (`CvTextExtractor`); scanned images give “No readable text found”.
2. `UntrustedText::sanitize()` removes hidden characters, e-mail
   addresses, phone numbers and the applicant's **name**, redacts lines
   that state protected attributes (age, DOB, gender, religion, caste,
   marital status, disability, nationality, photo), and replaces
   instruction-like text (“ignore previous instructions”, role/score
   demands, chat markup, hidden channels, exfiltration attempts) with
   `[instruction-like text removed]`, recording flags.
3. The prompt contains the JD version, the rubric and the sanitised CV
   inside `<candidate_cv>` delimiters, with instructions to treat it as
   data, use job-related evidence only and never mention protected
   traits. The exact request produced by the test suite is in
   [evidence/16-ai-cv-review-exact-request.txt](evidence/16-ai-cv-review-exact-request.txt).
4. The model must reply in a fixed **JSON schema** (per-criterion scores
   or `null`, matched / unmet / unclear requirements with evidence quotes,
   possible-manipulation flag, optional questions).
5. The server **validates** the reply: every evidence quote must appear
   verbatim in the CV text; the overall fit score is **computed by the
   server** from the rubric weights (the model's own total is ignored);
   any reply that mentions a protected trait is **discarded** and stored
   as unavailable. Refusals, truncation, timeouts, auth/rate-limit errors
   are stored as `unavailable`/`failed` with the error and **no score**.
6. HR sees the review as “decision support”, can accept or override it
   with a reason, and makes the decision. Rejections and holds need a
   written note; there is no automatic rejection.

Stored result example: [evidence/17-ai-cv-review-stored-result.json](evidence/17-ai-cv-review-stored-result.json).

## 4. Provider configuration

Only the server-side **Anthropic** provider is implemented
(`AnthropicProvider`, official `anthropic-ai/sdk`). In `.env`:

```ini
ai.provider = 'anthropic'
ai.apiKey = 'sk-ant-…'        # read from .env only; never stored in the DB or shown
ai.model = ''                 # default claude-opus-5-5
ai.timeoutSeconds = 90
ai.maxRetries = 2
ai.effort = 'medium'          # low | medium | high
```

Model, timeout, retries and effort can also be changed on the AI
permissions page (audited). Then, per capability: enable it, allow
sharing with the provider, set a monthly cost ceiling above 0 and (for
payroll) choose the allow-listed employees. Admin → Diagnostics shows
whether the SDK is installed and a key is present (never the key).

> The live Anthropic API was **not** called during development (no key was
> available). The provider is exercised through a fake in the automated
> tests; verify once with your own key by running one CV review.

## 5. Service-token API (`/api/ai/…`)

For an external AI agent, an owner issues a **service token** on the AI
permissions page: choose capabilities, the record scope (candidate ids, or
employee ids + payroll periods), a purpose, a lifetime (1–72 h) and optionally a
maximum number of uses. The token (`dgrai_…`) is shown **once**; only its
SHA-256 hash is stored. Tokens can be revoked immediately. A token cannot
log in to the web app.

Send it as `Authorization: Bearer dgrai_…`. Only these routes exist:

| Method & path | Capability | Notes |
| --- | --- | --- |
| `GET /api/ai/recruitment/candidates/{id}/evaluation-input` | `ai.recruitment.evaluate_cv` | Returns a grant id, the sanitised CV, JD, rubric, the instructions and the output schema |
| `POST /api/ai/recruitment/candidates/{id}/evaluation` | `ai.recruitment.evaluate_cv` | Body `{grant_id, result, model}`; validated exactly like the in-app flow; stored as decision support |
| `POST /api/ai/recruitment/candidates/{id}/timeline-proposals` | `ai.recruitment.create_timeline_entry` | Creates a *proposed* event that HR must accept or reject |
| `GET /api/ai/payroll/summary?period=YYYY-MM` | `ai.payroll.read_approved_summary` | Period must be in the token's scope; finalized runs only; employees = token scope ∩ capability allowlist; five summary fields |
| `GET /api/ai/payroll/period-summary?period=YYYY-MM` | `ai.payroll.summarize_period` | Aggregate totals of one finalized period in the token's scope |

Every other path under `/api/ai/` — invoices, contacts, leads, bank,
salary edits, files, guessed paths, any method — returns **404** and is
logged as denied. Missing/expired/revoked/exhausted tokens get **401**.
No endpoint can change salaries, finalize payroll, move money, reveal
bank accounts or call CRM/billing.

## 6. What AI never receives

`billing.*`, `crm.*`, `bank.*` data; company CIF; raw employee bank
details; salaries of unrelated employees; Google Sheets credentials;
`.env`; backups; candidate names, e-mails or phone numbers; protected
attributes. Adding any of these would require a new, separately designed
and owner-approved capability.

## 7. Tests that prove the boundary

`tests/feature/RecruitmentAiTest.php`, `tests/feature/HrPayrollTest.php`
(payroll AI) and `tests/unit/UntrustedTextTest.php` — see
[07-TEST-REPORT.md](07-TEST-REPORT.md) §3.
