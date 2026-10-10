# 02 — Competitor research (limited)

## What could and could not be inspected

All six reference sites failed DNS resolution from the build environment (`getaddrinfo ENOTFOUND` for surpluss.co, forthclear.io, surplusmarket.com, excess2sell.com, surplusstockworldwide.com, autoparts24.eu). **No competitor page, UI, registration flow or private feature was inspected.** The notes below come only from public third-party sources found by web search. They may be out of date, and nothing here describes any competitor's backend.

| Site | What public sources say | Sources |
|---|---|---|
| **Excess2Sell** (India) | B2B portal for overstock and surplus across electronics, telecom, apparel and home goods. Seller dashboards, business pricing and quantity discounts for "registered KYC partners". Buyer watch lists, price alerts and stock comparison. Self-pickup or chargeable shipping. Auctions and deals. Claims "100 % confidentiality" for buyers and sellers. In 2025 a non-binding term sheet for acquisition by Avance Technologies was reported. | [App Store listing](https://apple.co/3sDEhSK), [YourStory](https://yourstory.com/companies/excess2sell), [ChannelDrive 2016](https://channeldrive.in/?p=5281), [Techleap news](https://finder.techleap.nl/news/feed/avance-technologies-to-acquire-excess2sell) |
| **Surpluss** (Singapore, founded 2022) | B2B excess-inventory liquidation, including near-expiry and slow-moving stock. Described as using AI/data analytics and "AI buyer matching". Revenue model: marketplace commission. | [Caplight profile](https://www.caplight.com/company/surpluss), [CB Insights comparison](https://www.cbinsights.com/compare/excess2sell-vs-surpluss) |
| **Forthclear** | B2B marketplace where e-commerce merchants liquidate surplus and dead stock to *verified bulk buyers*. Tagged as a Shopify app with 3PL tracking. | [Caplight profile](https://www.caplight.com/company/forthclear) |
| **surplusmarket.com** | Nothing found. | — |
| **surplusstockworldwide.com** | Nothing found under that domain or name. | — |
| **autoparts24.eu** | Not researched beyond the failed site fetch. | — |

## Comparison against BearingCave's requirements

"Public info" is limited to what the sources above report. "Unknown" means not confirmed either way.

| Capability | Excess2Sell | Surpluss | Forthclear | BearingCave (built) |
|---|---|---|---|---|
| Segment | General B2B overstock | General excess inventory | E-commerce merchants | **Automotive parts** (bearings, OEM/aftermarket) |
| Part-number / OEM / cross-reference search | Unknown | Unknown | Unknown | Yes, with match type labelled and no implied interchangeability |
| Vehicle fitment search | Unknown | Unknown | Unknown | Yes (make, model, year) |
| Supplier verification depth | KYC partners | Unknown | Verified buyers | 12-module verification, AVL, risk, sanctions record |
| Confidentiality | Claimed | Unknown | Unknown | Country allow/deny lists, supplier alias, verified-buyer-only listings, confidential logistics |
| Matching | Unknown | "AI buyer matching" | Unknown | Explainable rule-based RFQ matching; score and reasons stored |
| Auctions | Yes | Unknown | Unknown | **Intentionally excluded** (sealed RFQ bidding instead; see below) |
| Inventory ageing | Unknown | Near-expiry focus | Unknown | Less than 6 months, 6–12 months, more than 12 months on every listing and report |
| Inspection / logistics services | Chargeable shipping | Reverse logistics (tag) | 3PL tracking | Inspection (10 %) and managed/confidential logistics workflows |
| Monetisation | Unknown | Commission | Unknown | Annual verified-supplier membership (₹50,000); service fees |

## Adopt, improve, exclude

**Adopt**
- Verified buyer gating for better prices (as at Forthclear and Excess2Sell): implemented as verified-buyer-only listings plus an explicit staff grant.
- Watch lists and comparison: implemented as saved inventory and comparison of up to `catalog.max_compare` items.
- Seller dashboards with selling history: implemented as the supplier dashboard, analytics and inventory export.

**Improve**
- Domain-specific search on normalised part numbers, OEM numbers, cross references and fitments, with honest match labels.
- Explainable matching instead of an opaque "AI" score: every RFQ match stores its reasons, and the weights are admin settings.
- Confidentiality the platform enforces itself rather than only claims: one visibility service is used by search, product pages, the API, matching, exports and the sitemap.
- Verification evidence stored per module, with history and expiry tracking.

**Intentionally exclude for now** (each can be revisited as a client decision)
- Open auctions: they expose price signals that manufacturers selling below list price usually want hidden. Sealed RFQ quotations meet the prompt's "confidential bidding" requirement.
- Loyalty points: not in the requirements.
- Automated "AI" claims: none are made. Matching is deterministic and documented.
- Platform responsibility for product quality: the prompt places quality and warranty with the buyer and supplier. Excess2Sell's 2016 claim of taking responsibility is not copied.

## Recommended follow-up

When the sites are reachable, a person should review the public UI, registration and search flows, then update this file. Expect about 2 hours. No code change is needed unless that review produces new requirements.
