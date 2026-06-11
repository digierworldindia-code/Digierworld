# Digie 'R' World — Official Website

Premium static website for **Digie 'R' World** (www.digierworld.com), a development & digital marketing agency in Bhiwadi, Rajasthan.

> Think Digital. Think Digie R World.

## Stack

Pure static — no build step, no framework, host anywhere (any shared hosting, Netlify, GitHub Pages, cPanel):

- HTML5 + CSS3
- Bootstrap 5.3 (CDN) + Bootstrap Icons
- jQuery 3.7 (CDN) + vanilla JS
- Google Fonts: Cormorant Garamond + Montserrat

## Pages

| File | Purpose |
|---|---|
| `index.html` | Home — hero, services, process, industries, packages, **free website audit tool**, testimonials, FAQ |
| `about.html` | Story, mission/vision, why choose us |
| `services.html` | All 20+ services with anchor links (e.g. `services.html#seo`) |
| `packages.html` | Social media plans (₹15k/25k/35k) + comparison table + T&C |
| `portfolio.html` | Software products + delivery stats + client wall |
| `case-studies.html` | Industry growth stories (hospital, school, real estate, restaurant) |
| `blog.html` | 6 full articles (inline expandable) |
| `contact.html` | Contact form, map, click-to-call/WhatsApp/email |

## Built-in lead generation

- **Website audit tool** (`assets/js/audit.js`) — instant heuristic scan, captures lead, routes report to WhatsApp/email
- **Digie Assistant chatbot** (`assets/js/chatbot.js`) — answers FAQs, suggests packages, captures name/phone/need, routes to WhatsApp
- **All forms** open WhatsApp with the enquiry prefilled (no backend required); leads also mirror to visitor `localStorage` (`drw_leads`)
- Floating WhatsApp button + floating social bar on every page

## Editing business details

All contact numbers, email and **social media links** live in one place: the `window.DRW` config at the top of `assets/js/main.js`. Set any social URL to `null` to hide its icon site-wide (YouTube and X are currently hidden until those channels exist).

## SEO / AI discovery

- Unique titles, meta descriptions, canonical + Open Graph on every page
- JSON-LD: LocalBusiness, Service offers, FAQPage, Blog, BreadcrumbList
- `robots.txt` (AI crawlers explicitly allowed) + `sitemap.xml`

## Replacing placeholders with real data

- **Client logos**: drop files into `assets/images/clients/` and activate the commented slider in `portfolio.html`
- **Testimonials / case-study numbers**: swap the representative entries in `index.html` and `case-studies.html` with real client quotes and metrics when available
