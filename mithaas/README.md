# MITHAAS — Sweets • Bakery • Restaurant

A premium brand website for **Mithaas**. Plain PHP with shared includes — no
framework, no database, no build step. Upload it to any PHP-enabled host.

## Stack

- **PHP 8** for page composition only — every page pulls one shared header and
  footer from `includes/`. There is no database and no backend logic.
- **Bootstrap 5.3.3** (CDN) — grid, navbar, offcanvas, modal, forms
- **Bootstrap Icons 1.11** (CDN)
- Google Fonts: **Cormorant Garamond** (display) + **Jost** (body)
- `assets/css/mithaas.css` — the brand theme layer, loaded after Bootstrap
- Vanilla JS, ~28 KB unminified, no jQuery

## Architecture

Shared markup lives in exactly one place. Change the footer once and all 11
pages follow.

```
includes/
    config.php      site URL, navigation, SEO defaults, helpers
    header.php      <head>, navigation, mobile drawer, sticky action bar
    footer.php      footer and the scripts every page loads
    page-hero.php   inner-page banner            — used by 9 pages
    cta-band.php    closing call-to-action band  — used by 4 pages
```

A page is then just its own content plus its SEO values:

```php
<?php
$pageTitle       = 'Menu | Mithaas Sweets, Bakery & Restaurant';
$metaDescription = 'The full Mithaas menu — …';
$breadcrumb      = ['Home' => 'index.php', 'Menu' => 'menu.php'];

require __DIR__ . '/includes/header.php';
?>
  <!-- page content -->
<?php require __DIR__ . '/includes/footer.php'; ?>
```

Every include is loaded with `__DIR__`, so paths hold no matter where a page
sits. Sections that appear on only one page stay in that page — the goal is a
single source of truth for what is genuinely shared, not a file per section.

The active navigation item is resolved server-side from the requested
filename, so it is correct before any JavaScript runs.

## Run it locally

**Option 1 — PHP's built-in server (recommended)**

```bash
cd mithaas
php -S localhost:8000
```

Or, for the same thing with a friendlier message if PHP is missing:

```bash
python3 serve.py          # -> http://localhost:8000
python3 serve.py 3000     # or pick your own port
```

Needs PHP 8 (`sudo apt install php-cli`, `brew install php`, or
windows.php.net). Stop it with Ctrl+C.

**Option 2 — upload it**

Copy the contents of `mithaas/` into your host's public folder
(`public_html`, `www` or `htdocs`). Any standard PHP host works — shared
hosting, cPanel, a VPS. Nothing to configure and nothing to install.

Opening `index.php` by double-clicking will **not** work: the browser cannot
run PHP. Use option 1 or 3 for that.

**Option 3 — the single-file offline build**

`mithaas-offline.html` is the whole site in one file: all 11 pages, with
Bootstrap, the icons and both typefaces embedded. It makes **zero** network
requests, so it works on a plane, on a locked-down machine, or emailed to
someone. Good for showing the design to a client. Regenerate it any time with
`tools/build-standalone.py` (see that file's header).

---

## Pages

| File | Purpose |
|---|---|
| `index.php` | Home — hero, story, favourites, sweets, bakery, restaurant, gifting, why, gallery, reviews, Instagram, location |
| `our-story.php` | Editorial brand story |
| `sweets.php` | Traditional, premium, regional and seasonal sweets |
| `bakery.php` | Cakes, pastries, cookies, breads, baked snacks |
| `restaurant.php` | North Indian, South Indian, snacks & street food, Chinese, thali, beverages |
| `menu.php` | **Interactive digital menu** — 11 categories, live search, deep links |
| `gallery.php` | Filterable masonry gallery with lightbox |
| `contact.php` | Call / WhatsApp / email / address / hours / map / enquiry form |
| `privacy-policy.php`, `terms.php` | Legal |
| `404.php` | Error state |

---

## ⚠️ Before this goes live — three things

### 1. Add the official logo

Put the **exact, unmodified** Mithaas logo file at:

```
assets/img/brand/mithaas-logo.png
```

**Easiest — no command line.** Open `tools/logo-installer.html` in any browser,
drag the logo onto it, and download the four files it produces. Everything runs
on your own machine; nothing is uploaded. Then drop those files into
`assets/img/brand/`.

**Or from a terminal**, which does the same thing in one step:

```bash
python3 tools/add-logo.py ~/Downloads/mithaas-logo.png
```

Either way the site also accepts the logo as `.png`, `.svg`, `.jpg`, `.jpeg`
or `.webp`, and under the name `logo.*` as well as `mithaas-logo.*` — so
whatever the file ends up being called, it will be found.

The logo has deliberately **not** been recreated, redrawn or substituted anywhere in this
build. Until the real file is present, the browser shows the image's alt text.
See `assets/img/README.md` for the favicon and social-card exports from the same artwork.

### 2. Fill in the business details

Everything the business owns lives in **one file**: `assets/js/config.js`.

```js
phone: null,          →  "+919999999999"
phoneDisplay: null,   →  "+91 99999 99999"
whatsapp: null,       →  "919999999999"
email: null,          →  "hello@example.com"
address: { ... },     →  the real address
hours: { ... },       →  { open: "08:30", close: "22:30" } per day
mapEmbed: null,       →  the src="…" from Google Maps → Share → Embed a map
social: { ... },      →  the real Instagram / Facebook URLs
```

**Nothing is invented.** Every field left as `null` renders an honest
"To be confirmed" state and disables the button rather than showing a fake
phone number or address. Open the browser console — it prints a checklist of
what is still missing.

### 3. Add prices (when you're ready)

Prices live in `assets/js/menu-data.js` on each item's `p` field, and are **hidden by
default**. The menu shows a discreet "Price on request" until you:

1. fill in `p:` for the items you want priced — a number (`p: 260`) or per variant
   (`p: { "250g": 260, "500g": 500, "1kg": 980 }`), and
2. set `showPrices: true` in `config.js`.

No prices have been assumed or carried over from any reference material.

---

## Adding photography

All images resolve from `assets/img/`. Any image file that is missing is replaced at runtime
by a branded ivory-and-gold plate carrying the dish name — so the site never shows a broken
image, and every photo you add upgrades it automatically.

Filenames, folders and crops are listed in **`assets/img/README.md`**.

## Editing the menu

`assets/js/menu-data.js` is a plain array. Each category has `id`, `name`, `short`, `note`,
`intro` and `items`. Each item:

```js
{ n: "Kaju Katli",                       // name
  d: "Thin diamonds of cashew…",         // one-line description
  v: ["250g", "500g", "1kg"],            // variants (optional)
  p: null,                               // price (optional)
  t: "signature",                        // "signature" | "seasonal" | "new" (optional)
  i: "kaju-katli" }                      // image slug
```

Add, remove or reorder freely — the menu page, the category chips, the search index and the
footer deep links all follow the data.

## Enquiry form routing

`config.js → formMode`:

- `"whatsapp"` *(default)* — opens WhatsApp with the enquiry pre-filled. No backend needed.
- `"email"` — opens the visitor's mail client.
- `"endpoint"` — POSTs JSON to `formEndpoint` (Formspree, Basin, or your own API).

## SEO

Unique `<title>` and meta description per page · canonical URLs · Open Graph and Twitter
cards · `BreadcrumbList` JSON-LD on every inner page · `Restaurant`/`Bakery`/`Store` JSON-LD
built from `config.js` (it only emits fields that are actually known) · semantic H1→H3
hierarchy · descriptive alt text · `robots.txt` · `sitemap.xml`.

Update the domain in `config.js → origin`, in `robots.txt`, in `sitemap.xml`, and in the
`SITE` constant of the page `<head>` blocks if the site moves off
`www.digierworld.com/mithaas`.

## Performance & accessibility

- Two CDN stylesheets, one CDN script bundle, three small local files. No jQuery.
- Every image is lazy-loaded with an explicit aspect ratio — no layout shift.
- Heroes use `fetchpriority="high"`; fonts preconnect and `display=swap`.
- Skip link, visible focus rings, labelled form fields with inline errors, `aria-current`
  on the active nav item, `aria-pressed` on filter chips, keyboard-navigable lightbox
  (arrow keys + Escape).
- `prefers-reduced-motion` disables every reveal, parallax and transition.
- Print stylesheet so the menu prints cleanly.
