# MITHAAS — Sweets • Bakery • Restaurant

A static, premium brand website for **Mithaas**. Pure HTML/CSS/JS on Bootstrap 5.3 —
no build step, no framework, no backend. Drop it on any host: GitHub Pages, Netlify,
cPanel, shared hosting.

## Stack

- HTML5, semantic and hand-written
- **Bootstrap 5.3.3** (CDN) — grid, navbar, offcanvas, modal, forms
- **Bootstrap Icons 1.11** (CDN)
- Google Fonts: **Cormorant Garamond** (display) + **Jost** (body)
- `assets/css/mithaas.css` — the brand theme layer, loaded after Bootstrap
- Vanilla JS, ~28 KB unminified, no jQuery

## Run it locally

**Option 1 — local server (recommended)**

```bash
cd mithaas
python3 serve.py          # -> http://localhost:8000
python3 serve.py 3000     # or pick your own port
```

No dependencies; it uses only the Python standard library. It serves the site's
own `404.html`, sets correct MIME types, and disables caching so a refresh
always shows your latest edit. Stop it with Ctrl+C.

Any other static server works just as well:

```bash
npx serve mithaas          # Node
php -S localhost:8000 -t mithaas
```

**Option 2 — just open the file**

Double-click `mithaas/index.html`. Everything works from `file://` — the menu,
gallery and forms included — because there is no build step and no backend.
You need an internet connection for this option, since Bootstrap and the fonts
load from a CDN.

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
| `index.html` | Home — hero, story, favourites, sweets, bakery, restaurant, gifting, why, gallery, reviews, Instagram, location |
| `our-story.html` | Editorial brand story |
| `sweets.html` | Traditional, premium, regional and seasonal sweets |
| `bakery.html` | Cakes, pastries, cookies, breads, baked snacks |
| `restaurant.html` | North Indian, South Indian, snacks & street food, Chinese, thali, beverages |
| `menu.html` | **Interactive digital menu** — 11 categories, live search, deep links |
| `gallery.html` | Filterable masonry gallery with lightbox |
| `contact.html` | Call / WhatsApp / email / address / hours / map / enquiry form |
| `privacy-policy.html`, `terms.html` | Legal |
| `404.html` | Error state |

---

## ⚠️ Before this goes live — three things

### 1. Add the official logo

Put the **exact, unmodified** Mithaas logo file at:

```
assets/img/brand/mithaas-logo.png
```

Or let the helper do it, which also generates the favicon, the apple-touch
icon and the social share card from the same artwork:

```bash
python3 tools/add-logo.py ~/Downloads/mithaas-logo.png
```

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
