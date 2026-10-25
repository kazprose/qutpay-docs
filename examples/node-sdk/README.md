# @qutpay/sdk — Qut Pay client for Node.js

Accept Kaspi QR / Kaspi link payments in Kazakhstan through [Qut Pay](https://qut.kz): create an invoice, send the customer to `payUrl`, receive a signed webhook. Qut Pay is an independent service, not affiliated with Kaspi Bank. KZT only.

```bash
npm i @qutpay/sdk
```

```js
import { QutPay, verifyWebhook } from '@qutpay/sdk';

const qp = new QutPay({ apiKey: process.env.QUTPAY_API_KEY }); // qp_test_… (sandbox) or qp_live_…
const inv = await qp.createInvoice({ amount: 2500, description: 'Order #1001', externalOrderId: '1001', idempotencyKey: 'order-1001', successUrl: 'https://site.kz/ok' });
// → inv.payUrl (hosted pay page with QR), inv.qrImageUrl, inv.deepLink, inv.expiresAt

// webhook route (Express): use the RAW body
app.post('/qutpay/webhook', express.raw({ type: '*/*' }), (req, res) => {
  if (!verifyWebhook({ secret: process.env.QUTPAY_WEBHOOK_SECRET, rawBody: req.body, headers: req.headers })) return res.sendStatus(401);
  const { event, invoice } = JSON.parse(req.body);
  if (event === 'invoice.paid') markOrderPaid(invoice.externalOrderId); // idempotent
  res.sendStatus(200);
});
```

Methods: `createInvoice`, `getInvoice(id, { live })`, `listInvoices(params)`, `cancelInvoice(id)`, `refundInvoice(id, amount?, reason?)`, `simulateInvoice(id, status)` (sandbox), `account()`; `verifyWebhook({ secret, rawBody, headers })`.

Docs: https://api.qut.kz/for-ai · OpenAPI https://api.qut.kz/openapi.json · agent skill https://api.qut.kz/SKILL.md · MCP server `npx -y @qutpay/mcp`. MIT.
