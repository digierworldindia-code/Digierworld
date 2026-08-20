# Mithaas — image drop-in guide

Every `<img>` on this site points at a file in this folder. **Until a file exists, the site
shows a branded gold-and-ivory plate carrying the dish name instead of a broken image.**
Drop the real photograph in at the path below and it appears automatically — no code change.

## 1. The logo (do this first)

| File | What it must be |
|---|---|
| `brand/mithaas-logo.png` | **The exact official Mithaas logo file, unmodified.** Transparent PNG (or SVG as `mithaas-logo.svg` — update the `src` in each page if you use SVG). Recommended width ≥ 900px so it stays sharp on the hero. |
| `brand/favicon.png` | Square favicon exported from the same logo (512×512). |
| `brand/apple-touch-icon.png` | 180×180, same source. |
| `brand/og-mithaas.jpg` | 1200×630 social share card. |

Do not redraw, recolour, re-typeset, crop or regenerate the logo. Export it from the original
artwork at the sizes above and nothing else.

## 2. Photography

Shoot or supply real Mithaas photographs wherever possible — they always beat stock. Warm,
natural light; shallow depth of field; real serving ware; no heavy filters.

| Folder | Used by | Suggested crop |
|---|---|---|
| `hero/` | Full-bleed page heroes | 2400×1350 (16:9), landscape |
| `sweets/` | Sweets page, favourites shelf, gifting | 1200×1500 (4:5), portrait |
| `bakery/` | Bakery tiles and editorial | 1400×1050 (4:3) |
| `restaurant/` | Restaurant categories, thali, kitchen | 1400×1050 (4:3) |
| `gallery/` | Gallery masonry and Instagram strip | Mixed — see below |
| `brand/` | Story page and shopfront | 1200×1500 (4:5) |

### Exact filenames the pages look for

**hero/** — `hero-sweets.jpg`, `hero-menu.jpg`

**brand/** — `story-hero.jpg`, `story-counter.jpg`, `story-kadhai.jpg`, `story-oven.jpg`,
`story-dining.jpg`

**sweets/** — `hero-sweets.jpg`, `gulab-jamun.jpg`, `kaju-katli.jpg`, `motichoor-laddoo.jpg`,
`rasmalai.jpg`, `kaju-anjeer-roll.jpg`, `ghewar.jpg`, `gift-box.jpg`, `festival-tray.jpg`,
`wedding-order.jpg`, `cat-traditional-mithai.jpg`, `cat-premium-sweets.jpg`,
`cat-regional-seasonal.jpg`

**bakery/** — `hero-bakery.jpg`, `bakery-editorial.jpg`, `truffle-cake.jpg`,
`pastry-counter.jpg`, `cookies.jpg`, `bread.jpg`, `veg-puff.jpg`, `brownie.jpg`

**restaurant/** — `hero-restaurant.jpg`, `thali-hero.jpg`, `kitchen.jpg`,
`cat-north-indian.jpg`, `cat-south-indian.jpg`, `cat-snacks-street-food.jpg`,
`cat-chinese.jpg`, `cat-thali-combos.jpg`, `cat-beverages.jpg`

**gallery/** — `hero-gallery.jpg`, `sweet-counter.jpg`, `kaju-katli-stack.jpg`,
`bread-oven.jpg`, `thali.jpg`, `laddoo-tray.jpg`, `dining.jpg`, `bakery-shelf.jpg`,
`masala-dosa.jpg`, `diwali-order.jpg`, `chaat-counter.jpg`, `jalebi.jpg`, `shopfront.jpg`,
`wedding-order.jpg`, `cake-fridge.jpg`, `chilli-paneer.jpg`,
plus `ig-01.jpg` … `ig-06.jpg` (square, for the Instagram strip)

## 3. Before you upload

1. **Resize** to roughly the crop above — do not upload 6000px camera files.
2. **Convert to WebP** for real weight savings, then either rename to `.jpg` or update the
   `src` in the page. Target under 200 KB per image, under 350 KB for heroes.
3. **Keep the aspect ratio** listed above so nothing shifts as the page loads.

Every image already has `loading="lazy"` (heroes use `eager` + `fetchpriority="high"`),
`decoding="async"` and a fixed aspect ratio on its container, so layout shift is already
handled for you.
