# Vendored front-end assets

These are the compiled files the pages load. They are committed here rather
than pulled by Composer or npm, so the application has no build step and no
runtime dependency on a CDN — nothing a page needs comes from a third party.

| What | Version | Where it came from |
|---|---|---|
| `bootstrap/` | 5.3.8 | `twbs/bootstrap`, `dist/css` and `dist/js` |
| `bootstrap-icons/` | 1.11 | `twbs/bootstrap-icons`, the stylesheet and the font files |

The fonts under `public/assets/fonts` (Fraunces and Inter, Latin subsets) are
self-hosted for the same reason, and are preloaded by the site layout.

To take a newer Bootstrap: install it somewhere scratch
(`composer require twbs/bootstrap`), copy `dist/css/bootstrap.min.css` and
`dist/js/bootstrap.bundle.min.js` over the files here, note the version in this
table, and check the console and the portal at phone width before committing.
