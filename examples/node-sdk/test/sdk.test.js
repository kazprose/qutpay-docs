import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { QutPay, verifyWebhook } from '../index.js';

test('verifyWebhook accepts a correctly signed fresh body and rejects tampering', () => {
  const secret = 'whsec_unit';
  const body = JSON.stringify({ event: 'invoice.paid', invoice: { id: 'inv_1' } });
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = 'sha256=' + crypto.createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
  assert.equal(verifyWebhook({ secret, rawBody: body, headers: { 'x-webhook-signature': sig, 'x-webhook-timestamp': ts } }), true);
  assert.equal(verifyWebhook({ secret, rawBody: body + ' ', headers: { 'x-webhook-signature': sig, 'x-webhook-timestamp': ts } }), false);
  assert.equal(verifyWebhook({ secret, rawBody: body, headers: { 'x-webhook-signature': sig, 'x-webhook-timestamp': String(Number(ts) - 1000) } }), false);
});

test('client requires a key', () => {
  assert.throws(() => new QutPay({}), /apiKey/);
});
