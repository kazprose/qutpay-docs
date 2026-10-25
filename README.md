# Qut Pay — Kaspi Pay payments API for Kazakhstan (docs & examples)

Qut Pay lets a business in Kazakhstan accept Kaspi payments on a website, in a mobile app, in Telegram or by a plain link — through the standard **"Cashier"** role of its own Kaspi Pay account. Invoices are created by REST API, the customer pays in the Kaspi.kz app (QR, link, or an invoice pushed to their phone number), and your server gets a signed webhook. Money settles directly in your Kaspi Pay account.

This repository holds the public documentation: the OpenAPI spec, the integration guide, and copy-paste examples. Service and cabinet: https://qut.kz · API: https://api.qut.kz

## Quick start
```bash
curl -X POST https://api.qut.kz/api/v1/invoices \
  -H "X-API-Key: qp_test_…" -H "Content-Type: application/json" \
  -d '{"amount": 2500, "externalOrderId": "A-1041", "description": "Order A-1041"}'
# → { "id": "inv_…", "status": "pending", "payUrl": "https://qut.kz/pay/…", "qr": "…" }
```
When the customer pays, `POST` to your webhook URL with header `X-Qutpay-Signature: sha256=<HMAC of raw body>`; verify with your webhook secret, then mark the order paid. Full flow, retries and idempotency: [`docs/integration.md`](docs/integration.md).

## Contents
- `openapi.json` — the API contract (also served live at https://api.qut.kz/openapi.json)
- [`docs/integration.md`](docs/integration.md) — step by step, with the webhook signature check in Node.js, PHP and Python
- [`docs/connect.ru.md`](docs/connect.ru.md) / [`docs/connect.kk.md`](docs/connect.kk.md) — how to prepare a Kaspi Pay cashier number (the one step people get wrong)
- `examples/` — minimal servers in Node.js, PHP, Python; WordPress/WooCommerce and OpenCart modules; n8n workflows
- `llms.txt` — the same guide formatted for AI coding agents

## Sandbox
Keys starting with `qp_test_` create invoices that never reach Kaspi and never move money; everything else (webhooks, refunds, receipts) behaves as in live mode. Switch to `qp_live_` when the integration is done.

## Pricing
Flat monthly subscription: Старт 4 990 ₸ for 800 invoices a month, Бизнес 14 900 ₸ for 4 000, Про 39 900 ₸ for 15 000, Партнёр 79 900 ₸ for 30 000 plus the Partner API (enabled by the platform on request). 7-day trial that starts with your first live invoice. The platform charges no percentage on payments; Kaspi's own fee for QR acquiring applies as it would without any service. Details: https://qut.kz/tarify/ (kk: https://qut.kz/kk/tarifter/)

## Trust & reliability
- Public status page, measured by an independent monitor every 5 minutes with the history in an open repository: https://status.qut.kz (source: https://github.com/kazprose/qutpay-status)
- Trust center — security measures, backups, the Kaspi dependency, what is not guaranteed (no independent audit yet, no contractual SLA): https://qut.kz/en/trust/ (ru: https://qut.kz/trust/, kk: https://qut.kz/kk/trust/); the same text is in [`docs/trust.en.md`](docs/trust.en.md)
- Liveness check without a key: `GET https://api.qut.kz/health`

## About
Qut Pay is an independent service and is not affiliated with JSC "Kaspi Bank". Kaspi and Kaspi Pay are trademarks of their owner. Access is limited to what the Cashier role allows (issue an invoice, see its status, refund your own invoice) and can be revoked from the cabinet at any time.

Questions: kazprose@gmail.com · WhatsApp +7 778 881 3333 · Telegram @qutpaybot

## Languages

- English: this README and `docs/integration.md` (written for people and for AI coding agents alike)
- Русский: `docs/connect.ru.md`, `docs/integration.ru.md`, `docs/tariffs.ru.md`, `docs/partner.ru.md`, `docs/trust.ru.md`; tariffs at https://qut.kz/tarify/
- English: `docs/integration.en.md`, `docs/connect.en.md`, `docs/tariffs-guide.en.md`, `docs/partner.en.md`, `docs/trust.en.md`
- Қазақша: `docs/connect.kk.md`, `docs/integration.kk.md`, тарифтер: https://qut.kz/tarifter/
- Knowledge base, 126 articles in both languages: https://api.qut.kz/kb · https://api.qut.kz/kb/ru

## Contributing

Found a mistake in the docs or an example that does not run? Open an issue or a pull request here. Questions about your own integration are faster through the cabinet's Support section.
