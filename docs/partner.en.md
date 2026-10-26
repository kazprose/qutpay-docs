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
| `GET …/{id}/members`, `POST …/{id}/members {phone|email, role: manager|developer, name?}`, `DELETE …/{id}/members/{accountId}` | The client's staff: list, add, remove. Only the client changes its owner; at most 10 members per organization |
| `DELETE /api/v1/partner/organizations/{id}` | Close (archive) a client opened by mistake — only while it was never used: no invoices, the owner never signed in. Otherwise `409 client_in_use`: the client's owner closes it in the cabinet (Settings → "Close organization"). Closing = archiving: invoices and the audit log stay, keys and cashiers stop |
| `GET /api/v1/partner/earnings` | Referral code, clients, accrued rewards |

The partner manages the client's invoices **with the client's key** (`/api/v1/invoices`), or views them by signing in to the cabinet.

**The partner role.** The owner and developers of the partner organization automatically act in every client organization with the role "partner" (clients appear in the organization switcher). It ranks above developer: creates keys, webhooks and invoices, may pay the tariff, adds and removes the client's managers/developers — but cannot change or remove the owner or close the organization. When someone leaves the partner organization, access to all clients ends at once; partner staff with the manager role do not enter clients. The client's owner can end the partner's access at any time (Settings → "Partner access"): the referral link stays, members added by the partner stay. The Partnership page in the cabinet has a "Staff" section for every client.

Full description: https://api.qut.kz/docs (Partner tag).
