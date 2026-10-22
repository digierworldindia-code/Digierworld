# DGR Office — UI theme and frontend asset policy

DGR Office has **one approved design system in two modes**: Dark (black
surfaces, white text) and Light (light-grey/white surfaces, near-black text),
both with a single gold accent. Each user picks **Light, Dark or System**
(follows the device) in the top bar. Every module (Dashboard, Billing,
Finance, CRM, HR, Payroll, Reports, Settings, Administration, sign-in and
error pages) uses the same design system. New modules must use it as it is.
They must not add new colours, themes, CDNs or frontend libraries.

Automated guards (`tests/unit/ThemeComplianceTest.php` and
`tests/unit/VendorAssetsTest.php`) fail the build when a screen or asset
breaks these rules.

---

## 1. Approved palettes (do not change)

Colours are defined **only** in `public/assets/css/dgr-theme.css` as CSS
custom properties: the dark set on `:root, [data-bs-theme="dark"]` and the
light set on `[data-bs-theme="light"]`. Views, `custom.css` and JavaScript
never contain colour values. They use the tokens below or the components in §4.

**Light mode** (same tokens, light values):

| Token | Light value | Note |
| --- | --- | --- |
| `--dgr-bg` | `#F8F9FA` | Page background |
| `--dgr-sidebar` / `--dgr-card` | `#FFFFFF` | Sidebar, cards |
| `--dgr-text` / `--dgr-text-2` / `--dgr-text-3` | `#171717` / `#52525B` / `#67676F` | Text |
| `--dgr-border` | `#E4E4E7` | Hairlines |
| `--dgr-input-border` | `#8C8C95` | Form controls (3:1 against their fill) |
| `--dgr-gold` | `#D4AF37` | Fills, lines, primary buttons (dark text on gold) |
| `--dgr-gold-text` | `#866514` | Gold used as **text** (links, icons): `#D4AF37` on white is only 2.1:1 |
| status | `#15803D` / `#A16207` / `#B91C1C` / `#1D4ED8` | success / warning / danger / info |

**Dark mode:**

| Token | Value | Use |
| --- | --- | --- |
| `--dgr-bg` | `#0B0B0D` | Page background |
| `--dgr-sidebar` | `#111114` | Sidebar, kanban columns, sticky action bar |
| `--dgr-card` | `#1A1A1E` | Cards, tables, panels, modals, menus |
| `--dgr-text` | `#FFFFFF` | Primary text |
| `--dgr-text-2` | `#A1A1AA` | Secondary text, labels, table headers |
| `--dgr-gold` | `#D4AF37` | The only accent: primary buttons, links, active menu, focus ring |
| `--dgr-inset` | `#141417` | Form inputs (sit slightly below the card) |
| `--dgr-raised` | `#222228` | Hover rows, raised chips |
| `--dgr-border` / `--dgr-border-strong` | `#303036` / `#3A3A42` | Hairlines, menu borders |
| `--dgr-input-border` | `#68686F` | Form controls (3:1 against their fill) |
| `--dgr-gold-text` | `#D4AF37` | Gold used as text (the accent itself on dark) |
| `--dgr-text-3` | `#8B8B94` | Tertiary text (footers, hints) |
| `--dgr-on-gold` | `#0B0B0D` | Text on gold buttons and badges |
| `--dgr-success` / `--dgr-warning` / `--dgr-danger` / `--dgr-info` | `#4ADE80` / `#FBBF24` / `#F87171` / `#7DB4F5` | Status only (paid, overdue, failed, informational), never decoration |
| `--dgr-chart-1` … `--dgr-chart-8` | gold, then neutrals of alternating lightness | Chart series (read by `DGR.chartTheme()`) |

Contrast is tested, not assumed. `ThemeComplianceTest` computes WCAG
contrast ratios and requires at least 4.5:1 (AA) for every text colour on every surface it
appears on. The same test requires text on gold and every status colour on
cards to reach 4.5:1.

Bootstrap follows the mode: `<html data-bs-theme="dark|light">`, and
`dgr-theme.css` maps Bootstrap's `--bs-*` variables to the tokens above in
both modes. Standard Bootstrap markup therefore renders in the DGR palette
without extra classes. `color-scheme` makes native date pickers, scrollbars
and select menus match the mode.

**How the mode is chosen and applied**

* The choice (light / dark / system) is saved in the user's profile
  (`user_profiles.preferences.theme`, via `POST /profile/theme`) and in the
  browser (`localStorage`), so it follows the user to other devices and the
  sign-in page remembers the device's last choice. Default: **system**.
* `public/assets/js/theme-init.js` is loaded in `<head>` right after the
  stylesheets (a small blocking script, allowed by the CSP because it is a
  file): it sets `data-bs-theme` before the page is painted, so there is no
  flash of the wrong theme, and follows the operating system live when the
  choice is *system*.
* Switching dispatches `dgr:themechange`; charts (`dashboard.js`,
  `crm-performance.js`) redraw with the new token colours.
* `tests/unit/ThemeComplianceTest.php` checks both palettes' exact values
  and WCAG AA contrast for every text token on every surface in both
  modes; `tests/feature/ThemePreferenceTest.php` checks saving and
  applying the choice.

**Printed documents stay white.** Invoice, cash-memo, credit-note, receipt and
payslip PDFs (`app/Views/pdf/`) are printed on paper and keep their own white
templates in either mode; the logo is never recoloured. The `@media print`
block in `dgr-theme.css` prints any screen (reports, digests)
black-on-white in both modes.

## 2. Frontend files

```
public/assets/
  vendor/                         third-party, official, unmodified (never edit)
    MANIFEST.json                 version, registry tarball, sha512, sha384 per file
    bootstrap/                    Bootstrap 5.3.8: bootstrap.min.css, bootstrap.bundle.min.js, LICENSE
    bootstrap-icons/              Bootstrap Icons 1.13.2: bootstrap-icons.min.css, fonts/*.woff2|woff, LICENSE
    chartjs/                      Chart.js 4.5.1: chart.umd.min.js, LICENSE.md
  css/
    dgr-theme.css                 the design system: tokens + every shared component
    custom.css                    module components (invoice editor, kanban, calendar, …), tokens only
  js/
    app.js                        shared vanilla JS (DGR namespace): menus, search, toasts, confirm, charts theme
    <module>.js                   one small vanilla script per screen that needs one
  img/
    favicon.png
```

Third-party files live under `vendor/<package>/` **byte-for-byte as
published**, with their licence and an entry in `MANIFEST.json`. Our own CSS
and JS live in `css/` and `js/`. This split lets anyone verify the vendor
files independently and keeps our code apart from library code. The
company's own logo, signature and stamp are not public files. They are
uploaded in Settings → Branding, stored outside the web root and served
through `brand/logo` exactly as uploaded.

**What a page loads.** `app/Views/partials/theme_head.php` is the one place
stylesheets are linked, always in this order: Bootstrap → Bootstrap Icons →
`dgr-theme.css` → `custom.css`. The layout loads
`bootstrap.bundle.min.js` (which includes Popper) and `app.js` once. A
screen adds its own module script in its `scripts` section. Chart.js is
loaded only on the two screens with charts (Dashboard, CRM Performance).
Every URL goes through the `asset()` helper, which appends a content hash
(`?v=…`) so browsers cache files indefinitely and fetch them again only when
the file changes.

Approximate sizes before gzip: Bootstrap CSS 232 KB, Bootstrap JS bundle
80 KB, icon CSS 87 KB plus the 134 KB woff2 font, `dgr-theme.css` 31 KB,
`custom.css` 8 KB, `app.js` 13 KB, and Chart.js 209 KB on chart pages only.
No web fonts are downloaded: text uses the system UI font stack.

### Libraries and why each one is needed

| Library | Version | Needed for | Not used |
| --- | --- | --- | --- |
| Bootstrap | 5.3.8 | Grid, forms, tables, dropdowns, modals, offcanvas sidebar, toasts, dark colour mode | — |
| Bootstrap Icons | 1.13.2 | Menu, button and status icons (font) | — |
| Chart.js | 4.5.1 | Dashboard and CRM performance charts | — |
| jQuery | — | — | **Not shipped.** Bootstrap 5 does not need it, and all DGR scripts are vanilla JavaScript. (`expense-form.js` defines a local `$` as a `querySelector` shortcut; that is not jQuery.) |
| React / Vue / Angular, UI kits, admin templates, date-picker or table plugins | — | — | **Not used.** Native `<input type="date">`, server-side pagination and Bootstrap components are enough. |

## 3. Security rules

* **No CDN at runtime.** Every script, stylesheet and font is served from the
  application's own origin. No page contacts a third-party host to render.
* **Content-Security-Policy** (`app/Filters/SecurityHeadersFilter.php`):

  ```
  default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:;
  font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self';
  base-uri 'self'; object-src 'none'
  ```

  There is no `unsafe-inline` for scripts or styles. Views therefore never
  use `<script>` blocks, `onclick=` handlers, `<style>` blocks or `style="…"`
  attributes. Behaviour goes in `js/`, appearance in `css/`. (Because this
  CSP has no `unsafe-inline`, a Subresource Integrity attribute would add
  nothing. SRI would only be required if an approved external CDN ever became
  unavoidable, and none is used.)
* **Pinned, verified, audited.** `MANIFEST.json` pins each library to an exact
  version and records the official npm registry tarball, its sha512 integrity
  and the sha384 of every shipped file.
  * `tests/unit/VendorAssetsTest.php` runs offline with the normal test
    suite. It checks the pinned versions, the official tarball URLs and every
    file hash, and it fails if an unlisted file appears in `vendor/`.
  * `scripts/verify-vendor-assets.sh` runs online. It downloads each tarball
    from the registry, checks it against the registry's published sha512,
    requires every shipped file to be byte-identical to the file inside the
    tarball, and runs `npm audit`. Last run, 2026-10-20: all files identical,
    **0 known vulnerabilities**.
* **No duplicates.** Each library is loaded once, from one path. There are no
  source maps, no unminified copies and no second icon set.

### Upgrading a library (reviewed change only)

1. Pick the new **exact** version, read its release notes and check the
   GitHub advisory database for it.
2. Download the official tarball:
   `curl -fsSLO https://registry.npmjs.org/<pkg>/-/<pkg>-<ver>.tgz`. Then
   compare `openssl dgst -sha512 -binary <file>.tgz | openssl base64 -A` with
   `dist.integrity` from `https://registry.npmjs.org/<pkg>/<ver>`.
3. Copy only the minified production files and the licence into
   `public/assets/vendor/<package>/`, unmodified.
4. Update `MANIFEST.json`: version, tarball, `tarballIntegrity` and each
   file's `sha384-$(openssl dgst -sha384 -binary <file> | openssl base64 -A)`.
   Update the expected versions in `VendorAssetsTest`.
5. Run `scripts/verify-vendor-assets.sh` and `vendor/bin/phpunit`, and check
   the screens with the browser console open: no CSP or console errors.

## 4. Components

All components below are defined in `dgr-theme.css` (shared) or
`custom.css` (module-specific). Use them as they are. If a screen needs
something new, add a component class built from tokens. Do not use one-off
colours.

| Component | Markup / classes |
| --- | --- |
| Dark sidebar | `.app-sidebar` (offcanvas below 992 px), `.sidebar-brand` (logo `.brand-logo` or `.brand-text`), `.sidebar-search`, `.nav-section-toggle`, `.nav-sub-link`, `.nav-link-single`; the active item gets a gold inset bar and `aria-current="page"` |
| Top navigation | `.app-topbar` with `.topbar-search` (global search + `.search-suggest`), `.topbar-actions` (quick-create `btn-primary`, notifications `.notif-menu` / `.notif-item.unread` / `.notif-dot`, profile `.btn-profile` + `.avatar`) |
| Page frame | `.page-head` → `.breadcrumb`, `.page-title`, `.page-actions`; `.sticky-actions` for save/cancel bars on long forms |
| Dashboard cards | `.kpi-card` → `.kpi-label` (+ `.help-tip`), `.kpi-value`, `.kpi-sub`; `.kpi-card.accent` for a gold top edge; `.card-section-title` for group headings |
| Panels | `.card` / `.card-header` / `.card-body`; `.table-card` for a card that wraps a table (accepts `.card-header` too); `.empty-state` for "nothing here yet" |
| Forms | Standard `.form-label`, `.form-control`, `.form-select`, `.form-check-input`, `.form-switch`, `.input-group-text`, `.invalid-feedback`; required fields add `.required` to the label; inputs sit on `--dgr-inset` with a gold focus ring |
| Search fields | `.form-control` with `type="search"`; global search in `.topbar-search` |
| Filters | `.filter-bar` above a list: a row of `.form-control-sm` / `.form-select-sm` and a `btn-primary btn-sm` "Filter"; tabs above it use `.nav-tabs` |
| Date pickers | Native `<input type="date">` / `type="month"` / `type="datetime-local"`, themed via `color-scheme: dark` (no plugin) |
| Data tables | `.table-card > .table-responsive > .table.table-hover.align-middle`; numbers `.num` (right-aligned, tabular figures), codes `.mono`, masked bank/ID numbers `.masked` |
| Pagination | `partials/pager.php` (the CI4 pager template) renders Bootstrap `.pagination.pagination-sm`, themed |
| Status | `status_badge()` / `stage_tone()` helpers → `.badge.badge-success|warning|danger|info|gold|neutral` (soft tinted badges); `.badge-solid-gold` for the rare emphasis badge |
| Buttons | `btn-primary` = gold (main action, at most one per area), `btn-outline-secondary` = neutral secondary, `btn-outline-danger` / `btn-danger` = destructive, `btn-success` = record payment / approve, `btn-link` = gold text, `.btn-icon` for icon-only buttons (always with `aria-label`) |
| Modals | Bootstrap `.modal` (themed card surface); `DGR.confirm()` in `app.js` for confirmations |
| Notifications | Toasts via `DGR.toast(message, 'success'|'danger'|'warning'|'info')` → `.toast.toast-<tone>`; flash messages render as `.alert-success|danger|warning|info` |
| Invoice editor | `custom.css`: `.gst-cards` / `.gst-card(.selected|.disabled)`, `.tax-advice(.warn|.block)`, `.line-items`, `.totals-box` (`.row-t`, `.grand`) |
| Reports | Report cards are `.card` links in a grid; report tables are data tables; charts sit in `.chart-box` (`.tall` for 320 px) |
| Role & permission controls | `.perm-matrix` (`.pk` permission key column, gold `.bi-check-lg`), `.perm-danger` for dangerous permissions |
| Boards and timelines | `.kanban` / `.kanban-col` / `.kanban-card`, `.stage-mark.stage-mark-<tone>`, `.timeline` |
| Calendar | `.cal-grid`, `.cal-cell(.cal-today|.cal-off|.cal-empty)`, `.cal-chip`, `.att-grid` |
| Loading | `.skeleton` (shimmer is switched off for `prefers-reduced-motion`) |

**Charts.** Call `DGR.chartTheme()` from `app.js` before creating a chart.
It reads the tokens from CSS, sets Chart.js defaults (font, grid, legend and
tooltip colours) and returns `{ gold, text, text2, border, card, series[8] }`.
Charts use `series` in order: gold first, then neutrals that alternate in
lightness so neighbouring slices stay distinguishable.

**Layout without inline styles.** Because `style=""` is blocked by the CSP,
the few fixed sizes a screen needs (a column width, a max-width for a narrow
form) are small utility classes at the end of `custom.css`. They are named
after the value: `u-w-90px`, `u-w-12p` (percent), `u-mxw-720px` (max-width),
`u-mnw-220px` (min-width), `u-mxh-60vh` (max-height), `u-mnh-60px`,
`u-h-260px`, `u-pos-sticky`, `u-top-76px`, `u-ws-pre-line`. Prefer
Bootstrap's own utilities (`w-100`, `text-end`, `mb-3`, `d-flex`, …). Add a
`u-*` class only when no Bootstrap utility fits, and use the same naming.

## 5. Rules for every new screen or module

1. Extend `layouts/app` (or `auth/layout` for signed-out pages). Never write
   a standalone `<html>` page with its own stylesheet links.
2. Use only the components above and the tokens in `dgr-theme.css`. Do not
   use `bg-white`, `bg-light`, `bg-dark`, `text-dark`, `btn-dark`,
   `btn-light`, `table-light`, `text-bg-*`, solid `bg-primary|success|…`
   badges or hex colours in views. The guard test lists any it finds.
3. No inline `<script>`, `on…=` handlers, `<style>` or `style=""`. Put
   behaviour in `public/assets/js/<module>.js` (vanilla JavaScript, no
   jQuery) and appearance in `custom.css`.
4. Load every file through `asset()`. Never link to an external URL.
5. A new library needs a clear technical reason that Bootstrap and vanilla
   JS cannot meet, an owner-approved review, and the upgrade procedure in §3
   (official tarball, MANIFEST entry, hashes, audit, tests).
6. Check the screen at 1366 px and at 390 px (phone), with the browser
   console open: no CSP or console errors, and tables scroll horizontally
   instead of overflowing the page.

Run `vendor/bin/phpunit tests/unit/ThemeComplianceTest.php
tests/unit/VendorAssetsTest.php` for a quick check while working on views.
