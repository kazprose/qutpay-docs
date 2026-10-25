# WordPress / WooCommerce: accepting payments via Kaspi (Qut Pay plugin)

The plugin adds a "Pay via Kaspi" payment method to your WooCommerce store: the buyer selects Kaspi at checkout → goes to the Qut Pay payment page (QR; on a phone, one tap to pay in Kaspi) → the moment the payment is made, the order automatically becomes "Обработка/Выполнен" (Processing/Completed), the receipt goes to the buyer, and you get a Telegram notification. The money goes directly to your Kaspi Pay account.

**Requirements:** WordPress 6.0+, WooCommerce 7.0+ (classic checkout, Checkout Blocks and HPOS all work), PHP 7.4+, the site is served over **https**, store currency is **KZT (₸)**. An organisation in the Qut Pay cabinet with a Kaspi "Кассир" (Cashier) connected (no Cashier is needed for sandbox testing).

Time: 10–15 minutes.

## Step 1. Install the plugin

1. Download the archive: **https://api.qut.kz/downloads/qutpay-for-woocommerce.zip**
2. WordPress → **Плагины → Добавить новый → Загрузить плагин** (Plugins → Add New → Upload Plugin) → zip → **Установить** (Install) → **Активировать** (Activate).
3. If the plugin list contains an old/broken "qutpay-for-woocommerce" entry (for example, "Файл плагина не найден" — plugin file not found), delete it first, if necessary delete the `wp-content/plugins/qutpay-for-woocommerce` folder on your hosting, then upload it again.

## Step 2. Key and webhook in the Qut Pay cabinet

Cabinet: **https://qut.kz/app/** (sign in with phone number + code).

1. **Integrations → API keys**: name — your site (for example `qazaqtoday.info`) → **Create**. The key is shown once; copy it. A sandbox organisation gets `qp_test_…`, a live one gets `qp_live_…`.
2. **Integrations → Webhook**: in the address field enter
   ```
   https://YOUR-SITE/wp-json/qutpay/v1/webhook
   ```
   In the "Key" list select the key you have just created (then only this site's invoices will be delivered) → **Create**. Copy the **secret (whsec_…)** that is shown; it is also displayed only once.
   If "Постоянные ссылки" (Permalinks) are disabled on the site, the address is `https://YOUR-SITE/?rest_route=/qutpay/v1/webhook`.

## Step 3. WooCommerce settings

**WooCommerce → Настройки → Платежи → «Qut Pay (Kaspi QR)» → Управление** (WooCommerce → Settings → Payments → "Qut Pay (Kaspi QR)" → Manage):

| Field | What to enter |
|---|---|
| Включить (Enable) | ✔ |
| Название на кассе (Title at checkout) | the name the buyer sees; the default can be left as is |
| Описание (Description) | a short explanation shown at checkout |
| API-ключ (API key) | the key from step 2 |
| Секрет webhook (Webhook secret) | `whsec_…` from step 2 |
| Адрес API (API address) | `https://api.qut.kz` — do not change |
| Статус после оплаты (Status after payment) | physical goods — **Обработка (processing)**, digital service/course — **Выполнен (completed)** |
| Лог (Log) | ✔ while testing (WooCommerce → Статус → Логи → `qutpay`) |

**Сохранить изменения** (Save changes). Check that WooCommerce → Настройки → Общие → Валюта (Settings → General → Currency) = **Казахстанский тенге (KZT)** (Kazakhstani tenge), otherwise the method will not appear at checkout.

## Step 4. Testing

**Sandbox (no real money):** while the organisation in the cabinet is in `sandbox` mode, invoices are not sent to Kaspi. Place an order in the store and choose the Kaspi method → on the Qut Pay payment page press the **"[Sandbox] simulate payment"** button → you return to the site, the order becomes "Обработка/Выполнен" (Processing/Completed), and the order notes show "Qut Pay: оплачен, счёт inv_…" (paid, invoice inv_…).

**Live (real payments):** in the cabinet, **Kaspi → Add Cashier** (with an SMS code), then switch the mode to **Live**, create a live key (`qp_live_…`) and put it into the plugin. Create a test product for 10 ₸ and pay for it from your own phone: within 3 seconds the order becomes paid, the receipt link appears on the "Thank you" page, and you get a Telegram notification. Then refund the order from the cabinet (**Invoices → … → Refund**) and check that in WooCommerce it became "Возвращён" (Refunded).

## How it works

- When an order is placed, the plugin calls `POST /api/v1/invoices` (`externalOrderId` = order number, `Idempotency-Key` = `wc-{id}-…`, so a double click does not create two invoices) and redirects the buyer to `payUrl`.
- The invoice stays in the `pending` state for 3 minutes (the Kaspi QR lifetime). If it is not paid → `expired` → the order becomes "Отменён" (Cancelled); if a late payment arrives afterwards, the `invoice.paid` webhook sets the order back to paid.
- For every webhook event, `X-Webhook-Signature` (HMAC-SHA256) and `X-Webhook-Timestamp` are verified; an invalid signature → 401. If the same event arrives twice, the order is not changed twice.
- On the "Thank you" page the plugin re-queries the status from the API (so the order is shown correctly even if the webhook is delayed).
- Refunds: "Возврат" (Refund) in the order card → the plugin calls `POST /invoices/{id}/refund` (the key must have the `refunds:write` permission — tick "allow refunds" when creating the key).

## Common problems

| Symptom | Cause / fix |
|---|---|
| The Kaspi method is not visible at checkout | Currency is not KZT; the plugin is not enabled; the key is empty |
| "Файл плагина не найден" (plugin file not found) | Old archive (built on Windows). Delete the plugin folder and upload the new zip |
| The order stays in "Ожидает оплаты" (Pending payment) | The webhook is not registered in the cabinet or the secret does not match. Cabinet → Integrations → Webhook → "Delivery log": 401 — wrong secret, 404 — wrong address (`/wp-json/qutpay/v1/webhook`), 5xx — site error (Логи → qutpay) |
| "timeout" in the webhook log | The site takes longer than 8 seconds to respond (caching plugin/protection). If you use Cloudflare, set the `/wp-json/qutpay/` path to "Bypass" |
| `kaspi_session_expired` | The Cashier session in the cabinet has been interrupted: Kaspi → Cashier → sign in again with an SMS code |
| `tariff_limit_reached` | The daily limit is used up: extend it on the Tariff page |
| Invoices are not created in live but are created in sandbox | The plugin has a sandbox key (`qp_test_`) — enter a live key |

Questions: https://t.me/qutpaybot or kazprose@gmail.com. Full API description: https://api.qut.kz/docs
