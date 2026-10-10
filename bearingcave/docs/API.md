# REST API v1

Base URL: `https://www.bearingcave.com/api/v1`. All responses are JSON.

## Conventions

| Item | Rule |
|---|---|
| Success | `{"data": …, "meta": {…}}` (`meta` appears only on paginated lists) |
| Error | `{"error": {"status": 404, "message": "Not found", "details": {…}}}` |
| Auth | Public endpoints work without a token and apply **guest** visibility. Private endpoints need `Authorization: Bearer <token>`. |
| Tokens | Created by the user in **Account → API tokens** (Shield personal access tokens). The token is shown once and can be revoked there. It acts with the user's own company permissions. |
| Visibility | Product endpoints use the same `VisibilityService` as the website: country rules, verified-buyer-only listings, supplier aliasing and price visibility all apply to API callers too. |
| CSRF | Not required for `/api/*`. Token auth is used instead. |

## Public endpoints

### `GET /products`

Searches published listings visible to the caller.

Query parameters (all optional):

| Group | Parameters |
|---|---|
| Search and filters | `q`, `category` (slug), `brand` (slug), `condition`, `country` (ISO-2 stock location), `age` (`lt_6m`, `6_12m`, `gt_12m`), `verified` (`1`), `min_price`, `max_price`, `min_qty`, `in_stock` (`1`) |
| Dimensions | `id`, `od`, `w` (bore, outer diameter and width in mm) |
| Vehicle fitment | `make`, `model`, `year` |
| Other | `spec`, `sort` |
| Paging | `page`, `per_page` (default 24, maximum 100) |

Example response, captured from the demo data (a guest caller, so `price` is null):

```json
{
  "data": [{
    "id": "a40bd8e2-0c54-4963-82bf-0cf9e9785ab9",
    "url": "https://…/product/deep-groove-ball-bearing-2rs-sealed-6204-2rs",
    "sku": "APX-6204-2RS", "name": "Deep groove ball bearing, 2RS sealed",
    "part_number": "6204-2RS", "oem_part_number": null, "brand": "SKF", "category": "Ball Bearings",
    "condition": "new", "available_quantity": 1200, "moq": 50, "unit": "pcs", "sale_mode": "piece",
    "currency": "INR", "price": null, "price_on_request": false, "inventory_age": "lt_6m",
    "warehouse_country": "IN",
    "supplier": {"name": "[SAMPLE] Apex Bearings & Components Pvt Ltd", "verified": true},
    "match_type": "part_number", "interchangeability_note": null
  }],
  "meta": {"total": 2, "page": 1, "per_page": 1, "pages": 2}
}
```

Field notes:
- `match_type` is one of `part_number`, `oem`, `alternate`, `declared_cross_reference`, `verified_cross_reference`, `text` or `none`.
- When the match came through a supplier-declared cross reference, `interchangeability_note` says the interchangeability is **not verified**.
- `supplier.name` is the supplier's alias (for example "Supplier BC-S-…") unless the caller may see the real identity.
- `price` is `null` when the caller may not see prices: guests, unless the setting allows it, and listings marked price on request.

### `GET /products/{uuid}`

Returns the same fields plus `specifications[]`, `cross_references[]` (each with a `verified_interchange` boolean) and `fitments[]`. Returns 404 if the listing doesn't exist **or** the caller may not see it. The two cases are deliberately indistinguishable.

### `GET /categories`, `GET /brands`

Active reference data.

### `GET /suggest?q=620`

Autocomplete suggestions: label, type and URL, filtered by visibility.

## Authenticated endpoints

| Method | Path | Who | Returns |
|---|---|---|---|
| GET | `/me` | any token | user id, email, groups, company (name, type, verification status, plan code) |
| GET | `/notifications` | any token | last 50 in-app notifications |
| GET | `/orders` | buyer or supplier | last 100 orders of the caller's company (number, status, payment, shipment, currency, total) |
| GET | `/rfqs` | buyer | last 100 RFQs of the caller's company |
| GET | `/rfqs/{id}` | buyer | RFQ, items and **current quotations** (number, revision, status, total, validity, lead time). Another company's RFQ returns 404 and writes a security audit event. |
| POST | `/rfqs` | buyer with `rfq.manage` | creates an RFQ; 201 with `id`, `rfq_number`, `status` |

`POST /rfqs` body:

```json
{
  "title": "Bearings for Q4 service stock",
  "destination_country": "AE",
  "deadline_at": "2026-11-15 18:00:00",
  "currency": "USD",
  "delivery_terms": "CIF Jebel Ali",
  "items": [
    {"part_number": "6204-2RS", "brand_text": "SKF", "quantity": 500, "unit": "pcs", "specifications": "C3 clearance", "target_unit_price": "1.20"}
  ]
}
```

Status codes:
- **422** with `details` for validation errors.
- **422** for business rules, for example when the plan's open-RFQ limit is reached.
- **403** when the user's company role lacks `rfq.manage`.

## Webhooks (payment providers)

`POST /webhooks/payments/{provider}` receives provider callbacks.
- CSRF does not apply. Each request is authenticated by the gateway's signature check. The sandbox gateway uses an HMAC-SHA256 of the raw body in the `X-Sandbox-Signature` header.
- Events are stored in `payment_webhook_events` with a unique provider and event id, so a replayed event is acknowledged but not applied twice.
- Response: 200 `{"data": {"ok": true, …}}`, or 400 for an invalid signature or payload.

The sandbox gateway is disabled in production. No escrow provider is configured yet (see `07_PENDING_INTEGRATIONS.md`, I-2).

## Quick test

```bash
curl -s "http://localhost:8080/api/v1/products?q=6204&per_page=1"
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v1/me
```

A request without a token to an authenticated endpoint returns 401.
