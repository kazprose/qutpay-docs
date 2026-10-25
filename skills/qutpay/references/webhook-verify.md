# Verifying a Qut Pay webhook

Every delivery carries `X-Webhook-Signature: sha256=<hex>` and `X-Webhook-Timestamp: <unix seconds>`.
`hex(HMAC_SHA256(secret, timestamp + "." + rawBody))` must equal the signature (constant-time compare) and the timestamp must be within 300 s of now. Always hash the **raw body bytes**, never a re-serialised object. Respond 2xx within 8 s; deduplicate by `(invoice.id, invoice.status)`.

## Node.js (Express)

```js
import crypto from 'node:crypto';
import express from 'express';

const app = express();
app.post('/qutpay/webhook', express.raw({ type: '*/*' }), async (req, res) => {
  const ts = String(req.headers['x-webhook-timestamp'] || '');
  const sig = String(req.headers['x-webhook-signature'] || '');
  const expected = crypto.createHmac('sha256', process.env.QUTPAY_WEBHOOK_SECRET).update(`${ts}.${req.body}`).digest('hex');
  const ok = sig.startsWith('sha256=') && expected.length === sig.length - 7
    && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig.slice(7)))
    && Math.abs(Date.now() / 1000 - Number(ts)) <= 300;
  if (!ok) return res.sendStatus(401);
  const { event, invoice } = JSON.parse(req.body);
  if (event === 'invoice.paid') await markOrderPaid(invoice.externalOrderId, invoice);         // idempotent
  if (event === 'invoice.refunded' || event === 'invoice.partially_refunded') await markOrderRefunded(invoice.externalOrderId, invoice);
  res.sendStatus(200);
});
```

With the SDK: `import { verifyWebhook } from '@qutpay/sdk'; verifyWebhook({ secret, rawBody: req.body, headers: req.headers })`.

## Next.js (App Router)

```js
// app/api/qutpay/webhook/route.js
import crypto from 'node:crypto';
export async function POST(req) {
  const raw = await req.text();
  const ts = req.headers.get('x-webhook-timestamp') || '';
  const sig = req.headers.get('x-webhook-signature') || '';
  const expected = crypto.createHmac('sha256', process.env.QUTPAY_WEBHOOK_SECRET).update(`${ts}.${raw}`).digest('hex');
  const ok = sig.startsWith('sha256=') && expected.length === sig.length - 7
    && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig.slice(7)))
    && Math.abs(Date.now() / 1000 - Number(ts)) <= 300;
  if (!ok) return new Response('bad signature', { status: 401 });
  const { event, invoice } = JSON.parse(raw);
  // ...update the order idempotently...
  return new Response('ok');
}
```

## PHP (plain / Laravel)

```php
$raw = file_get_contents('php://input');                 // Laravel: $request->getContent()
$ts  = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $ts . '.' . $raw, getenv('QUTPAY_WEBHOOK_SECRET'));
$ok = str_starts_with($sig, 'sha256=') && hash_equals($expected, substr($sig, 7)) && abs(time() - (int) $ts) <= 300;
if (!$ok) { http_response_code(401); exit; }
$data = json_decode($raw, true);
if ($data['event'] === 'invoice.paid') { /* mark order paid, idempotently */ }
http_response_code(200);
```

With the SDK: `\QutPay\Webhook::verify($secret, $raw, getallheaders())`.

## Python (Flask / Django / FastAPI)

```python
import hmac, hashlib, time, json

def verify(secret: str, raw_body: bytes, headers) -> bool:
    sig = headers.get("X-Webhook-Signature", "")
    ts = headers.get("X-Webhook-Timestamp", "")
    if not sig.startswith("sha256=") or not ts.isdigit() or abs(time.time() - int(ts)) > 300:
        return False
    expected = hmac.new(secret.encode(), f"{ts}.".encode() + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig[7:])

# Flask
@app.post("/qutpay/webhook")
def qutpay_webhook():
    if not verify(os.environ["QUTPAY_WEBHOOK_SECRET"], request.get_data(), request.headers):
        return "", 401
    data = json.loads(request.get_data())
    if data["event"] == "invoice.paid":
        mark_order_paid(data["invoice"]["externalOrderId"], data["invoice"])   # idempotent
    return "", 200
```

With the SDK (stdlib only): `from qutpay import verify_webhook; verify_webhook(secret, raw_body, headers)`.

## Testing the handler

Cabinet → Integrations → Webhook → "Send test" delivers a `webhook.test` event signed with the real secret. In sandbox, `POST /invoices/{id}/simulate {"status":"paid"}` triggers a genuine `invoice.paid` delivery. Local development: expose the handler with a tunnel (ngrok, cloudflared) — the webhook URL must be a public domain, not localhost or an IP.
