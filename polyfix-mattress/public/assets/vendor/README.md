# Vendored front-end assets

These are the compiled files the pages load. They are committed here rather
than pulled by Composer or npm, so the application has no build step and no
runtime dependency on a CDN — nothing a page needs comes from a third party.

| What | Version | Where it came from |
|---|---|---|
| `bootstrap/` | 5.3.8 | `twbs/bootstrap`, `dist/css` and `dist/js` |
| `bootstrap-icons/` | 1.11 | `twbs/bootstrap-icons`, the stylesheet and the font files |
| `jsqr/` | 1.4.0 | npm `jsqr`, `dist/jsQR.js` and `LICENSE` (Apache-2.0) |

`jsqr/jsQR.js` is the fallback QR decoder for the camera scanner. Browsers
that provide the native `BarcodeDetector` API never download it — the scanner
loads it only when the native decoder is missing, which is mostly iOS Safari.
It is the file npm publishes, byte for byte; the tarball it came from matches
the registry's own checksums:

    shasum  8efb8d0a7cc6863cb6d95116b9069123ce9eb2d1
    sha512  dxLob7q65Xg2DvstYkRpkYtmKm2sPJ9oFhrhmudT1dZvNFFTlroai3AWSpLey/w5vMcLBXRgOJsbXpdN9HzU/A==

The fonts under `public/assets/fonts` (Fraunces and Inter, Latin subsets) are
self-hosted for the same reason, and are preloaded by the site layout.

To take a newer Bootstrap: install it somewhere scratch
(`composer require twbs/bootstrap`), copy `dist/css/bootstrap.min.css` and
`dist/js/bootstrap.bundle.min.js` over the files here, note the version in this
table, and check the console and the portal at phone width before committing.
