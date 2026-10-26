/**
 * Qut Pay MCP server — tools, resources and prompts an AI agent uses to work with the Qut Pay API
 * (Kaspi QR invoices for Kazakhstan). Every tool is a 1:1 view of the public API, limited by the
 * key's scopes; nothing here can create keys or webhooks (those stay in the merchant cabinet).
 *
 * Safety rules (kept in code, not only in docs):
 *   - the API key comes from the environment and is never echoed in any output;
 *   - `qutpay_simulate_payment` refuses to run with a live key;
 *   - `qutpay_refund_invoice` with a live key requires `confirm: true`;
 *   - responses are JSON text plus `structuredContent`, with secret-looking strings masked.
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';

export const VERSION = '0.1.2';
const DEFAULT_BASE = 'https://api.qut.kz';
const SECRET_RE = /\b(qp_(?:live|test)_[A-Za-z0-9_-]{6,}|whsec_[A-Za-z0-9_-]{6,})\b/g;

export const mask = (text) => String(text).replace(SECRET_RE, (m) => m.slice(0, 8) + '…');

export class QutPayApiError extends Error {
  constructor(status, code, message) {
    super(message || code || `HTTP ${status}`);
    this.status = status;
    this.code = code;
  }
}

/** Minimal client: one place that knows the key, so no tool can leak it by accident. */
export const createClient = ({ apiKey, baseUrl = DEFAULT_BASE, fetchImpl = globalThis.fetch, timeoutMs = 20000 }) => {
  if (!apiKey) throw new Error('QUTPAY_API_KEY is not set');
  const base = String(baseUrl).replace(/\/+$/, '') + '/api/v1';
  const isLive = /^qp_live_/.test(apiKey);
  const call = async (method, path, body, headers = {}) => {
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const r = await fetchImpl(base + path, {
        method,
        headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json', 'User-Agent': `qutpay-mcp/${VERSION}`, ...headers },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: controller.signal,
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new QutPayApiError(r.status, data.error, data.message);
      return data;
    } catch (e) {
      if (e instanceof QutPayApiError) throw e;
      throw new QutPayApiError(0, e.name === 'AbortError' ? 'timeout' : 'network_error', e.message);
    } finally {
      clearTimeout(t);
    }
  };
  const qs = (params) => {
    const s = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
    return s ? '?' + s : '';
  };
  return {
    isLive,
    mode: isLive ? 'live' : 'sandbox',
    account: () => call('GET', '/account'),
    status: () => call('GET', '/status'),
    createInvoice: ({ idempotencyKey, ...body }) => call('POST', '/invoices', body, idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
    getInvoice: (id, live) => call('GET', `/invoices/${encodeURIComponent(id)}${live ? '?live=1' : ''}`),
    listInvoices: (params) => call('GET', `/invoices${qs(params)}`),
    cancelInvoice: (id) => call('POST', `/invoices/${encodeURIComponent(id)}/cancel`),
    refundInvoice: (id, amount, reason) => call('POST', `/invoices/${encodeURIComponent(id)}/refund`, { ...(amount === undefined ? {} : { amount }), ...(reason ? { reason } : {}) }),
    simulateInvoice: (id, status) => call('POST', `/invoices/${encodeURIComponent(id)}/simulate`, { status }),
    listSubscriptions: (params) => call('GET', `/subscriptions${qs(params)}`),
    getSubscription: (id) => call('GET', `/subscriptions/${encodeURIComponent(id)}`),
  };
};

const ok = (data, note) => {
  const text = mask(JSON.stringify(data, null, 2));
  return { content: [{ type: 'text', text: note ? `${note}\n\n${text}` : text }], structuredContent: data && typeof data === 'object' && !Array.isArray(data) ? JSON.parse(text) : { result: JSON.parse(text) } };
};
const fail = (e) => {
  const body = e instanceof QutPayApiError ? { error: e.code || 'error', message: mask(e.message), status: e.status } : { error: 'error', message: mask(e?.message || String(e)) };
  return { isError: true, content: [{ type: 'text', text: JSON.stringify(body) }], structuredContent: body };
};
const run = async (fn) => {
  try {
    return await fn();
  } catch (e) {
    return fail(e);
  }
};

const fetchText = async (url, fetchImpl) => {
  let r;
  try {
    r = await fetchImpl(url, { headers: { Accept: 'text/markdown, text/plain, application/json' } });
  } catch (e) {
    throw new Error(`could not fetch ${url}: ${e.message}`);
  }
  if (!r.ok) throw new Error(`${url}: HTTP ${r.status}`);
  return r.text();
};

/**
 * Builds the McpServer. `client` can be injected (tests); otherwise it is created from env/options.
 */
/**
 * Without a key (remote endpoint scanned by a directory, or a client that forgot the header) the
 * server still lists tools and serves docs; every tool call answers `unauthorized` with instructions.
 */
const anonymousClient = (message) => {
  const deny = () => Promise.reject(new QutPayApiError(401, 'unauthorized', message));
  return { isLive: false, mode: 'anonymous', account: deny, status: deny, createInvoice: deny, getInvoice: deny, listInvoices: deny, cancelInvoice: deny, refundInvoice: deny, simulateInvoice: deny, listSubscriptions: deny, getSubscription: deny };
};

export const createServer = ({ apiKey = process.env.QUTPAY_API_KEY, baseUrl = process.env.QUTPAY_BASE_URL || DEFAULT_BASE, docsUrl, fetchImpl = globalThis.fetch, client, allowAnonymous = false, anonymousMessage = 'No API key: pass Authorization: Bearer qp_test_… (create a key in the Qut Pay cabinet → Integrations → API keys).' } = {}) => {
  const api = client || (!apiKey && allowAnonymous ? anonymousClient(anonymousMessage) : createClient({ apiKey, baseUrl, fetchImpl }));
  const docsBase = String(docsUrl || baseUrl).replace(/\/+$/, '').replace(/\/api\/v1$/, '');
  const liveNote = api.isLive ? 'LIVE MODE: this key moves real money. ' : '';
  const server = new McpServer({ name: 'qutpay', version: VERSION }, { instructions: `Qut Pay — Kaspi QR payments for Kazakhstan. Mode of this key: ${api.mode}. Start with qutpay_account. Create invoices with an idempotencyKey per order; the customer pays at payUrl; the merchant's webhook (registered in the cabinet) marks the order paid. In sandbox use qutpay_simulate_payment to complete a test payment. Read resource qutpay://skill for the integration procedure. Never ask the merchant to paste the API key into chat.` });

  // ── resources (docs pulled from the API so they are never stale) ──
  const docResource = (name, uri, title, url, mimeType = 'text/markdown') =>
    server.registerResource(name, uri, { title, description: `${title} (${url})`, mimeType }, async (u) => ({ contents: [{ uri: u.href, mimeType, text: await fetchText(url, fetchImpl) }] }));
  docResource('skill', 'qutpay://skill', 'Qut Pay agent skill (SKILL.md)', `${docsBase}/SKILL.md`);
  docResource('integration', 'qutpay://docs/integration', 'Full integration guide for AI agents', `${docsBase}/for-ai`, 'text/plain');
  docResource('errors', 'qutpay://docs/errors', 'Error codes, statuses and limits', `${docsBase}/skill/references/errors.md`);
  docResource('webhook-verify', 'qutpay://docs/webhook-verify', 'Webhook signature verification code (Node, PHP, Python)', `${docsBase}/skill/references/webhook-verify.md`);
  docResource('openapi', 'qutpay://docs/openapi', 'OpenAPI 3 definition', `${docsBase}/openapi.json`, 'application/json');

  // ── tools ──
  server.registerTool(
    'qutpay_account',
    {
      title: 'Qut Pay account and mode',
      description: 'Returns the organization behind the API key: mode (sandbox or live), tariff and limits, connected Kaspi cashiers, provider status. Call this first.',
      inputSchema: {},
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    () => run(async () => ok({ keyMode: api.mode, ...(await api.account()), provider: await api.status().catch(() => null) }, liveNote || undefined)),
  );

  server.registerTool(
    'qutpay_create_invoice',
    {
      title: 'Create a Kaspi invoice',
      description: 'Creates an invoice (QR by default, or pushed to the customer\'s Kaspi.kz app with kind="phone"). Returns payUrl (hosted pay page), qrImageUrl, deepLink, expiresAt (~3 min). Always pass idempotencyKey (e.g. "order-1001") so a retry never creates a second invoice. Amount in KZT, ≤ 2 decimals; whole tenge for kind="phone".',
      inputSchema: {
        amount: z.number().positive().describe('Amount in KZT, e.g. 2500 or 1500.5'),
        description: z.string().max(255).optional().describe('Shown to the customer, e.g. "Order #1001"'),
        externalOrderId: z.string().max(120).optional().describe('Your order id; later GET /invoices?externalOrderId finds it'),
        idempotencyKey: z.string().min(1).max(120).optional().describe('Unique per order; same key → same invoice'),
        kind: z.enum(['qr', 'phone']).optional().describe('"qr" (default) or "phone" (invoice pushed into the customer\'s Kaspi.kz app; needs customer.phone)'),
        customer: z.object({ name: z.string().optional(), phone: z.string().regex(/^7\d{10}$/).optional().describe('7XXXXXXXXXX'), email: z.string().email().optional() }).optional(),
        successUrl: z.string().url().optional(),
        failUrl: z.string().url().optional(),
        metadata: z.record(z.any()).optional().describe('Returned unchanged in webhooks'),
      },
      annotations: { readOnlyHint: false, idempotentHint: true, openWorldHint: true },
    },
    (args) => run(async () => ok(await api.createInvoice(args), api.isLive ? 'LIVE MODE: a real Kaspi invoice was issued.' : undefined)),
  );

  server.registerTool(
    'qutpay_get_invoice',
    {
      title: 'Get invoice status',
      description: 'Reads one invoice by id (inv_…). live=true asks Kaspi right now instead of the cached status. Status flow: new → pending → paid | failed | expired | cancelled; paid → partially_refunded → refunded.',
      inputSchema: { id: z.string().min(1), live: z.boolean().optional() },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    ({ id, live }) => run(async () => ok(await api.getInvoice(id, live))),
  );

  server.registerTool(
    'qutpay_list_invoices',
    {
      title: 'List invoices',
      description: 'Lists invoices of the organization, newest first. Filter by status, externalOrderId, date range (ISO), limit ≤ 50, offset for paging.',
      inputSchema: {
        status: z.enum(['new', 'pending', 'paid', 'failed', 'expired', 'cancelled', 'partially_refunded', 'refunded']).optional(),
        externalOrderId: z.string().optional(),
        from: z.string().optional().describe('ISO date/time, inclusive'),
        to: z.string().optional().describe('ISO date/time, exclusive'),
        limit: z.number().int().min(1).max(50).optional(),
        offset: z.number().int().min(0).optional(),
      },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    (args) => run(async () => ok(await api.listInvoices({ limit: 20, ...args }))),
  );

  server.registerTool(
    'qutpay_cancel_invoice',
    {
      title: 'Cancel an open invoice',
      description: 'Closes a pending invoice. A QR may still be paid until expiresAt — then invoice.paid arrives anyway; handle it.',
      inputSchema: { id: z.string().min(1) },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    ({ id }) => run(async () => ok(await api.cancelInvoice(id))),
  );

  server.registerTool(
    'qutpay_refund_invoice',
    {
      title: 'Refund a paid invoice',
      description: 'Full refund (omit amount) or partial refund of a paid invoice. Needs the refunds:write scope. With a LIVE key real money is returned to the customer, so confirm=true is required and the merchant must have asked for this refund explicitly.',
      inputSchema: { id: z.string().min(1), amount: z.number().positive().optional().describe('Omit for a full refund'), reason: z.string().max(255).optional(), confirm: z.boolean().optional().describe('Required (true) with a live key') },
      annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
    },
    ({ id, amount, reason, confirm }) =>
      run(async () => {
        if (api.isLive && confirm !== true) throw new QutPayApiError(0, 'confirmation_required', 'LIVE key: pass confirm=true after the merchant has explicitly approved this refund (amount and invoice).');
        return ok(await api.refundInvoice(id, amount, reason), api.isLive ? 'LIVE MODE: real money was refunded.' : undefined);
      }),
  );

  server.registerTool(
    'qutpay_simulate_payment',
    {
      title: 'Simulate a payment (sandbox only)',
      description: 'Completes a sandbox invoice as paid / failed / expired without Kaspi, so the whole flow including the merchant\'s webhook can be tested. Refuses to run with a live key.',
      inputSchema: { id: z.string().min(1), status: z.enum(['paid', 'failed', 'expired']).optional().describe('Default "paid"') },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    },
    ({ id, status }) =>
      run(async () => {
        if (api.isLive) throw new QutPayApiError(0, 'sandbox_only', 'Simulation works only with a sandbox key (qp_test_…). Switch the organization to sandbox in the cabinet or use a test key.');
        return ok(await api.simulateInvoice(id, status || 'paid'));
      }),
  );

  server.registerTool(
    'qutpay_list_subscriptions',
    {
      title: 'List scheduled invoices (subscriptions)',
      description: 'Read-only list of recurring invoice schedules. Needs the subscriptions:manage scope on the key.',
      inputSchema: { status: z.enum(['active', 'paused', 'cancelled']).optional(), limit: z.number().int().min(1).max(50).optional() },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    (args) => run(async () => ok(await api.listSubscriptions({ limit: 20, ...args }))),
  );

  server.registerTool(
    'qutpay_get_subscription',
    {
      title: 'Get one subscription',
      description: 'Reads a recurring invoice schedule by id (sub_…). Needs the subscriptions:manage scope.',
      inputSchema: { id: z.string().min(1) },
      annotations: { readOnlyHint: true, openWorldHint: true },
    },
    ({ id }) => run(async () => ok(await api.getSubscription(id))),
  );

  // ── prompts ──
  server.registerPrompt(
    'integrate-checkout',
    {
      title: 'Integrate Kaspi payments into a project',
      description: 'Step-by-step task for adding Qut Pay (create invoice → payUrl → signed webhook → confirm on success page) to the current codebase.',
      argsSchema: { stack: z.string().optional().describe('e.g. "Next.js + Prisma", "Laravel", "Django"') },
    },
    ({ stack }) => ({
      messages: [
        {
          role: 'user',
          content: {
            type: 'text',
            text: `Add Kaspi payments through Qut Pay to this project${stack ? ` (${stack})` : ''}. First read the resource qutpay://skill and follow it exactly. Then: (1) call qutpay_account to learn the mode and limits; (2) implement "create invoice → redirect to payUrl" on checkout with an Idempotency-Key per order, reading QUTPAY_API_KEY from the environment; (3) implement the webhook route with raw-body HMAC-SHA256 verification (see qutpay://docs/webhook-verify), timestamp tolerance 300 s, idempotent order updates, and late invoice.paid after invoice.expired; (4) confirm the status server-side on the success page; (5) test in sandbox with qutpay_create_invoice and qutpay_simulate_payment and show me the result; (6) finish with the checklist of what I must do in the Qut Pay cabinet. Never put the key or webhook secret into code, chat or logs.`,
          },
        },
      ],
    }),
  );

  server.registerPrompt(
    'verify-sandbox',
    {
      title: 'Verify the integration in sandbox',
      description: 'Creates a test invoice, simulates payment and checks that the merchant\'s webhook handler updated the order.',
      argsSchema: { amount: z.string().optional().describe('Amount in KZT, default 100') },
    },
    ({ amount }) => ({
      messages: [
        {
          role: 'user',
          content: {
            type: 'text',
            text: `Verify the Qut Pay integration end to end in sandbox: call qutpay_account (must be sandbox); create an invoice of ${amount || 100} KZT with externalOrderId "test-" + timestamp and an idempotencyKey; call qutpay_simulate_payment on it; call qutpay_get_invoice and confirm status "paid"; then check in this project's logs/database that the webhook handler marked the test order paid. Repeat once with status "expired" and confirm nothing was fulfilled. Report each step with the invoice id, never the API key.`,
          },
        },
      ],
    }),
  );

  return server;
};
