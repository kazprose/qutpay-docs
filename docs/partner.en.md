# Referrals and Partner API

## Referral program (for every merchant)
- Cabinet → **Partnership** → your personal code and link `https://qut.kz/app/login?ref=CODE`.
- The first organization of a merchant who signs up through that link is attached to you (`referred_by`).
- Every time they pay for a tariff, **20 %** of the amount (`REFERRAL_PERCENT`) is credited to you (`referral_earnings`, status `accrued`).
- The payout is made manually by the platform: Platform → Rewards → "Paid out" (`paid_out`).

## Partner API (integrators, web studios, franchises)
Requirement: the organization is on the **"Partner"** tariff (set by the platform) and the API key has the `partner:manage` scope.

The Partner tariff is **79 900 ₸/month** for up to 30 000 invoices a month (client organizations' invoices are not counted — they go through the clients' own cashiers). The platform enables the tariff on request; the partner then pays for it from the cabinet's **Partnership** page via Kaspi. The same page has a **"Create client organization"** form and the client list: an organization can be opened without code by giving the owner's phone, and the client's API key is shown once; the list matches `GET /api/v1/partner/organizations`.

| Method | What it does |
|---|---|
| `POST /api/v1/partner/organizations` `{name, ownerPhone, ownerName?, legalName?, idn?}` | Opens an organization for the client: the owner's account (created if it does not exist), sandbox + a 7-day trial, the partner's owner becomes a `developer` member, the response contains `apiKey` (shown once) |
| `GET /api/v1/partner/organizations` | List of clients: mode, tariff, payment statistics, active Kaspi connections |
| `GET /api/v1/partner/organizations/{id}` | Organization + connections |
| `POST …/{id}/connections` → `…/{cid}/kaspi/init` → `send-phone {processId, phone}` → `verify-otp {processId, otp}` | Connecting the client's Kaspi "Кассир" (Cashier) (the SMS code is sent to the cashier's phone) |
| `POST …/{id}/mode {mode: 'live'}` | Switch to live mode (an active connection is required) |
| `POST …/{id}/api-keys {name, scopes?}` | A new key for the client |
| `GET /api/v1/partner/earnings` | Referral code, clients, accrued rewards |

The partner manages the client's invoices **with the client's key** (`/api/v1/invoices`), or views them by signing in to the cabinet (developer role).

Full description: https://api.qut.kz/docs (Partner tag).
