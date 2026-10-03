# 6. User roles and permissions

## 6.1 Roles

| Role | Code | Purpose |
|---|---|---|
| Super Admin | `SUPER_ADMIN` | Complete access, including users, roles and system settings. Still bound by workflow and segregation-of-duties rules |
| QA Admin | `QA_ADMIN` | Owns specifications and templates, gauges, final QA approval, revisions, reports and sync monitoring |
| Quality Engineer | `QUALITY_ENGINEER` | Inspects, verifies IPR rounds, performs the Quality Verification stage, records calibrations |
| Production Engineer | `PRODUCTION_ENGINEER` | Performs the Production Verification stage, views reports |
| Operator | `OPERATOR` | Creates and submits inspection reports only |
| Viewer / Management | `VIEWER` | Read-only dashboards, reports and printouts |

Each user has **one role**. Extra combinations are created as custom roles by the Super Admin; the six system roles
cannot be deleted. Permissions are a fixed catalogue checked in code. The matrix below is the seeded default and
is editable from **Admin → Roles**, except the Super Admin row.

## 6.2 Permission matrix (default)

✓ = granted · – = not granted

| Permission | Super Admin | QA Admin | Quality Eng. | Production Eng. | Operator | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| **Dashboard** | | | | | | |
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| **Inspections** | | | | | | |
| `inspection.create` – start, edit own drafts / returned reports | ✓ | ✓ | ✓ | – | ✓ | – |
| `inspection.submit` – submit (operator signature) | ✓ | ✓ | ✓ | – | ✓ | – |
| `inspection.view_own` | ✓ | ✓ | ✓ | – | ✓ | – |
| `inspection.view_all` | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| `inspection.cancel_own` – cancel own unsubmitted draft | ✓ | ✓ | ✓ | – | ✓ | – |
| `inspection.cancel_any` | ✓ | ✓ | – | – | – | – |
| `inspection.round_verify` – IPR "Inspected By Quality Engineer" | ✓ | ✓ | ✓ | – | – | – |
| `inspection.round_reopen` – reopen a signed IPR round | ✓ | ✓ | – | – | – | – |
| `inspection.verify_production` – Production Verification | ✓ | – | – | ✓ | – | – |
| `inspection.verify_quality` – Quality Verification | ✓ | ✓ | ✓ | – | – | – |
| `inspection.approve_qa` – QA Approval (final) | ✓ | ✓ | – | – | – | – |
| `inspection.revise` – correction revision of an approved report | ✓ | ✓ | – | – | – | – |
| `inspection.print` – print / PDF of reports the user can see | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Templates** | | | | | | |
| `template.view` | ✓ | ✓ | ✓ | ✓ | – | – |
| `template.manage` – drafts, sections, parameters, mapping | ✓ | ✓ | – | – | – | – |
| `template.publish` – publish / retire versions | ✓ | ✓ | – | – | – | – |
| **Master data** | | | | | | |
| `master.view` | ✓ | ✓ | ✓ | ✓ | – | – |
| `master.machines` | ✓ | ✓ | – | – | – | – |
| `master.parts` | ✓ | ✓ | – | – | – | – |
| `master.employees` – employees and departments | ✓ | – | – | – | – | – |
| `master.shifts` | ✓ | – | – | – | – | – |
| `master.library` – parameter library, units, methods, gauge types | ✓ | ✓ | – | – | – | – |
| **Gauges** | | | | | | |
| `gauge.view` | ✓ | ✓ | ✓ | ✓ | – | – |
| `gauge.manage` | ✓ | ✓ | – | – | – | – |
| `gauge.calibrate` – record calibration, upload certificate | ✓ | ✓ | ✓ | – | – | – |
| **Reports** | | | | | | |
| `report.view` | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| `report.export` | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| **Administration** | | | | | | |
| `user.manage` | ✓ | – | – | – | – | – |
| `role.manage` | ✓ | – | – | – | – | – |
| `setting.manage` | ✓ | – | – | – | – | – |
| `audit.view` | ✓ | ✓ | – | – | – | – |
| `loginlog.view` | ✓ | – | – | – | – | – |
| `sync.view` | ✓ | ✓ | – | – | – | – |
| `sync.manage` – retry / re-queue sync jobs | ✓ | ✓ | – | – | – | – |

Operators pick gauges inside the inspection form without `gauge.view`. The lookup endpoint is covered by
`inspection.create` and only returns gauges of the type the parameter requires, with their calibration status.

## 6.3 Record-level rules (data scope)

| Rule | Detail |
|---|---|
| Own reports | `view_own` = reports the user created or submitted, plus IPR day sheets where they signed a round |
| Shared IPR day sheet | While an IPR sheet is DRAFT, any user with `inspection.create` can open it to add **their own** rounds. Rounds signed by someone else are read-only to them |
| Drafts | Only the creator edits a DRAFT. Only the originator edits a RETURNED report. Nobody edits any other status (also enforced by DB triggers) |
| Approval inbox | Shows only reports waiting at a stage the user holds a permission for, excluding reports the user may not sign (see 6.4) |
| Printing / export | Limited to reports the user may view. Every print, PDF and export is written to the audit trail |
| Templates | Operators never see LSL/USL as editable. Limits come only from the published template version, and any LSL/USL sent by a client is ignored |

## 6.4 Segregation of duties

These are hard rules in `WorkflowService`. They apply to the Super Admin too.

1. **Nobody verifies or approves a report they submitted.**
2. With `workflow.distinct_signers` on (default), one person signs **at most one stage** of a report (submission, production, quality, QA).
3. On an IPR round the **QE signature must come from a different person** than the operator signature (also a DB CHECK).
4. A user cannot change their own role or permissions, and only a Super Admin can assign the Super Admin role.
5. Approval signatures require **password re-entry** (`workflow.reauth_on_sign`, default on). A shared, logged-in tablet therefore cannot be used to sign as someone else.

## 6.5 Where authorization is enforced

| Layer | Mechanism |
|---|---|
| Route | `PermissionFilter`, for example `['filter' => 'permission:inspection.approve_qa']` → 403 page or JSON |
| Service | `AuthorizationService::authorize($user, $permission, $record)`: ownership, workflow stage, segregation of duties, record locks |
| Database | `qms_app` cannot UPDATE/DELETE evidence tables, cannot DELETE reports, cannot run DDL; triggers freeze submitted and approved data |
| UI | Buttons and menus the user may not use are not rendered. This is cosmetic only and never the control |
| Monitoring | Every denied attempt is written to `audit_logs` (`ACCESS_DENIED`), so repeated probing is visible |

Permission sets are loaded from `role_permissions` on every request (cached only for that request), so a matrix
change applies on the user's next click without logging anyone out. Changing a user's role, disabling the account
or resetting the password rotates that user's `security_stamp`, which ends all of their open sessions.
