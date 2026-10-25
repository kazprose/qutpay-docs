# Tariffs, limits, light KYC

## Plans (`apps/api/src/services/tariffs.js` → `PLANS`)
| tier | Name | Price | Monthly limit | Daily cap | Note |
|---|---|---|---|---|---|
| trial | Trial | 0 | — | 50/day | 7 days; the period starts **from the first live invoice**, not at sign-up |
| start | Start | 4 990 ₸/month | 800 | 200 | |
| business | Business | 14 900 ₸/month | 4 000 | 1 500 | |
| pro | Pro | 39 900 ₸/month | 15 000 | 5 000 | |
| partner | Partner | 79 900 ₸/month | 30 000 | 2 000 | Partner API; the platform enables the tier, then the partner pays from the cabinet (Partnership page) |
| founder | Founder | — | unlimited | internal (qut.kz, first partners) |

The trial period does not start at sign-up: the organization's `tariff.expiresAt` field stays empty, and when the first **live** invoice is created (`activateTrialIfNeeded`) the 7 days are counted from that moment. Sandbox invoices do not start the period. A merchant who still has not started two days after signing up gets a one-time reminder (`runTrialNudges`); on the Platform page they are shown as "trial not started".

The main limit is **monthly**; it resets at the start of the calendar month (Almaty time). One busy day does not stop operations: this matters for a business with a seasonal peak.

The daily cap is a separate thing: it is not a business limit but a safeguard against an integration stuck in a loop. It is set several times above the monthly average and is not hit in normal operation.

The limit is checked only in **live** mode (`assertTariffAllows`): if the period has expired — `403 tariff_inactive`; if the monthly limit is used up — `429 tariff_limit_reached`; if the daily cap is reached — `429 tariff_daily_burst`. Sandbox is not limited.

When more than 80 percent of the monthly limit is used, the merchant gets a one-time warning via Telegram, and a usage bar is shown on the Tariff page in the cabinet. The warning is re-armed when the tariff is extended.

Tariffs bought earlier keep only the daily limit. For them the monthly limit is computed as `dailyLimit × 30`, so nobody's terms get worse.

## Buying a tariff
Cabinet → **Tariff** → choose a plan and the number of months → "Pay". The platform creates an invoice in the `PLATFORM_ORG_ID` organization (qut.kz) (`created_via = tariff`, `metadata.qutpay = 'tariff'`), and the merchant pays it via Kaspi. When the invoice becomes `paid`, `onInvoicePaid` activates the tariff:
- if the same tier is still active — the period is added on top of the current one; a different tier — starts now;
- the `tariff_orders` record becomes `paid`, `audit_log` → `tariff.paid`, and the merchant gets a Telegram notification.

An administrator (Platform → Organizations → "Tariff") can manually set any tier and number of days (`PATCH /panel/platform/orgs/:id/tariff`).

## Light KYC
Cabinet → Settings → **Legal details**: legal name, BIN/IIN (12 digits), contact details, website, type of business. When complete — `kyc_status = submitted`; an administrator sets `approved` / `needs_changes` (with a note). For now this does not block switching to live mode (as with competitors), but the legal name is shown on the receipt and in the offer agreement.
