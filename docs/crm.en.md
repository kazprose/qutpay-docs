# Connecting to CRM and other systems

Qut Pay has two levels. The first is a ready-made solution you simply install. The second is connecting through your CRM's own webhook or API capability. Both work; the only difference is the time it takes to connect.

## Level 1. Ready-made solution

No code is needed. Install it, enter the key, and it works.

| System | What to do |
|---|---|
| WordPress / WooCommerce | [Plugin](https://api.qut.kz/downloads/qutpay-for-woocommerce.zip) → [guide](https://api.qut.kz/docs/guide/wordpress) |
| OpenCart 4 | [Module](https://api.qut.kz/downloads/qutpay.ocmod.zip), README inside the archive |
| Tilda, Webflow, any HTML form | Cabinet → Integrations → "Form hook", put the link into the form's action field |
| n8n | [Two ready-made workflows](https://api.qut.kz/downloads/qutpay-n8n.zip): creating an invoice and receiving events |
| 1C | [Guide](https://api.qut.kz/docs/guide/1c) |
| Telegram | Platform bot: `/invoice 5000`, payment notifications ([guide](https://api.qut.kz/docs/guide/telegram_bot)) |

## Level 2. Connecting via the API

There is no ready-made module, but you can connect. There is one condition: **the CRM must be able to send outbound HTTP requests** (usually called an "outgoing webhook", «исходящий вебхук»), or you need a developer.

| CRM | How it connects |
|---|---|
| Bitrix24 | Outgoing webhook or n8n |
| amoCRM, Kommo | Digital pipeline webhook or REST API |
| Altegio | Webhook and API |
| МойСклад, RetailCRM | REST API |
| Any other system | REST API + webhook |

This list is an example, not a limitation. Any system that can send HTTP requests can be connected.

### Flow

There are two directions; usually both are needed.

**CRM → Qut Pay (creating an invoice).** When a deal moves to the "Awaiting payment" stage, the CRM sends a request to our API and receives a payment link and a QR in the response.

```
POST https://api.qut.kz/api/v1/invoices
X-API-Key: qp_live_…
Content-Type: application/json

{
  "amount": 25000,
  "description": "Deal #1042",
  "externalOrderId": "1042",
  "metadata": { "dealId": "1042", "manager": "Айгүл" },
  "customer": { "phone": "77001234567", "name": "Customer" }
}
```

The response contains `payUrl`, `qrUrl` and `id`. The CRM sends that link to the customer.

If the CRM cannot send JSON and can only submit a plain form, use the "Form hook": it recognises the `amount`, `phone`, `email`, `name` and `comment` fields by itself.

**Qut Pay → CRM (payment notification).** When the customer pays, we send a webhook to your address. `metadata` and `externalOrderId` come back unchanged, so you immediately know which deal was paid.

```json
{
  "event": "invoice.paid",
  "invoice": {
    "id": "inv_…",
    "status": "paid",
    "amount": 25000,
    "externalOrderId": "1042",
    "metadata": { "dealId": "1042", "manager": "Айгүл" },
    "paidAt": "2026-09-10T05:12:00.000Z"
  }
}
```

The signature is in the `X-QutPay-Signature` header, HMAC-SHA256. Verification examples: [Website integration](https://api.qut.kz/docs/guide/integration).

### If you have no developer

Copy the [guide for AI agents](https://api.qut.kz/docs/guide/for_ai) in full, give it to ChatGPT, Claude or Cursor, and set the task: "connect Qut Pay payments to my CRM". The guide lists every endpoint, field and error. Do not paste the API key or the webhook secret into the chat; put them only on your own server.

The same text is ready in the cabinet: Integrations → "Guide for AI agents" → "Copy with task".

### Testing

Do everything in sandbox mode first; it runs without real money. By changing the invoice status manually in the cabinet, you check that your CRM handles the webhook correctly. Once everything works, cabinet → Kaspi → connect a Cashier and switch to live mode.

## Help

If you get stuck while connecting, write to us: cabinet → **Support**, or WhatsApp +7 778 881 3333, or `/support text` in Telegram [@qutpaybot](https://t.me/qutpaybot).
