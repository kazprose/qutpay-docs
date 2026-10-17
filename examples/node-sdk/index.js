/**
 * Qut Pay — client SDK for your website (Node.js 18+, ESM).
 *
 *   import { QutPay, verifyWebhook } from '@qutpay/sdk';
 *   const qp = new QutPay({ apiKey: process.env.QUTPAY_API_KEY }); // baseUrl defaults to https://api.qut.kz
 *
 *   const inv = await qp.createInvoice({ amount: 5000, description: 'Премиум жазылым', externalOrderId: 'inv-1001',
 *                                        customer: { email: 'a@b.kz' }, successUrl: 'https://site.kz/ok' });
 *   res.redirect(inv.payUrl);
 *
 * Webhook verification (Express):
 *   app.post('/qutpay-webhook', express.raw({ type: '*\/*' }), (req, res) => {
 *     if (!verifyWebhook({ secret, rawBody: req.body, headers: req.headers })) return res.sendStatus(401);
 *     const { event, invoice } = JSON.parse(req.body);
 *     if (event === 'invoice.paid') activateService(invoice.externalOrderId);
 *     res.sendStatus(200);
 *   });
 */
import crypto from 'node:crypto';

export class QutPay {
  #baseUrl;
  #apiKey;

  constructor({ apiKey, baseUrl = 'https://api.qut.kz', timeoutMs = 20000 }) {
    if (!apiKey) throw new Error('apiKey is required');
    this.#baseUrl = baseUrl.replace(/\/+$/, '');
    this.#apiKey = apiKey;
    this.timeoutMs = timeoutMs;
  }

  async #call(method, path, body, headers = {}) {
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const resp = await fetch(this.#baseUrl + path, {
        method,
        headers: { 'X-API-Key': this.#apiKey, 'Content-Type': 'application/json', ...headers },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: controller.signal,
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok) {
        const err = new Error(data.message || data.error || `HTTP ${resp.status}`);
        err.status = resp.status;
        err.code = data.error;
        throw err;
      }
      return data;
    } catch (err) {
      if (!err.status) {
        err.status = 0;
        err.code = err.name === 'AbortError' ? 'timeout' : 'network_error';
      }
      throw err;
    } finally {
      clearTimeout(t);
    }
  }

  /** @returns {Promise<{id:string, status:string, payUrl:string, qrUrl:string, deepLink:string, qrImageUrl:string, expiresAt:string}>} */
  createInvoice({ amount, kind = 'qr', description, externalOrderId, customer, successUrl, failUrl, metadata, idempotencyKey } = {}) {
    return this.#call('POST', '/api/v1/invoices', { amount, kind, description, externalOrderId, customer, successUrl, failUrl, metadata }, idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {});
  }
  /** Legacy alias (orders API). */
  createOrder(params) {
    return this.createInvoice({ ...params, externalOrderId: params.externalOrderId ?? params.merchantRef, kind: params.method === 'invoice' ? 'phone' : params.kind });
  }
  getInvoice(id, { live = false } = {}) {
    return this.#call('GET', `/api/v1/invoices/${encodeURIComponent(id)}${live ? '?live=1' : ''}`);
  }
  listInvoices(params = {}) {
    const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null)).toString();
    return this.#call('GET', `/api/v1/invoices${qs ? '?' + qs : ''}`);
  }
  cancelInvoice(id) {
    return this.#call('POST', `/api/v1/invoices/${encodeURIComponent(id)}/cancel`);
  }
  /** Full refund when amount is omitted. */
  refundInvoice(id, amount, reason) {
    return this.#call('POST', `/api/v1/invoices/${encodeURIComponent(id)}/refund`, { ...(amount === undefined ? {} : { amount }), ...(reason ? { reason } : {}) });
  }
  /** Sandbox only. */
  simulateInvoice(id, status = 'paid') {
    return this.#call('POST', `/api/v1/invoices/${encodeURIComponent(id)}/simulate`, { status });
  }
  account() {
    return this.#call('GET', '/api/v1/account');
  }
}

/** Backwards-compatible name from the first release. */
export const KaspiPayClient = QutPay;

/**
 * Verifies X-Webhook-Signature. `rawBody` must be the exact bytes/string received.
 * Rejects deliveries older than `toleranceSec` (replay protection).
 */
export const verifyWebhook = ({ secret, rawBody, headers, toleranceSec = 300 }) => {
  if (!secret) return false;
  const get = (n) => headers[n] ?? headers[n.toLowerCase()] ?? (typeof headers.get === 'function' ? headers.get(n) : undefined);
  const signature = String(get('X-Webhook-Signature') || '');
  const timestamp = String(get('X-Webhook-Timestamp') || '');
  if (!signature.startsWith('sha256=') || !/^\d{1,12}$/.test(timestamp)) return false;
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > toleranceSec) return false;
  const body = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
  const a = Buffer.from(signature.slice(7));
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
};
