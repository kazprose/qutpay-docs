import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer, createClient, mask } from '../src/server.js';

/** Fake Qut Pay API: records requests, answers like the real one. No network. */
const fakeFetch = (log, { fail } = {}) => async (url, init = {}) => {
  const u = new URL(url);
  const body = init.body ? JSON.parse(init.body) : undefined;
  log.push({ method: init.method || 'GET', path: u.pathname + u.search, body, headers: init.headers });
  const json = (status, data) => ({ ok: status < 400, status, json: async () => data, text: async () => JSON.stringify(data) });
  if (fail) return json(fail.status, { error: fail.code, message: fail.message });
  if (u.pathname.endsWith('/account')) return json(200, { organization: { name: 'Shop' }, mode: 'sandbox', tariff: { tier: 'trial' } });
  if (u.pathname.endsWith('/status')) return json(200, { provider: 'mock', mode: 'sandbox' });
  if (u.pathname.endsWith('/invoices') && init.method === 'POST') return json(201, { id: 'inv_1', status: 'pending', amount: body.amount, payUrl: 'https://qut.kz/pay/inv_1' });
  if (/\/invoices\/inv_1\/simulate$/.test(u.pathname)) return json(200, { id: 'inv_1', status: body.status });
  if (/\/invoices\/inv_1\/refund$/.test(u.pathname)) return json(201, { id: 'inv_1', status: 'refunded', refunded: body.amount ?? 'full' });
  if (/\/invoices\/inv_1\/cancel$/.test(u.pathname)) return json(200, { id: 'inv_1', status: 'cancelled' });
  if (/\/invoices\/inv_1$/.test(u.pathname)) return json(200, { id: 'inv_1', status: 'paid', secretEcho: 'qp_test_ABCDEFGHIJ' });
  if (u.pathname.endsWith('/invoices')) return json(200, { invoices: [{ id: 'inv_1' }], total: 1 });
  if (u.pathname.endsWith('/subscriptions')) return json(200, { subscriptions: [] });
  if (u.pathname.endsWith('/SKILL.md')) return { ok: true, status: 200, text: async () => '---\nname: qutpay-kaspi-payments\n---\n# skill' };
  return json(404, { error: 'not_found' });
};

const connect = async ({ apiKey = 'qp_test_abc', fail } = {}) => {
  const log = [];
  const server = createServer({ apiKey, baseUrl: 'https://api.example.test', fetchImpl: fakeFetch(log, { fail }) });
  const client = new Client({ name: 'test', version: '0' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(a);
  await client.connect(b);
  return {
    client,
    log,
    close: async () => {
      await client.close();
      await server.close();
    },
  };
};

test('lists the expected tools, resources and prompts', async () => {
  const { client, close } = await connect();
  const tools = (await client.listTools()).tools.map((t) => t.name).sort();
  assert.deepEqual(tools, ['qutpay_account', 'qutpay_cancel_invoice', 'qutpay_create_invoice', 'qutpay_get_invoice', 'qutpay_get_subscription', 'qutpay_list_invoices', 'qutpay_list_subscriptions', 'qutpay_refund_invoice', 'qutpay_simulate_payment']);
  const res = (await client.listResources()).resources.map((r) => r.uri).sort();
  assert.deepEqual(res, ['qutpay://docs/errors', 'qutpay://docs/integration', 'qutpay://docs/openapi', 'qutpay://docs/webhook-verify', 'qutpay://skill']);
  const prompts = (await client.listPrompts()).prompts.map((p) => p.name).sort();
  assert.deepEqual(prompts, ['integrate-checkout', 'verify-sandbox']);
  await close();
});

test('create invoice sends the right request with Idempotency-Key and never echoes the key', async () => {
  const { client, log, close } = await connect();
  const r = await client.callTool({ name: 'qutpay_create_invoice', arguments: { amount: 2500, description: 'Order #1', externalOrderId: '1', idempotencyKey: 'order-1', successUrl: 'https://site.kz/ok' } });
  const req = log.find((l) => l.path === '/api/v1/invoices' && l.method === 'POST');
  assert.ok(req);
  assert.equal(req.headers['Idempotency-Key'], 'order-1');
  assert.equal(req.headers['X-API-Key'], 'qp_test_abc');
  assert.equal(req.body.amount, 2500);
  assert.equal(req.body.idempotencyKey, undefined, 'idempotency goes into the header only');
  assert.equal(r.isError, undefined);
  assert.equal(r.structuredContent.id, 'inv_1');
  assert.doesNotMatch(JSON.stringify(r), /qp_test_abc/);
  await close();
});

test('get invoice masks secret-looking strings in API responses', async () => {
  const { client, close } = await connect();
  const r = await client.callTool({ name: 'qutpay_get_invoice', arguments: { id: 'inv_1', live: true } });
  assert.match(r.content[0].text, /"status": "paid"/);
  assert.doesNotMatch(r.content[0].text, /qp_test_ABCDEFGHIJ/);
  assert.match(r.content[0].text, /qp_test_…/);
  await close();
});

test('simulate works in sandbox and refuses with a live key', async () => {
  const sb = await connect();
  const r1 = await sb.client.callTool({ name: 'qutpay_simulate_payment', arguments: { id: 'inv_1' } });
  assert.equal(r1.structuredContent.status, 'paid');
  assert.ok(sb.log.some((l) => l.path.endsWith('/invoices/inv_1/simulate') && l.body.status === 'paid'));
  await sb.close();
  const live = await connect({ apiKey: 'qp_live_xyz' });
  const r2 = await live.client.callTool({ name: 'qutpay_simulate_payment', arguments: { id: 'inv_1' } });
  assert.equal(r2.isError, true);
  assert.equal(r2.structuredContent.error, 'sandbox_only');
  assert.equal(live.log.length, 0, 'no request was sent');
  await live.close();
});

test('refund: live key needs confirm=true; sandbox does not; amount omitted → full refund', async () => {
  const live = await connect({ apiKey: 'qp_live_xyz' });
  const r1 = await live.client.callTool({ name: 'qutpay_refund_invoice', arguments: { id: 'inv_1', amount: 500 } });
  assert.equal(r1.isError, true);
  assert.equal(r1.structuredContent.error, 'confirmation_required');
  assert.equal(live.log.length, 0);
  const r2 = await live.client.callTool({ name: 'qutpay_refund_invoice', arguments: { id: 'inv_1', amount: 500, confirm: true } });
  assert.equal(r2.isError, undefined);
  assert.match(r2.content[0].text, /LIVE MODE/);
  assert.deepEqual(live.log[0].body, { amount: 500 });
  await live.close();
  const sb = await connect();
  const r3 = await sb.client.callTool({ name: 'qutpay_refund_invoice', arguments: { id: 'inv_1' } });
  assert.equal(r3.isError, undefined);
  assert.deepEqual(sb.log[0].body, {});
  await sb.close();
});

test('API errors come back as isError with the stable code, not as exceptions', async () => {
  const { client, close } = await connect({ fail: { status: 429, code: 'tariff_limit_reached', message: 'limit' } });
  const r = await client.callTool({ name: 'qutpay_create_invoice', arguments: { amount: 10 } });
  assert.equal(r.isError, true);
  assert.deepEqual(r.structuredContent, { error: 'tariff_limit_reached', message: 'limit', status: 429 });
  await close();
});

test('account merges key mode, account and provider status; list passes filters', async () => {
  const { client, log, close } = await connect();
  const a = await client.callTool({ name: 'qutpay_account', arguments: {} });
  assert.equal(a.structuredContent.keyMode, 'sandbox');
  assert.equal(a.structuredContent.provider.provider, 'mock');
  await client.callTool({ name: 'qutpay_list_invoices', arguments: { status: 'paid', limit: 5 } });
  assert.ok(log.some((l) => l.path === '/api/v1/invoices?limit=5&status=paid' || l.path === '/api/v1/invoices?status=paid&limit=5'));
  await close();
});

test('resources are fetched from the API host and prompts render', async () => {
  const { client, close } = await connect();
  const r = await client.readResource({ uri: 'qutpay://skill' });
  assert.match(r.contents[0].text, /qutpay-kaspi-payments/);
  const p = await client.getPrompt({ name: 'integrate-checkout', arguments: { stack: 'Laravel' } });
  assert.match(p.messages[0].content.text, /Laravel/);
  assert.match(p.messages[0].content.text, /qutpay:\/\/skill/);
  await close();
});

test('client refuses to start without a key; mask() helper', () => {
  assert.throws(() => createClient({ apiKey: '' }), /QUTPAY_API_KEY/);
  assert.equal(mask('key qp_live_ABCDEFGHIJ and whsec_ZZZZZZZZZZ'), 'key qp_live_… and whsec_ZZ…');
});
