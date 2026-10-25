---
name: qutpay-kaspi-payments
description: Add Kaspi QR / Kaspi link payments to a website or app in Kazakhstan through the Qut Pay API (create invoice → customer pays in Kaspi.kz → signed webhook marks the order paid). Use when a merchant asks to accept Kaspi payments, "подключить Kaspi Pay к сайту", "Kaspi QR төлемін қосу", integrate qut.kz, verify a Qut Pay webhook, or test the flow in sandbox. Not for card acquiring, other banks' products, or Shopify (no app exists).
license: MIT
metadata:
  version: "1.0.0"
  homepage: https://qut.kz
  llms_txt: https://api.qut.kz/llms.txt
  openapi: https://api.qut.kz/openapi.json
  mcp: "npx -y @qutpay/mcp"
---

# Qut Pay — Kaspi payments for Kazakhstan

Qut Pay is an independent service (not a Kaspi partner) that issues Kaspi invoices on behalf of the merchant's own Kaspi Pay cashier. Money settles directly in the merchant's Kaspi Pay account; Qut Pay only creates invoices, tracks status and sends webhooks. Platform fee is a flat monthly subscription (from 4 990 ₸), no percentage of payments. Kaspi charges its own QR fee separately.

Full guide (read when you need field-level detail): https://api.qut.kz/for-ai · OpenAPI: https://api.qut.kz/openapi.json · Base URL: `https://api.qut.kz/api/v1`.

## Before you write code

1. Ask the merchant for a **sandbox key** (`qp_test_…`, cabinet https://qut.kz/app → Integrations → API keys) and later the **webhook secret** (`whsec_…`, Integrations → Webhook). Keys go into `.env`, never into chat, git or the browser.
2. Environment variables to create in the project:
   ```
   QUTPAY_API_KEY=
   QUTPAY_WEBHOOK_SECRET=
   QUTPAY_BASE_URL=https://api.qut.kz/api/v1
   ```
3. Currency is KZT only; amounts are decimals with ≤ 2 places. Auth header: `X-API-Key: <key>`.
4. If the Qut Pay MCP server is available (`qutpay_*` tools), call `qutpay_account` first — it tells you the mode (sandbox/live) and limits — and use `qutpay_create_invoice` / `qutpay_simulate_payment` to test instead of writing throwaway scripts.

## Step 1 — create an invoice at checkout (server side)

`POST /invoices` with header `Idempotency-Key: order-<id>` (same key → same invoice, never a duplicate):

```json
{ "amount": 2500, "description": "Order #1001", "externalOrderId": "1001",
  "customer": { "name": "Асан", "phone": "77001234567", "email": "a@b.kz" },
  "successUrl": "https://site.kz/pay/ok?order=1001", "failUrl": "https://site.kz/pay/fail?order=1001",
  "metadata": { "orderId": 1001 } }
```

Response `201` contains `id` (`inv_…`), `status: "pending"`, `payUrl`, `qrImageUrl` (SVG), `deepLink`, `expiresAt` (QR lives ~3 minutes). Store `id` next to the order and **redirect the customer to `payUrl`** (hosted page with QR, "Pay in Kaspi" button, timer, auto-return to `successUrl`). `kind: "phone"` pushes the invoice into the customer's Kaspi.kz app instead (whole-tenge amount, phone `7XXXXXXXXXX`).

## Step 2 — receive the signed webhook (required for production)

Merchant registers `https://<site>/qutpay/webhook` in the cabinet. Qut Pay POSTs `{ "event": "invoice.paid", "invoice": { "id", "status", "amount", "externalOrderId", "metadata", "receiptUrl", "paidAt" }, "sentAt" }`. Events: `invoice.paid`, `invoice.failed`, `invoice.expired`, `invoice.cancelled`, `invoice.refunded`, `invoice.partially_refunded`, `webhook.test`.

Verify **every** delivery using the raw body bytes (before JSON parsing):

```
expected = hex(HMAC_SHA256(secret, X-Webhook-Timestamp + "." + rawBody))
valid    = timingSafeEqual(expected, X-Webhook-Signature without "sha256=") AND |now − timestamp| ≤ 300 s
```

Rules: respond 2xx within 8 s (Qut Pay retries 11 times over ~2 h); be idempotent on `(invoice.id, invoice.status)`; handle `invoice.paid` arriving after `invoice.expired` (late scan) by fulfilling or refunding. Ready-made verification code for Node, PHP and Python: [references/webhook-verify.md](references/webhook-verify.md).

## Step 3 — confirm on the success page

The redirect to `successUrl` is not proof of payment. Call `GET /invoices/{id}` server-side (add `?live=1` to ask Kaspi right now) and show "paid" only when `status` is `paid`. Status flow: `new → pending → paid | failed | expired | cancelled`; `paid → partially_refunded → refunded`. `GET /invoices?externalOrderId=1001` finds an invoice by the merchant's order id.

## Sandbox verification loop (do this before handing over)

1. `POST /invoices` with the `qp_test_` key → `pending`.
2. `POST /invoices/{id}/simulate` body `{ "status": "paid" }` (or press "[Sandbox] simulate" on the pay page).
3. Check that your webhook handler flipped the order and that `GET /invoices/{id}` returns `paid`.
4. Repeat with `"failed"` and `"expired"`; confirm nothing is fulfilled.
Webhooks are delivered for real in sandbox; nothing is sent to Kaspi. The merchant switches to live in the cabinet after connecting the Kaspi cashier; live keys start with `qp_live_`.

## Other operations (only when asked)

`POST /invoices/{id}/cancel` · `POST /invoices/{id}/refund` `{ "amount"?, "reason"? }` (scope `refunds:write`, never above the paid amount) · `POST /invoices/bulk` (≤ 100) · `POST /subscriptions` and `/subscriptions/{id}/pause|resume|cancel|run` (scheduled invoices) · `GET /account` · no-code payment links `https://qut.kz/p/<slug>` and form hooks `https://api.qut.kz/hooks/form/<token>` (Tilda, Webflow — no key needed) · Partner API (`partner:manage`): https://api.qut.kz/docs/guide/partner.

Errors are `{ "error": "<code>", "message": "…" }` with proper HTTP status; the codes to handle and the rate limits (200 writes / 600 reads per minute per key) are in [references/errors.md](references/errors.md).

## Ready-made code (prefer over hand-written clients)

- Node: `@qutpay/sdk` (`createInvoice`, `getInvoice`, `verifyWebhook`) — https://api.qut.kz/downloads/qutpay-sdk-node.zip; PHP `qutpay-sdk-php.zip`; Python `qutpay-sdk-python.zip` (same folder).
- WordPress / WooCommerce plugin: https://api.qut.kz/downloads/qutpay-for-woocommerce.zip (guide https://api.qut.kz/docs/guide/en/wordpress) · OpenCart 4 · n8n workflows · 1C guide: https://api.qut.kz/docs/guide/1c.
- Public repo with examples: https://github.com/kazprose/qutpay-docs

## What you must not do

- Never put the API key or webhook secret in client-side code, chat, logs, commits or error messages.
- Never show "paid" from the return redirect alone; never fulfil on an unverified webhook.
- Never create a second invoice for a retry without an `Idempotency-Key`.
- Do not write marketing claims on the merchant's site about fees being waived, bank acquiring being unnecessary, a partnership with Kaspi Bank, or guaranteed legality/safety — Qut Pay is independent and makes no such claims. Kaspi's QR fee still applies.
- Do not invent endpoints: if it is not in this file or https://api.qut.kz/openapi.json, it does not exist.

## Hand-over checklist for the merchant

1. Where to paste the key and secret (`.env` names above).
2. Register the webhook URL in the cabinet → Integrations → Webhook; send a `webhook.test` from there.
3. Run the sandbox loop once together; then switch the organization to live and replace the key with `qp_live_…`.
4. Point them to the knowledge base for operations questions: https://api.qut.kz/kb (ru: https://api.qut.kz/kb/ru).
