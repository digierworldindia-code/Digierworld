# Dev Engineering — Website

Static website for **Dev Engineering Works** — industrial trolleys, MS pallets and bins,
storage rack systems, office storage, UPS battery racks, MS fabrication and toolroom work.
Units in Gurgaon, Haryana (since 2012) and Ahmedabad, Gujarat (since 2019).

## Stack

HTML5 · Bootstrap 5.3.3 · CSS3 · Vanilla JavaScript. No build step, no framework, no
Node/PHP dependency for the frontend. Bootstrap and the webfonts are **vendored locally**,
so the site has zero third-party requests and works on any shared hosting or cPanel account.

**To deploy:** upload the contents of this folder to `public_html/` (or your web root).
That is the whole process.

## Pages

| File | Purpose |
|---|---|
| `index.html` | Home — hero, company intro, product categories, toolroom, why us, customers |
| `about-us.html` | Company background, 2012/2019 timeline, how an enquiry runs |
| `products.html` | All five categories with every product |
| `manufacturing-capabilities.html` | Fabrication, toolroom operations, locations |
| `industries.html` | Industries served + customer list |
| `contact-us.html` | Enquiry form + contact details |
| `products/industrial-trolleys.html` | Material movement trolleys |
| `products/pallets-bins.html` | Pallets, MS bins, foldable pallets |
| `products/storage-racks.html` | Metal storage rack systems |
| `products/office-storage.html` | Office storage systems |
| `products/battery-racks.html` | UPS battery and battery cabinet systems |

## ⚠ Before going live — required replacements

The company profile PDF was not available when this site was built, so the following are
**placeholders**. Each one is marked with a `TODO` comment in the HTML. Search and replace
across all files.

| Placeholder | Where | Replace with |
|---|---|---|
| `+91 00000 00000` / `tel:+910000000000` | topbar, footer, contact page | Real phone number |
| `email@example.com` / `mailto:email@example.com` | topbar, footer, contact page | Real email address |
| `https://www.devengineeringworks.in` | canonical + Open Graph tags on every page, `robots.txt`, `sitemap.xml`, JSON-LD | Real domain |
| Street addresses | `contact-us.html` → "Visit Us" | Full address for each unit (only city/state are shown now, which is accurate) |

**Customer names** in `industries.html` and `index.html` are taken from the list supplied
with the brief. Check each spelling against the company profile before publishing — in
particular confirm whether the customer is *Toyota Gosei* or *Toyoda Gosei*.

## ⚠ Images are placeholders

Every image is a **blueprint-style technical illustration** generated for this build, not a
photograph. They are brand-consistent and safe to ship, but real factory and product
photographs will do far more for a B2B buyer. Replace them in `assets/images/`:

```
assets/images/logo/       logo.svg, logo-light.svg (footer), mark.svg
assets/images/products/   one per product + cat-*.svg category heroes
assets/images/factory/    hero, factory-unit, welding-fabrication, toolroom-machining, profile-cutting
assets/images/about/      company, workshop
assets/images/customers/  empty — drop customer logos here if you want a logo wall
```

Drop-in replacements should be **4:3** (e.g. 1200×900) JPG or WebP. Keep the same filenames
and nothing else needs to change; otherwise update the `src` and the `alt` text. Every image
already has descriptive alt text — update it to describe the real photograph.

## Wiring up the enquiry form

`contact-us.html` has a fully validated form with **no backend attached**. Client-side
validation runs on submit; because `action` is empty, `main.js` blocks the submit and tells
the visitor to call or email instead.

To connect it, add `action` and `method` to `<form id="deEnquiryForm">`:

```html
<!-- PHP handler on the same host -->
<form class="de-form" id="deEnquiryForm" action="send-enquiry.php" method="post" novalidate>

<!-- or Web3Forms -->
<form class="de-form" id="deEnquiryForm" action="https://api.web3forms.com/submit" method="post" novalidate>
  <input type="hidden" name="access_key" value="YOUR-KEY">

<!-- or FormSubmit -->
<form class="de-form" id="deEnquiryForm" action="https://formsubmit.co/YOUR-EMAIL" method="post" novalidate>
```

Once `action` is set, validation still runs and the form submits normally. Field names are
already sensible: `name`, `company`, `phone`, `email`, `product`, `message`.

Product pages link to `contact-us.html?product=<name>`, which preselects the category (or
drops the product name into the message when there is no matching option).

## Editing

- **Colours, spacing, type** — CSS custom properties at the top of `assets/css/style.css`.
- **Behaviour** — `assets/js/main.js` (sticky header, mobile nav, scroll reveal, back-to-top,
  form validation, `?product=` prefill, footer year). One file, no dependencies.
- **Adding a product** — copy a `.de-product-card` block in the relevant
  `products/*.html`, and add a matching entry to `products.html`.
- **Nav / footer** are duplicated per page (plain static HTML). Change a link in one place,
  change it everywhere — search for the href.

## Content rules used in this build

Nothing on this site states a dimension, load rating, material grade, certification,
production capacity, employee count, customer count or any other figure, because none were
available. **Keep it that way unless you can source the number.** The only figures used are
the two founding dates (2012, 2019) and counts of things visible on the site itself.

## SEO

- Unique title, meta description, canonical and Open Graph tags on all 11 pages
- One `H1` per page, clean `H2`/`H3` hierarchy, descriptive alt text throughout
- JSON-LD: `Organization` sitewide, `BreadcrumbList` on interior pages, `ItemList` of
  `Product` on the products pages (name/description/image only — no invented values)
- `sitemap.xml` (11 URLs) and `robots.txt` — update the domain in both
- Keyword focus is assigned per page: home → industrial trolley manufacturer in Gurgaon;
  category pages → their own product terms; capabilities → MS fabrication and toolroom
