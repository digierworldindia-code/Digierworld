# 04 — Permissions matrix

Access is decided in three layers. All three are configuration, not code:

1. **Staff roles**: Shield groups and permissions in `app/Config/AuthGroups.php`, enforced with `permission:` route filters and permission-aware menus.
2. **Marketplace roles**: company type plus verification status plus plan entitlements (`plan_entitlements`, editable in Admin → Membership plans), enforced by `EntitlementService`, the `entitled:` filter and `VisibilityService`.
3. **Company employees**: member role plus granular permissions (`company_member_permissions`), enforced by the `member:` filter.

## 1. Staff roles (generated from `AuthGroups.php` with `php tools/permissions_matrix.php`)

| Permission | Super Admin | Verification Officer | Procurement Manager | Inspection Manager | Logistics Manager | Finance Manager | Account Manager | Customer Support Executive |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `admin.access` — Access the admin panel | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `users.view` — View user accounts | ✔ |  |  |  |  |  |  |  |
| `users.manage` — Activate/deactivate users and reset access | ✔ |  |  |  |  |  |  |  |
| `staff.manage` — Create staff users and assign roles | ✔ |  |  |  |  |  |  |  |
| `companies.view` — View buyer and supplier companies | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `companies.manage` — Suspend/reactivate companies, assign account managers | ✔ |  |  |  |  |  |  |  |
| `verification.view` — View verification applications | ✔ | ✔ |  |  |  |  | ✔ |  |
| `verification.review` — Review verification stages (KYC, compliance, risk, quality, audit, sanctions) | ✔ | ✔ |  |  |  |  |  |  |
| `verification.approve` — Give final management approval / rejection | ✔ |  |  |  |  |  |  |  |
| `kyc.sensitive` — View decrypted bank and identity numbers | ✔ | ✔ |  |  |  |  |  |  |
| `avl.manage` — Manage the Approved Vendor List | ✔ | ✔ | ✔ |  |  |  |  |  |
| `catalog.manage` — Manage categories and brands | ✔ |  | ✔ |  |  |  |  |  |
| `products.review` — Approve/reject product listings | ✔ |  | ✔ |  |  |  |  |  |
| `inventory.manage` — View and adjust any supplier inventory | ✔ |  | ✔ |  |  |  |  |  |
| `imports.manage` — Import inventory or supplier master data on behalf of suppliers | ✔ |  | ✔ |  |  |  |  |  |
| `rfq.view` — View RFQs and quotations | ✔ |  | ✔ |  |  |  | ✔ |  |
| `rfq.manage` — Review, match and distribute RFQs | ✔ |  | ✔ |  |  |  |  |  |
| `orders.view` — View orders | ✔ |  | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `orders.manage` — Update order status | ✔ |  | ✔ |  |  |  |  |  |
| `inspection.manage` — Manage inspection requests and reports | ✔ |  |  | ✔ |  |  |  |  |
| `logistics.manage` — Manage logistics requests and shipments | ✔ |  |  |  | ✔ |  |  |  |
| `payments.view` — View invoices and payments | ✔ |  |  |  |  | ✔ |  |  |
| `payments.record` — Record manual payments and manage gateway settings | ✔ |  |  |  |  | ✔ |  |  |
| `memberships.manage` — Manage membership plans and entitlements | ✔ |  |  |  |  | ✔ |  |  |
| `subscriptions.manage` — Manage supplier subscriptions | ✔ |  |  |  |  | ✔ |  |  |
| `performance.manage` — Recalculate supplier performance and buyer scores | ✔ |  | ✔ |  |  |  | ✔ |  |
| `disputes.view` — View disputes | ✔ |  |  |  |  |  |  | ✔ |
| `disputes.manage` — Assign and resolve disputes | ✔ |  |  |  |  |  |  | ✔ |
| `reports.view` — View reports and analytics | ✔ | ✔ | ✔ |  |  | ✔ | ✔ |  |
| `reports.export` — Export reports to CSV/Excel | ✔ |  |  |  |  | ✔ |  |  |
| `cms.manage` — Manage CMS pages | ✔ |  |  |  |  |  |  |  |
| `seo.manage` — Manage SEO settings | ✔ |  |  |  |  |  |  |  |
| `notifications.manage` — View email outbox and notification settings | ✔ |  |  |  |  |  |  |  |
| `settings.manage` — Manage platform settings and business rules | ✔ |  |  |  |  |  |  |  |
| `audit.view` — View audit logs | ✔ |  |  |  |  |  |  |  |
| `leads.view` — View contact form leads | ✔ |  |  |  |  |  |  | ✔ |

Staff accounts are created by a Super Admin (Admin → Staff & roles) or from the CLI (`php spark bearingcave:create-admin`). Public registration never creates staff.

## 2. Marketplace roles (plan entitlements as seeded; admin can change any cell)

| Capability | Guest | Unverified buyer (`buyer_standard`) | Verified buyer (`buyer_verified`) | Unverified supplier (`supplier_free`) | Verified supplier (`supplier_verified`) |
|---|:-:|:-:|:-:|:-:|:-:|
| Browse public listings | ✔ (price per `catalog.guest_can_see_prices`, off by default) | ✔ | ✔ | ✔ | ✔ |
| Verified-buyer-only listings | | | ✔ only after staff with `verification.approve` grant access (`restricted_inventory_access` plus a per-company flag) | | |
| Submit RFQs (`rfq_submission`) | | ✔ (max 5 open, `max_open_rfqs`) | ✔ (unlimited) | | |
| Advanced comparison | | | ✔ | | |
| Consolidation services | | | ✔ | | |
| Verified Buyer badge (`buyer_badge`) | | | ✔ | | |
| Search and engagement analytics (`buyer_analytics`) | | | ✔ | | |
| Cart, orders, inspection, logistics, disputes | | ✔ | ✔ | ✔ (as seller) | ✔ (as seller) |
| List inventory, lot pricing, stock | | | | ✔ | ✔ |
| Receive direct enquiries (`direct_enquiries`) | | | | ✔ | ✔ |
| Per-piece pricing | | | | | ✔ |
| Verified Supplier badge, public profile, public contact | | | | | ✔ (each optional for the supplier) |
| Priority ranking | | | | | ✔ (boost 50) |
| Premium RFQ leads and bidding | | | | | ✔ |
| Country visibility control | | | | | ✔ |
| Bulk CSV/Excel import | | | | | ✔ |
| Advanced inventory, analytics, demand insights | | | | | ✔ |
| Dedicated account manager | | | | | ✔ |
| Direct (supplier-arranged) logistics | | | | ✔ platform-managed only | ✔ |

"Verified" requires **both** company verification by staff **and** an active subscription to the verified plan (for buyers the verified plan is free: it applies automatically once the company is verified, with no subscription). Suspended companies fall back to the default plan.

## 3. Company employees

The owner and company admins hold every permission. Members get only what is ticked in Account → Team:

| Key | Allows |
|---|---|
| `company.profile` | Edit company profile |
| `company.members` | Manage team members |
| `company.verification` | Manage verification documents |
| `company.billing` | View membership, invoices and payments |
| `inventory.manage` | Manage inventory and imports (suppliers) |
| `rfq.manage` | Create RFQs / submit quotations |
| `orders.manage` | Place, confirm and manage orders |
| `services.manage` | Request inspection and logistics services |
| `disputes.manage` | Raise and respond to disputes |
