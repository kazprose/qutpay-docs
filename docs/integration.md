# Qut Pay — integration guide for AI coding agents

Public docs, OpenAPI and examples on GitHub: https://github.com/kazprose/qutpay-docs · Knowledge base (merchant-facing answers, Kazakh and Russian): https://api.qut.kz/kb · machine-readable index with every article and its .md URL: https://api.qut.kz/kb/index.json · everything in one file: https://api.qut.kz/llms-full.txt

> Скопируйте этот текст целиком в ChatGPT / Claude / Cursor / Copilot / Codex вместе с задачей «Подключи оплату через Kaspi (Qut Pay) к моему проекту». Агент получит всё необходимое. Ключ API и секрет webhook вставьте сами в переменные окружения — никогда не отправляйте их в чат с ИИ.
> Осы мәтінді толығымен ЖИ-агентке көшіріп, «Жобама Qut Pay арқылы Kaspi төлемін қос» деп тапсырма беріңіз. API кілті мен webhook құпиясын чатқа жазбаңыз — тек .env файлына қойыңыз.

You are integrating **Qut Pay** — a service that lets a Kazakhstan business accept payments through **Kaspi** (QR code, Kaspi pay link, or an invoice pushed into the customer's Kaspi.kz app). Money goes directly to the merchant's Kaspi Pay account; Qut Pay only creates invoices, tracks their status and notifies you. Follow this guide exactly; do not invent endpoints.

## 0. Facts

- Base URL: `https://api.qut.kz/api/v1` (JSON, UTF-8, TLS only)
- Pricing: flat monthly subscription, no percentage of payments taken by the platform — Старт 4 990 ₸/month for 800 invoices (burst up to 200/day), Бизнес 14 900 ₸ for 4 000, Про 39 900 ₸ for 15 000; 7-day free trial that starts with the first live invoice. Kaspi's own QR fee (0,95 %) is charged by Kaspi separately, as without any service. Details: https://qut.kz/ru/tarify/ (kk: https://qut.kz/tarifter/)
- Auth: header `X-API-Key: <key>` (alternatively `Authorization: Bearer <key>`). Keys start with `qp_live_` (real payments) or `qp_test_` (sandbox). **Server-side only** — never ship the key to a browser or mobile app.
- Currency: KZT only. Amounts are decimal numbers (`2500`, `1500.5`), max 2 decimals. Invoices pushed to a phone (`kind: "phone"`) must be whole tenge.
- Merchant cabinet: `https://qut.kz/app/` (create API keys, webhook endpoints, switch sandbox ↔ live, see invoices).
- Full OpenAPI: `https://api.qut.kz/openapi.json` · human docs: `https://api.qut.kz/docs/guide/integration`
- Environment variables to prepare in the merchant's project:
  ```
  QUTPAY_API_KEY={{API_KEY}}
  QUTPAY_WEBHOOK_SECRET={{WEBHOOK_SECRET}}
  QUTPAY_BASE_URL=https://api.qut.kz/api/v1
  ```
- Current merchant context (filled by the cabinet when copied from there): organization **{{ORG_NAME}}**, mode **{{MODE}}**.

## 1. Create an invoice

`POST /invoices`

```json
{
  "amount": 2500,
  "description": "Order #1001",
  "externalOrderId": "1001",
  "customer": { "name": "Асан", "phone": "77001234567", "email": "a@b.kz" },
  "successUrl": "https://site.kz/pay/ok?order=1001",
  "failUrl": "https://site.kz/pay/fail?order=1001",
  "metadata": { "orderId": 1001 }
}
```

Optional: `kind: "phone"` — the invoice is pushed into the customer's Kaspi.kz app (requires `customer.phone` in `7XXXXXXXXXX` format and a whole-tenge amount). Default `kind: "qr"`.

Send header `Idempotency-Key: <unique per order>` (or body `idempotencyKey`) so retries never create a second invoice: same key → same invoice, HTTP 200 with `idempotentReplay: true`.

Response `201`:

```json
{
  "id": "inv_01M1X22B2DSV3MNE1XP5S2YG41",
  "status": "pending",
  "mode": "live",
  "kind": "qr",
  "amount": 2500,
  "currency": "KZT",
  "externalOrderId": "1001",
  "payUrl": "https://qut.kz/pay/inv_01M1X22B2DSV3MNE1XP5S2YG41",
  "qrUrl": "https://kaspi.kz/pay/...",
  "deepLink": "https://kaspi.kz/pay/...",
  "qrImageUrl": "https://qut.kz/pay/inv_01M1X22B2DSV3MNE1XP5S2YG41/qr.svg",
  "receiptUrl": null,
  "expiresAt": "2026-09-07T10:03:00.000Z",
  "createdAt": "2026-09-07T10:00:00.000Z"
}
```

**Recommended UX:** redirect the customer to `payUrl` — a hosted page with the QR, a "Pay in Kaspi" button for phones, a timer and automatic return to `successUrl` after payment. Alternatively embed `qrImageUrl` (SVG) and `deepLink` yourself. A QR is valid ~3 minutes (`expiresAt`); create a new invoice if it expires.

## 2. Learn the result

### 2a. Webhook (required for production)

Register `https://<your-site>/qutpay/webhook` in the cabinet (Integrations → Webhook) and store the secret in `QUTPAY_WEBHOOK_SECRET`. Qut Pay POSTs JSON:

```json
{ "event": "invoice.paid", "invoice": { "id": "inv_…", "status": "paid", "amount": 2500, "externalOrderId": "1001", "metadata": {"orderId": 1001}, "receiptNumber": "2026-000042", "receiptUrl": "https://qut.kz/receipt/inv_…", "paidAt": "…" }, "sentAt": "…" }
```

Events: `invoice.paid`, `invoice.failed`, `invoice.expired`, `invoice.cancelled`, `invoice.refunded`, `invoice.partially_refunded`, `webhook.test`.

**Verify every delivery** (headers `X-Webhook-Signature` = `sha256=<hex>`, `X-Webhook-Timestamp` = unix seconds):

```
expected = hex( HMAC_SHA256( secret, timestamp + "." + rawBody ) )
valid    = timingSafeEqual(expected, signatureWithout"sha256=") && |now - timestamp| <= 300
```

Use the **raw request body bytes** (before JSON parsing). Respond `2xx` within 8 seconds; otherwise Qut Pay retries up to 11 times over ~2 hours (after 5 consecutive failures the endpoint pauses for 10–50 minutes). Make the handler idempotent: deduplicate by `(invoice.id, invoice.status)`.

**Late payments:** an invoice that was `expired` or `cancelled` may still turn `paid` a little later (the customer scanned at the last second). Handle `invoice.paid` arriving after `invoice.expired` — fulfil the order or refund.

### 2b. Polling (fallback / return page)

`GET /invoices/{id}` (add `?live=1` to ask Kaspi right now). Status flow: `new → pending → paid | failed | expired | cancelled`; `paid → partially_refunded → refunded`. On your `successUrl` page always confirm the status server-side before showing "paid" — the return redirect is not proof of payment.

`GET /invoices?externalOrderId=1001` finds an invoice by your order id.

## 3. Other operations

- `POST /invoices/{id}/cancel` — close an open invoice (a QR may still be paid until `expiresAt`; you then receive `invoice.paid`).
- `POST /invoices/{id}/refund` body `{ "amount": 500, "reason": "…" }` (omit `amount` for a full refund). Requires scope `refunds:write`. Response 201 with the updated invoice.
- `POST /invoices/bulk` body `{ "invoices": [ …up to 100 create bodies… ] }` → 207 with per-row results.
- `POST /subscriptions` — scheduled invoices: `{ "amount": 9900, "interval": "month", "every": 1, "dayOfMonth": 1, "kind": "phone", "customer": { "phone": "77001234567" }, "description": "Подписка" }`; manage with `/subscriptions/{id}/pause|resume|cancel|run`. Each generated invoice carries `metadata.subscriptionId` and `metadata.run`, and triggers normal webhooks.
- `GET /account` — organization, mode (`sandbox`/`live`), tariff, connected Kaspi cashiers.
- Payment links (no code): the merchant creates `https://qut.kz/p/<slug>` in the cabinet; customers enter the amount themselves.
- Form hooks (Tilda, Webflow, any HTML form): the cabinet gives a URL `https://api.qut.kz/hooks/form/<token>`; POST the form there (fields `amount|sum|price`, `phone`, `email`, `name`, `comment`) → JSON `{ ok, invoiceId, payUrl }`, or add `?redirect=1` to 302 the customer to the pay page. No API key needed.
- Partner API (`partner:manage` scope, partner tariff): `POST /partner/organizations` creates a client organization and returns its API key — see `https://api.qut.kz/docs/guide/partner`.

## 4. Sandbox testing

While the organization is in **sandbox** mode nothing is sent to Kaspi and no money moves. Use a `qp_test_…` key. Complete a test payment with `POST /invoices/{id}/simulate` body `{ "status": "paid" }` (also `"failed"`, `"expired"`) or press the "[Sandbox] pay" button on the pay page. Webhooks are delivered for real, so you can test the whole flow end to end. Switch to **live** in the cabinet after the Kaspi cashier is connected; live keys start with `qp_live_`.

## 5. Errors and limits

Errors are JSON `{ "error": "<stable_code>", "message": "<human text>" }` with proper HTTP status. Codes you should handle: `unauthorized` (401), `insufficient_scope` (403), `invalid_amount`, `invalid_phone`, `phone_required`, `amount_must_be_whole_tenge`, `invalid_url` (422), `invoice_not_found` (404), `invoice_not_open`, `invoice_not_refundable` (409), `invalid_refund_amount` (422), `kaspi_session_not_configured`, `kaspi_session_expired` (503 — merchant must re-connect the cashier in the cabinet; retry later), `tariff_limit_reached`, `tariff_inactive` (429/403), `request_rate_limited` (429 + `Retry-After`), `kaspi_error`, `refund_failed` (502).

Rate limits: 200 writes and 600 reads per minute per key (`X-RateLimit-Remaining`). Public pay pages are not rate limited for customers.

## 6. Ready-made code

- Node.js SDK: https://api.qut.kz/downloads/qutpay-sdk-node.zip (PHP: qutpay-sdk-php.zip, Python: qutpay-sdk-python.zip) — `new QutPay({ apiKey })`, `createInvoice()`, `verifyWebhook({ secret, rawBody, headers })`.
- PHP: `packages/sdk-php/src/QutPay.php` (single file, `\QutPay\Client`, `\QutPay\Webhook::verify`).
- Python: `packages/sdk-python/qutpay.py` (stdlib only, `QutPay`, `verify_webhook`).
- WordPress / WooCommerce plugin: `https://api.qut.kz/downloads/qutpay-for-woocommerce.zip` (install guide: `https://api.qut.kz/docs/guide/wordpress`). OpenCart 4: `packages/opencart-qutpay`. n8n workflows: `packages/n8n-qutpay`. 1C: `https://api.qut.kz/docs/guide/1c`.

Minimal Node.js (Express) reference implementation:

```js
import crypto from 'node:crypto';
import express from 'express';
const app = express();
const BASE = process.env.QUTPAY_BASE_URL || 'https://api.qut.kz/api/v1';
const headers = { 'X-API-Key': process.env.QUTPAY_API_KEY, 'Content-Type': 'application/json' };

app.post('/checkout', express.json(), async (req, res) => {
  const order = await createOrderInDb(req.body); // your code
  const r = await fetch(`${BASE}/invoices`, {
    method: 'POST',
    headers: { ...headers, 'Idempotency-Key': `order-${order.id}` },
    body: JSON.stringify({ amount: order.total, description: `Order #${order.id}`, externalOrderId: String(order.id), successUrl: `https://site.kz/orders/${order.id}`, metadata: { orderId: order.id } }),
  });
  const inv = await r.json();
  if (!r.ok) return res.status(502).json(inv);
  await saveInvoiceId(order.id, inv.id);
  res.redirect(inv.payUrl);
});

app.post('/qutpay/webhook', express.raw({ type: '*/*' }), async (req, res) => {
  const ts = String(req.headers['x-webhook-timestamp'] || '');
  const sig = String(req.headers['x-webhook-signature'] || '');
  const expected = crypto.createHmac('sha256', process.env.QUTPAY_WEBHOOK_SECRET).update(`${ts}.${req.body}`).digest('hex');
  const ok = sig.startsWith('sha256=') && expected.length === sig.length - 7 && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig.slice(7))) && Math.abs(Date.now() / 1000 - Number(ts)) <= 300;
  if (!ok) return res.sendStatus(401);
  const { event, invoice } = JSON.parse(req.body);
  if (event === 'invoice.paid') await markOrderPaid(invoice.externalOrderId, invoice); // idempotent
  if (event === 'invoice.refunded') await markOrderRefunded(invoice.externalOrderId);
  res.sendStatus(200);
});
```

## 7. Checklist for the agent

1. Add the three environment variables; read them on the server only.
2. Implement "create invoice → redirect to `payUrl`" on checkout with an `Idempotency-Key` per order.
3. Implement the webhook route with raw-body signature verification, timestamp tolerance, idempotent status handling, late-payment handling.
4. On the success page, confirm the status via `GET /invoices/{id}` before showing "paid".
5. Show the merchant where to configure: cabinet → Integrations → API key + webhook URL `https://<site>/qutpay/webhook`.
6. Test in sandbox: create invoice → `POST /invoices/{id}/simulate {"status":"paid"}` → verify the webhook flipped the order.
7. Do **not**: put the key in frontend code, trust the redirect as payment proof, parse JSON before verifying the signature, or ignore `invoice.paid` after `invoice.expired`.
