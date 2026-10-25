# Website integration (Qut Pay API)

If you have not connected yet, first read the [How to connect](START.md) page — it describes how to connect a Kaspi "Кассир" (Cashier).

Full specification: **https://api.qut.kz/docs** (OpenAPI: `/openapi.json`, a short guide for AI assistants: `/for-ai`).

**Pricing:** a fixed monthly subscription; the platform does not take a percentage of each payment — Start 4 990 ₸/month (800 invoices per month), Business 14 900 ₸ (4 000), Pro 39 900 ₸ (15 000); 7 days free, counted from the first real invoice. Kaspi charges its own QR fee (0.95 %) separately. Details: [Тарифтер](https://qut.kz/kk/tarifter/) · [Тарифы (ru)](https://qut.kz/tarify/).

Your website talks to the service in only two places:

1. **Create an invoice** — `POST https://api.qut.kz/api/v1/invoices`, then send the customer to `payUrl`.
2. **Get the result** — a webhook (`invoice.paid`, etc.) or `GET /api/v1/invoices/{id}`.

Authentication: `X-API-Key: <key>` (cabinet → API keys). The key is `qp_live_…` (live) or `qp_test_…` (sandbox).

```
Site ──POST /api/v1/invoices──▶ Qut Pay ──QR/invoice──▶ Kaspi
 │◀── payUrl (redirect) ────────┘
customer pays → Qut Pay checks every 3 s
 │◀── POST webhook: invoice.paid (HMAC) ──┘
```

## 1. Creating an invoice

| Field | Type | Description |
|---|---|---|
| `amount` | number ✅ | Tenge, at most 2 decimal places (an invoice sent to a phone — a whole number) |
| `kind` | `qr` \| `phone` | `qr` (default): QR + link; `phone`: a push to the customer's Kaspi (requires `customer.phone`) |
| `description` | string | Shown to the customer (100 characters; phone — 60) |
| `externalOrderId` | string | Order number on your site, returned in the webhook |
| `customer.name/phone/email` | string | If an email is given, the customer receives a receipt email (if SMTP is configured) |
| `successUrl`, `failUrl` | url | http(s) only |
| `metadata` | object | Any JSON |
| header `Idempotency-Key` | string | Safe to retry: same key → same invoice (HTTP 200, `idempotentReplay: true`) |

Response (201): `id`, `status: "pending"`, `payUrl`, `qrUrl`, `deepLink`, `qrImageUrl`, `expiresAt` (QR — the Kaspi scanning window, ~3 min).

The legacy `/api/v1/orders` path and the `merchantRef`, `method: "invoice"` fields are also accepted (alias).

## 2. Webhook

Cabinet → Webhook → add an address (in production only https and a real domain). The secret is shown once.

```
POST /qutpay-webhook
X-Webhook-Event: invoice.paid
X-Webhook-Timestamp: 1757167000
X-Webhook-Signature: sha256=3f1a…
X-Webhook-Delivery: 17
{ "event": "invoice.paid", "invoice": { "id": "inv_…", "externalOrderId": "1001", "status": "paid", "amount": 2500, "metadata": {…}, "receiptUrl": "…", "paidAt": "…" }, "sentAt": "…" }
```

Signature: `HMAC-SHA256(secret, timestamp + "." + rawBody)`, hex. Verify the body as the unmodified raw bytes. Reject a timestamp older than 5 minutes. On a non-2xx response the delivery is retried 11 times (10 s → 1 h). Processing must be **idempotent**: by the `(invoice.id, status)` pair.

**Late payment.** If money for a `cancelled`/`expired` invoice arrives late, `invoice.paid` (`late: true`) is still sent later — deliver the service or refund.

### Node.js

```js
import { QutPay, verifyWebhook } from '@qutpay/sdk'; // packages/sdk
const qp = new QutPay({ apiKey: process.env.QUTPAY_API_KEY });

app.post('/buy', async (req, res) => {
  const inv = await qp.createInvoice({ amount: 2500, description: 'Premium', externalOrderId: `o-${order.id}`, successUrl: 'https://site.kz/ok', idempotencyKey: `o-${order.id}` });
  res.redirect(inv.payUrl);
});

app.post('/qutpay-webhook', express.raw({ type: '*/*' }), (req, res) => {
  if (!verifyWebhook({ secret: process.env.QUTPAY_WEBHOOK_SECRET, rawBody: req.body, headers: req.headers })) return res.sendStatus(401);
  const { event, invoice } = JSON.parse(req.body);
  if (event === 'invoice.paid') activate(invoice.externalOrderId);
  res.sendStatus(200);
});
```

### PHP

`packages/sdk-php/src/QutPay.php` (a single file, PHP 7.4+):

```php
require 'QutPay.php';
$qp = new \QutPay\Client($apiKey);
$inv = $qp->createInvoice(['amount' => 2500, 'description' => 'Premium', 'externalOrderId' => '1001', 'successUrl' => 'https://site.kz/ok', 'idempotencyKey' => '1001']);
header('Location: ' . $inv['payUrl']);

// webhook
$raw = file_get_contents('php://input');
if (!\QutPay\Webhook::verify($secret, $raw, getallheaders())) { http_response_code(401); exit; }
$e = json_decode($raw, true);
if ($e['event'] === 'invoice.paid') activate($e['invoice']['externalOrderId']);
```

There is a plugin for WordPress/WooCommerce stores: `packages/woocommerce-qutpay`.

### Python

`packages/sdk-python/qutpay.py` (standard library only, 3.8+):

```python
from qutpay import QutPay, verify_webhook
qp = QutPay(api_key=API_KEY)
inv = qp.create_invoice(amount=2500, description="Premium", external_order_id="1001", success_url="https://site.kz/ok", idempotency_key="1001")
return redirect(inv["payUrl"])

# webhook (Flask / Django: request.get_data() / request.body)
if not verify_webhook(SECRET, request.get_data(), request.headers): abort(401)
```

## 3. Sandbox

While the organization is in `sandbox` mode, invoices are not sent to Kaspi. `POST /invoices/{id}/simulate {"status":"paid"}` or the "[Sandbox] pay" button on the payment page simulates a payment. Webhooks are delivered for real (the address may be http/localhost).

## 3.0. One organization, several projects: binding a key to a cashier

If one organization has several Kaspi cashiers and several projects (for example, two websites), create a separate API key for each project and **bind it to one cashier** (cabinet → Integrations → choose «Кассир» when creating the key, or the `connectionId` field). Then:

- live invoices created with that key go only through that cashier and never land on another one;
- the key sees only that cashier's live invoices (`GET /invoices`, `GET /invoices/{id}`, refund, cancel) — the other project's invoices are not visible to it;
- if you also bind the webhook address to that key, each project receives only its own events.

Sandbox invoices are not tied to a cashier; they are the organization's shared test data. A cashier that has a key bound to it cannot be deleted — move the key to another cashier first (`PATCH /panel/orgs/{org}/api-keys/{id}`, `connectionId`).

## 3.1. If you are latency-sensitive

If the customer is standing in front of a device and expects a result immediately after paying (a power-bank station, a vending machine, a turnstile, a barrier gate), do not make the webhook your only mechanism. The right approach is to run both in parallel:

1. The **webhook** remains the main channel; it usually arrives first.
2. In parallel, from the moment the invoice is created, poll its status with `GET /invoices/{id}` **for the first 30 seconds**, roughly once per second.
3. Accept whichever arrives first and ignore the other. That is why processing must be **idempotent**: if the same invoice arrives as "paid" twice, the device must not open twice.

After thirty seconds stop polling and rely on the webhook only. This does not create extra load, and it shortens the customer's waiting time.

The reason is simple: we learn about a payment by polling Kaspi, and the polling cycle varies with load. The webhook is sent at the moment we learn about it, but the network and your server's response also take time. When both channels run in parallel, the fastest one wins.

If you put the station or cell number into the invoice's `metadata`, it comes back unchanged both in the webhook payload and in the status response — you immediately know which device to open.

## 4. Errors

`{ "error": "<code>", "message": "<text>" }`. The main ones: `unauthorized`, `insufficient_scope`, `invalid_amount`, `invalid_phone`, `phone_required`, `amount_must_be_whole_tenge`, `invalid_url`, `invoice_not_found`, `invoice_not_open`, `invoice_not_refundable`, `kaspi_session_not_configured`, `kaspi_session_expired`, `tariff_limit_reached`, `request_rate_limited` (429 + `Retry-After`), `kaspi_error`, `refund_failed`.

## 5. Subscriptions (scheduled invoices), bulk, form hooks

- **Subscription**: `POST /api/v1/subscriptions {amount, interval: month|week|day, every, dayOfMonth?, kind: phone|qr, customer:{phone,email,name}, maxRuns?, startAt?}` — an invoice is created automatically in every period (`kind: phone` → to the customer's Kaspi app; `qr` → a link, plus an email if one is given). Management: `/pause`, `/resume` (with `{"catchUp": true}` the missed run is executed immediately; otherwise it continues from the next scheduled run), `/cancel`, `/run` (out of schedule). **Retry and misfire policy**: if invoice creation fails (Kaspi did not respond, the session dropped, tariff), it is retried according to the `retryDelaysMin` steps, default `[15, 60, 360]` minutes; once the steps are exhausted that run is dropped, the merchant is notified via Telegram/email, and the schedule continues (the response contains `retryAttempt`, `failedRuns`, `lastError`, `lastRunStatus`: `ok | retrying | failed | skipped`). `misfirePolicy`: `run_once` (default) — a late run is executed once; `skip` — if it is later than `misfireAfterMin` (default 1440), it is skipped and the schedule moves on to the next run. The invoice carries `metadata.subscriptionId` and `metadata.run`; webhooks arrive as usual.
- **Bulk**: `POST /api/v1/invoices/bulk {invoices:[…]}` — up to 100, response 207 with a result per item.
- **Form hooks** (Tilda, Webflow, any HTML form): cabinet → Integrations → "Form hook" → URL `https://api.qut.kz/hooks/form/<token>`. Form fields may be named freely (`amount|sum|price|Paymentsum`, `phone|Phone|tel`, `email`, `name`, `comment`); the response is `{ok, invoiceId, payUrl}`, with `?redirect=1` — a 302 to the payment page. Tilda's "test" ping is accepted. If `defaults.kind = phone`, the invoice goes to the customer's Kaspi app.
- **Partner API**: [docs/PARTNER.md](PARTNER.md).
- **1C**: [docs/1C.md](1C.md). **n8n**: `packages/n8n-qutpay`. **OpenCart**: `packages/opencart-qutpay`.

## 6. WordPress / WooCommerce

Ready-made plugin: https://api.qut.kz/downloads/qutpay-for-woocommerce.zip. Full installation guide (key, webhook, settings, testing, errors): [WORDPRESS.md](WORDPRESS.md) → https://api.qut.kz/docs/guide/wordpress. OpenCart 4: https://api.qut.kz/downloads/qutpay.ocmod.zip (README inside the archive).
