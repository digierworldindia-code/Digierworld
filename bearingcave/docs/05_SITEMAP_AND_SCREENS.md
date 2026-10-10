# 05 — Sitemap & screen list

All routes are defined explicitly in `app/Config/Routes.php`; auto-routing is disabled.

## Public website

| URL | Screen | Notes |
|---|---|---|
| `/` | Home | Hero search, live counts from the database, categories, how it works, membership teaser |
| `/marketplace` | Search and listing | Filters: query, category, brand, condition, country, inventory age, verified supplier, price, quantity, in stock, bore/OD/width, make/model/year, specification. Has sorting and pagination. Pages with queries are marked `noindex` |
| `/category/{slug}`, `/brand/{slug}` | Category and brand listings | |
| `/product/{slug}` | Product detail | Gallery, specifications, part numbers with match notes, stock, inventory age, price or request quote, quantity, cart, RFQ, comparison, other suppliers, inspection/logistics links, documents permitted for the viewer, JSON-LD |
| `/compare` | Comparison | Up to `catalog.max_compare` items. Advanced columns for verified buyers. `noindex` |
| `/suppliers`, `/suppliers/{slug}` | Supplier directory and profile | Only verified suppliers that enabled a public profile |
| `/membership` | Plans | Read from `membership_plans` |
| `/how-it-works`, `/for-buyers`, `/for-suppliers`, `/services/inspection`, `/services/logistics`, `/trust-and-verification`, `/about` | Content pages | |
| `/page/{slug}` | CMS pages | Terms, privacy and similar. Legal drafts are marked *Pending approval* |
| `/contact` | Contact form | Stored in `contact_leads`, rate-limited |
| `/register`, `/register/buyer`, `/register/supplier` | Registration | Creates the company and its owner user |
| `/login`, `/login/magic-link`, `/logout` | Shield auth | |
| `/sitemap.xml`, `/robots.txt` | SEO | The sitemap lists only indexable public listings. Robots disallows everything while `seo.indexing_enabled=0` |

## Buyer workspace (`/buyer/...`)

Dashboard · Marketplace search · Saved inventory · Comparison · RFQs and quotations (list, new, detail with quotation comparison, accept/reject, messages) · Enquiries · Cart / RFQ basket (to order or to RFQ) · Checkout · Orders (list and detail with status history, invoices, payments) · Invoices and payments · Inspection (list, new, detail) · Logistics (list, new, detail and tracking) · Disputes (list, new, detail) · Company profile · Verification · Documents · Team · Notifications · Account settings (password, API tokens).

## Supplier workspace (`/supplier/...`)

Dashboard (KPIs, stock ageing) · Analytics and performance 🔒 · Inventory list (filters, export) · Add/edit inventory (prices, tiers, lots, specifications, cross references, fitments, images, documents, countries) · Bulk upload 🔒 · Country visibility 🔒 · Product enquiries · RFQ leads and bidding 🔒 · My quotations 🔒 · Orders · Logistics · Inspection · Disputes · Company profile · Verification · Membership · Invoices and payments · Documents · Account manager 🔒 · Team · Notifications · Account settings.

🔒 = depends on plan entitlements. If the plan lacks the feature, the menu item shows a lock and leads to Membership.

## Admin panel (`/admin/...`)

Dashboard (KPIs) · Reports and analytics (15 reports, CSV/XLSX export) · Performance scores · Users · Buyers · Suppliers · Staff and roles · Verification queue (application with tabs for each module) · Approved Vendor List · Supplier master data (86-field directory, import) · Product review · Categories · Brands · Inventory movements · Imports (on behalf of suppliers) · RFQs and matching · Quotations / bids · Orders · Inspection · Logistics · Disputes · Invoices · Payments and escrow (gateway events) · Membership plans and entitlements · Subscriptions · CMS pages · SEO settings · Email outbox · Platform settings · Contact leads · Audit logs.

Each menu item appears only when the staff member holds the matching permission (see `04_PERMISSIONS_MATRIX.md`).

## Shared

`/dashboard` sends each user to their own workspace. `/notifications` · `/documents/{uuid}` streams a private document after an access check · `/account` covers profile, password and API tokens.

## Screenshots

`node tests/e2e/responsive.mjs` writes screenshots of every screen above at 375, 768 and 1366 px to `build/e2e/`.
