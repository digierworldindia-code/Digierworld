# Satya Moulds — official website

Static Bootstrap 5 website for **Satya Moulds**, a mould and tooling manufacturer
with units at IMT Manesar (Gurgaon) and IMT Bawal (Rewari).

No build step and no backend. Upload the contents of this folder to any web host,
shared hosting or cPanel account and it runs as-is.

## Stack

- HTML5, semantic sectioning (`header` / `nav` / `main` / `section` / `article` / `footer`)
- Bootstrap 5.3.3 via CDN, with Subresource Integrity hashes (verified against the published dist files)
- Bootstrap Icons 1.11.3
- Google Fonts: Archivo (headings) + Inter (body)
- One stylesheet (`assets/css/style.css`) and one script (`assets/js/main.js`); no jQuery, no framework

## Pages

| File | Page |
|---|---|
| `index.html` | Home |
| `about-us.html` | About Us |
| `pet-preform-moulds.html` | PET Preform Moulds |
| `hpdc-die-casting-dies.html` | HPDC Die Casting Dies |
| `plastic-injection-moulds.html` | Plastic Injection Moulds |
| `rubber-moulds.html` | Rubber Moulds |
| `jigs-fixtures.html` | Jigs, Fixtures & Precision Parts |
| `mould-design-development.html` | Mould Design & Development |
| `machinery.html` | Machinery & Infrastructure |
| `quality.html` | Quality |
| `industries.html` | Industries & Applications |
| `projects.html` | Project Portfolio |
| `contact.html` | Contact Us |

## Before going live — three things to do

### 1. Replace the placeholder images

Every image in `assets/images/` is a **generated placeholder**. Each one is
labelled on-screen with the exact filename it should be replaced by, so a real
photograph can be dropped in without touching any HTML — keep the filename and
the page picks it up.

- Page banners: `hero-*.jpg` — landscape, 1920 × 1080. The heading sits on the
  left, so keep the visually interesting part of the photo to the right.
- Capability and section photos: 1200 × 900 (4:3).
- Project photographs: `assets/images/projects/` — 1200 × 900 (4:3). The grid
  crops with `object-fit: cover`, so leave a little margin around the tool.
- Machine photographs: `assets/images/machinery/` — 1200 × 900 (4:3).
- `og-satya-moulds.jpg` (1200 × 630) is the image shown when a page is shared on
  WhatsApp, LinkedIn or Facebook.

Save photographs as progressive JPEG at around 70–80% quality to keep pages fast.

### 2. Point the enquiry form at a real endpoint

Open `assets/js/main.js` and set `formEndpoint` at the top:

```js
var SM = {
  formEndpoint: '',                       // <- put your form URL here
  enquiryEmail: 'toolroom@satyamoulds.com'
};
```

Any endpoint that accepts a multipart POST works (a PHP script on the hosting
account, Formspree, Basin, and so on). File uploads are passed through as the
`attachment` field.

**While it is left empty**, the form falls back to opening the visitor's email
client with the enquiry prefilled and addressed to the tool room — so the form
never silently loses an enquiry, but the visitor has to attach their drawing
themselves. Setting a real endpoint is strongly recommended.

### 3. Confirm the figures that need sign-off

Four numbers on `plastic-injection-moulds.html` come from the company profile and
are marked with an HTML comment in the source. Confirm them before publishing:

- Maximum mould size — 1200 × 850 × 650 mm
- Maximum mould weight — 6 tonnes
- Mould development capacity — 8–10 moulds per month
- Mould development lead time — 8–10 weeks

## Editing content

Contact details, addresses and phone numbers appear in the page footers and on
`contact.html`. Search and replace across the folder to change them:

- `+91-9650648852`, `+91-7838448595` (also in `tel:` links as `+919650648852`)
- `toolroom@satyamoulds.com`, `ranbeer@satyamoulds.com`

## Project portfolio

Project cards carry a `data-category` attribute; the filter buttons carry a
matching `data-filter`. Categories are `pet`, `hpdc`, `injection`, `rubber` and
`fixtures`.

To add a project, copy an existing `.project-item` block in `projects.html`, set
its category, name and image. Linking to `projects.html#hpdc` (or any category
key) opens the portfolio with that filter already applied.

Project names are recorded exactly as supplied by Satya Moulds and carry no
invented descriptions.

## SEO

Each page has its own title, meta description, canonical URL and Open Graph
tags, plus Organization and BreadcrumbList structured data. `sitemap.xml` and
`robots.txt` are included.

All URLs assume the site is served from `https://www.satyamoulds.com/`. If it is
hosted on a different domain or in a subfolder, update the `canonical`, `og:url`
and structured-data URLs, along with `sitemap.xml`.

No certifications, customers, tolerances, production figures or testimonials are
claimed anywhere on the site beyond what the company profile supports. Please
keep it that way — add a claim only when there is a document behind it.

## Accessibility & browser support

- One `h1` per page, ordered headings below it, and a skip-to-content link
- Descriptive `alt` text on every image; form fields all have visible labels
- Visible keyboard focus rings; the mobile menu is operable by keyboard
- Animations are disabled for visitors who set "reduce motion"
- Works in current Chrome, Firefox, Safari and Edge; images below the fold are lazy-loaded
