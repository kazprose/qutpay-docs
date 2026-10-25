# Trust center

This page collects what can be checked, not what we ask you to believe. Where a fact cannot be verified through a link, we say so. The page changes whenever something listed here changes.

## Status and availability

The availability of api.qut.kz, the site, the cabinet, the docs and the payment page is measured by an **independent monitor**: [Upptime](https://upptime.js.org) runs in GitHub Actions every 5 minutes, and every check and every incident is committed to a public repository — the history cannot be edited after the fact.

- Status page: **[status.qut.kz](https://status.qut.kz)** — current state, response time, uptime over 7 days, 30 days and a year, incidents with recovery time.
- Check history: [github.com/kazprose/qutpay-status](https://github.com/kazprose/qutpay-status).
- Liveness check without a key: `GET https://api.qut.kz/health` → `{"status":"ok"}` (it checks the database too).

Honest about numbers: monitoring started on 25 October 2026. Until 30 days of data exist we do not write "99.9 %" anywhere. The target is 99.5 % per month; it is **not a contractual commitment** — see "What we do not guarantee".

## Security measures

| What | How |
|---|---|
| Money | Never passes through Qut Pay. An invoice is issued on behalf of your Kaspi Pay cashier and the customer pays straight into your Kaspi business account. The platform has no access to your funds. |
| Cashier session | Stored encrypted (AES-256-GCM); the Kaspi Pay password is neither stored nor requested; login is by an SMS code the cashier enters. |
| API keys | Only a hash is stored; the key is shown once. `qp_live_` / `qp_test_` prefixes separate real and sandbox payments. Permissions are limited by scopes (for example read-only). |
| Webhooks | Every notification is signed with HMAC-SHA256 and a timestamp; up to 11 retries over ~2 hours, an endpoint pauses after 5 consecutive failures. |
| Cabinet login | WhatsApp button, one-time code, Telegram or Google; HttpOnly session cookie, HTTPS only. A separate audit log per organization. |
| Overload protection | Request limits per key and per IP, a per-invoice amount cap, a daily guard against an integration stuck in a loop. |
| Infrastructure | Servers in Kazakhstan, TLS everywhere, an external watchdog every 5 minutes with automatic restart and an administrator alert in Telegram. |
| Personal data | Only what invoicing and notifications need; never sold or shared with anyone except Kaspi (the invoice) and WhatsApp/Telegram (notifications). Details: [privacy policy](https://api.qut.kz/docs/guide/privacy). |

**No independent security audit has been performed yet.** The architecture and measures are described openly (this page, the [integration docs](https://api.qut.kz/docs/guide/en/integration)); when an audit happens, the auditor, the date and a public version of the report will appear here.

Found a vulnerability? Write to [kazprose@gmail.com](mailto:kazprose@gmail.com) with the subject "security". We answer within 3 business days and do not pursue good-faith researchers.

## Backups and incidents

- The database is backed up daily; copies are kept for 14 days. Off-site storage is being added — for now the copies live on the same server, which we treat as a gap, not a norm.
- Outages are detected by the external watchdog (every 5 minutes) and the status monitor; the administrator is alerted in Telegram and the service restarts automatically when needed.
- Incidents and maintenance appear on [status.qut.kz](https://status.qut.kz) automatically when a check fails and close on recovery, with the duration.
- Support: WhatsApp [+7 778 881 3333](https://wa.me/77788813333), Telegram [@qutpaybot](https://t.me/qutpaybot), [kazprose@gmail.com](mailto:kazprose@gmail.com), 09:00–21:00 Almaty time.

## The Kaspi dependency — the main risk

Qut Pay is an **independent service, not a Kaspi Bank partner**. The automation works through the standard "Cashier" role of your Kaspi Pay business account: the service does what an employee with a phone would do, without the phone. Kaspi has no public API for third-party services and may restrict such access, or a cashier with an abnormally large flow. Know this before connecting:

- the cashier needs a separate SIM with the single "Cashier" role; the owner's number does not qualify;
- after a Kaspi Pay app update the session may require a new login — we track versions and warn;
- if Kaspi disables the cashier, invoices stop until it is reconnected; money already received on your account is unaffected.

We do not hide this risk in the docs or in the [offer](https://api.qut.kz/docs/guide/offer), because hiding it would shift the consequences onto you.

## Openness

- Public docs, OpenAPI and examples: [github.com/kazprose/qutpay-docs](https://github.com/kazprose/qutpay-docs) (MIT).
- Knowledge base: 138 articles in Kazakh and Russian — [api.qut.kz/kb/ru](https://api.qut.kz/kb/ru), machine-readable index [kb/index.json](https://api.qut.kz/kb/index.json).
- Guides in Russian, Kazakh and English: [api.qut.kz/docs/guide/en/start](https://api.qut.kz/docs/guide/en/start).
- For AI assistants: [api.qut.kz/for-ai](https://api.qut.kz/for-ai), [llms.txt](https://qut.kz/llms.txt), [OpenAPI](https://api.qut.kz/openapi.json).
- Pricing with no hidden terms: [qut.kz/tarify](/tarify/) (Russian). The platform takes no percentage of payments; Kaspi's QR fee is charged by Kaspi separately.

## What we do not guarantee

- Uninterrupted 24/7 operation. The service is provided "as is" ([offer, clause 5.1](https://api.qut.kz/docs/guide/offer)); there is no contractual SLA. Actual availability is on the status page.
- That Kaspi will not change its rules. See the risk section above.
- Issuing fiscal receipts on your behalf: by law the seller issues the receipt; the easiest route is the free Kaspi Касса — [how](https://api.qut.kz/docs/guide/en/fiscal).

There are no customer reviews on this page on purpose: the service has accepted real payments since October 2026, and we publish only reviews given with consent and a company name, with no discount for a review. When they exist, they will be here.
