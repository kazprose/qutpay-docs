# Qut Pay API — errors, statuses and limits

Every error is JSON `{ "error": "<stable_code>", "message": "<human text>" }` with a matching HTTP status. Branch on `error`, show `message` to developers only.

| HTTP | `error` | Meaning | What the integration should do |
|---|---|---|---|
| 401 | `unauthorized` | Missing or revoked key | Check `QUTPAY_API_KEY`; the merchant creates a new key in the cabinet |
| 403 | `insufficient_scope` | Key lacks the scope (`refunds:write`, `subscriptions:manage`, `partner:manage`) | Ask the merchant for a key with that scope |
| 403 | `tariff_inactive` | Trial ended / subscription unpaid | Tell the merchant to pay the tariff in the cabinet; do not retry in a loop |
| 404 | `invoice_not_found` | Unknown invoice id for this organization | Check the stored id and the key's organization |
| 409 | `invoice_not_open` | Cancel on an invoice that is no longer pending | Treat as final; read the current status |
| 409 | `invoice_not_refundable` | Refund on an unpaid / already refunded invoice | Read the status first |
| 422 | `invalid_amount` | Not a number, ≤ 0, or > 2 decimals | Validate before calling |
| 422 | `amount_must_be_whole_tenge` | `kind: "phone"` with a fractional amount | Round for phone invoices |
| 422 | `invalid_phone` / `phone_required` | Phone must be `7XXXXXXXXXX`; required for `kind: "phone"` | Normalise the number |
| 422 | `invalid_url` | `successUrl` / `failUrl` not http(s) or malformed | Use absolute https URLs |
| 422 | `invalid_refund_amount` | Refund above the refundable remainder | Use ≤ paid − already refunded |
| 429 | `tariff_limit_reached` | Monthly or daily invoice limit of the tariff | Stop creating invoices; tell the merchant |
| 429 | `request_rate_limited` | Per-key rate limit; `Retry-After` header | Back off and retry once after `Retry-After` |
| 502 | `kaspi_error` / `refund_failed` | Kaspi rejected or timed out | Retry with the same `Idempotency-Key` after a short delay; surface to the merchant if persistent |
| 503 | `kaspi_session_not_configured` / `kaspi_session_expired` | No connected cashier, or Kaspi session lost | The merchant re-connects the cashier in the cabinet; retry later. Show a friendly "temporarily unavailable" message to the customer |

Idempotent replay: `POST /invoices` with an `Idempotency-Key` already used returns HTTP 200 and the existing invoice with `idempotentReplay: true` — not an error.

## Invoice statuses

`new → pending → paid | failed | expired | cancelled`; after payment `paid → partially_refunded → refunded`.
An `expired` or `cancelled` invoice can still become `paid` a little later (customer scanned at the last second): handle `invoice.paid` after `invoice.expired` by fulfilling or refunding.

## Webhook events

`invoice.paid`, `invoice.failed`, `invoice.expired`, `invoice.cancelled`, `invoice.refunded`, `invoice.partially_refunded`, `webhook.test`. Delivery: POST JSON, 8 s timeout, up to 11 retries over ~2 hours; after 5 consecutive failures the endpoint pauses 10–50 minutes.

## Limits

- Rate: 200 write and 600 read requests per minute per key (`X-RateLimit-Remaining`, `Retry-After`).
- Tariffs (invoices created per month, with a daily burst guard): Start 800 (200/day), Business 4 000, Pro 15 000, Partner 30 000 (2 000/day). 7-day free trial starts with the first live invoice. Pricing: https://qut.kz/tarify/
- QR validity ≈ 3 minutes (`expiresAt`); create a new invoice when it expires.
- Bulk: ≤ 100 invoices per `POST /invoices/bulk` (207 with per-row results).
- Webhook URL must be a public https domain (not localhost / IP); ≤ 5 endpoints per organization.
- Currency KZT only.
