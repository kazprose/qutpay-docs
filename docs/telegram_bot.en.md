# Telegram bot (@QutPayBot)

One platform bot shared by all organisations. You can issue invoices and receive payment notifications even without the cabinet.

## For merchants
1. Cabinet → **Settings** → **Telegram bot** → "Get link code" (valid for 15 minutes, single use).
2. In a private chat with the bot, or in a **group** the bot has been added to, send `/start CODE` (or open the link and press "Start").
3. This chat will receive: ✅ payments, ↩️ refunds, ⌛ expired invoices, ⚠️ Kaspi session interruptions (with a re-login link).

Commands:
| Command | What it does |
|---|---|
| `/invoice 5000` | Invoice with QR/link; the reply contains the payment page and the "Status" and "Cancel" buttons |
| `/invoice 5000 77001234567 Консультация` | Invoice sent to the customer's Kaspi app (to their phone), with a description |
| `/today` | Today's revenue, open invoices |
| `/last` | Last 10 invoices |
| `/status inv_…` / `/cancel inv_…` | Invoice status / cancel |
| `/support text` | Send a question to support; the reply comes to this chat |
| `/unlink` | Unlink the chat from the organisation |

In a private chat, simply writing `5000 Lesson` also creates an invoice. Aliases: `/счет`, `/шот`, `/бүгін`.
**Any member** of a group can create an invoice — you decide whom to add to the group. Refunds cannot be made from the bot (cabinet/API only).

## One tap via WhatsApp
On the sign-in page, "Sign in via WhatsApp" → `wa.me/<business-number>?text=Кіру: QP-<id>` opens → the client sends the prepared message → the Meta webhook (`messages`) arrives at our server → the sign-in is confirmed by the sender's number, and a text confirmation is sent to the client within the 24-hour window. No template, test list or business verification is needed. The **messages** subscription must be enabled in Meta → Configuration → Webhook fields.

## Signing in to the cabinet via Telegram
**One tap:** on the sign-in page, "Sign in via Telegram" → the bot opens → Start → "📱 Send my number" → the page signs in by itself (`/auth/telegram/start` → `t.me/bot?start=auth_<id>` → contact → `/auth/complete`). A person whose number is already linked is not asked for the contact. Alternative: phone number + the "Telegram" channel → the code arrives in the bot.

## For the platform administrator
1. @BotFather → `/newbot` → token. Cabinet → **Platform** → "Platform Telegram bot token" → Save (stored encrypted in the DB; `TELEGRAM_BOT_TOKEN` in `.env` also works).
2. "Set Telegram webhook" — registers `API_URL/telegram/webhook` (secret header `X-Telegram-Bot-Api-Secret-Token`, derived from TOKEN_SECRET_KEY). Repeated automatically when the server restarts.
3. @BotFather → `/setprivacy` → **Disable** — so that `/invoice` commands in a group reach the bot (or make the bot a group administrator).
4. Without https (dev) the bot works in long polling mode (`TELEGRAM_POLLING=1`).
5. **To receive alerts yourself,** on the Platform page use "Link Telegram" → "Open bot" (or send the bot the `/start adm_…` code, valid for 15 min). New sign-ups, new organisations, support requests and token/limit warnings go only to linked administrators; an unlinked administrator is shown in red on the page. This link is separate from the sign-in number (`tg_admin:<phone>`), so the number of your Telegram account may differ from the cabinet number.

## Internals
- `apps/api/src/services/telegram.js` — Bot API client, command handling, `broadcast()`.
- `apps/api/src/routes/telegram.js` — webhook. `panel.js` — `/panel/orgs/:id/telegram*`, `/panel/platform/telegram/set-webhook`.
- Tables: `telegram_chats` (chat → organisation), `telegram_link_codes`. Sign-in link: `platform_settings.tg_link:<phone>`; administrator alerts: `tg_admin:<phone>` (via code), otherwise `tg_link:<phone>`.
- Notifications in `notify.js` are sent in parallel to the chats of the organisation's own bot (settings) and of the platform bot; every send is recorded in the `notifications` table.
