# Интеграция с сайтом (Qut Pay API)

Если вы ещё не подключились, сначала прочитайте страницу [Как подключиться](START.md) — там описано, как подключить кассира Kaspi.

Полная спецификация: **https://api.qut.kz/docs** (OpenAPI: `/openapi.json`, краткое руководство для AI-ассистента: `/for-ai`).

**Стоимость:** фиксированная месячная подписка, платформа не берёт процент с платежа — Старт 4 990 ₸/мес (800 счетов в месяц), Бизнес 14 900 ₸ (4 000), Про 39 900 ₸ (15 000); 7 дней бесплатно, начиная с первого реального счёта. Kaspi удерживает свою комиссию за QR (0,95 %) отдельно. Подробнее: [Тарифтер](https://qut.kz/kk/tarifter/) · [Тарифы (ru)](https://qut.kz/tarify/).

Сайт взаимодействует с сервисом только в двух точках:

1. **Создание счёта** — `POST https://api.qut.kz/api/v1/invoices`, клиент перенаправляется на `payUrl`.
2. **Получение результата** — webhook (`invoice.paid` и др.) или `GET /api/v1/invoices/{id}`.

Аутентификация: `X-API-Key: <ключ>` (кабинет → API-ключи). Ключ `qp_live_…` (live) или `qp_test_…` (sandbox).

```
Сайт ──POST /api/v1/invoices──▶ Qut Pay ──QR/счёт──▶ Kaspi
 │◀── payUrl (redirect) ────────┘
клиент платит → Qut Pay проверяет каждые 3 сек
 │◀── POST webhook: invoice.paid (HMAC) ──┘
```

## 1. Создание счёта

| Поле | Тип | Описание |
|---|---|---|
| `amount` | number ✅ | Тенге, не более 2 знаков после запятой (счёт на телефон — целое число) |
| `kind` | `qr` \| `phone` | `qr` (по умолчанию): QR + ссылка; `phone`: push в Kaspi клиента (нужен `customer.phone`) |
| `description` | string | Видит клиент (100 символов; phone — 60) |
| `externalOrderId` | string | Номер заказа на сайте, возвращается в webhook |
| `customer.name/phone/email` | string | Если указан email, клиенту уходит письмо с чеком (если настроен SMTP) |
| `successUrl`, `failUrl` | url | Только http(s) |
| `metadata` | object | Любой JSON |
| header `Idempotency-Key` | string | Безопасно при повторах: тот же ключ → тот же счёт (HTTP 200, `idempotentReplay: true`) |

Ответ (201): `id`, `status: "pending"`, `payUrl`, `qrUrl`, `deepLink`, `qrImageUrl`, `expiresAt` (QR — окно сканирования Kaspi, ~3 мин).

Старый путь `/api/v1/orders` и поля `merchantRef`, `method: "invoice"` тоже принимаются (alias).

## 2. Webhook

Кабинет → Webhook → добавить адрес (в продакшене только https и реальный домен). Секрет показывается один раз.

```
POST /qutpay-webhook
X-Webhook-Event: invoice.paid
X-Webhook-Timestamp: 1757167000
X-Webhook-Signature: sha256=3f1a…
X-Webhook-Delivery: 17
{ "event": "invoice.paid", "invoice": { "id": "inv_…", "externalOrderId": "1001", "status": "paid", "amount": 2500, "metadata": {…}, "receiptUrl": "…", "paidAt": "…" }, "sentAt": "…" }
```

Подпись: `HMAC-SHA256(secret, timestamp + "." + rawBody)`, hex. Тело проверяйте в неизменённом виде, байт в байт. Timestamp старше 5 минут не принимайте. При ответе, отличном от 2xx, доставка повторяется 11 раз (10 с → 1 ч). Обработка должна быть **идемпотентной**: по паре `(invoice.id, status)`.

**Поздний платёж.** Если деньги по счёту `cancelled`/`expired` приходят с опозданием, `invoice.paid` (`late: true`) всё равно придёт позже — окажите услугу или сделайте возврат.

### Node.js

```js
import { QutPay, verifyWebhook } from '@qutpay/sdk'; // packages/sdk
const qp = new QutPay({ apiKey: process.env.QUTPAY_API_KEY });

app.post('/buy', async (req, res) => {
  const inv = await qp.createInvoice({ amount: 2500, description: 'Премиум', externalOrderId: `o-${order.id}`, successUrl: 'https://site.kz/ok', idempotencyKey: `o-${order.id}` });
  res.redirect(inv.payUrl);
});

app.post('/qutpay-webhook', express.raw({ type: '*/*' }), (req, res) => {
  if (!verifyWebhook({ secret: process.env.QUTPAY_WEBHOOK_SECRET, rawBody: req.body, headers: req.headers })) return res.sendStatus(401);
  const { event, invoice } = JSON.parse(req.body);
  if (event === 'invoice.paid') activate(invoice.externalOrderId);
  res.sendStatus(200);
});
```

### PHP

`packages/sdk-php/src/QutPay.php` (один файл, PHP 7.4+):

```php
require 'QutPay.php';
$qp = new \QutPay\Client($apiKey);
$inv = $qp->createInvoice(['amount' => 2500, 'description' => 'Премиум', 'externalOrderId' => '1001', 'successUrl' => 'https://site.kz/ok', 'idempotencyKey' => '1001']);
header('Location: ' . $inv['payUrl']);

// webhook
$raw = file_get_contents('php://input');
if (!\QutPay\Webhook::verify($secret, $raw, getallheaders())) { http_response_code(401); exit; }
$e = json_decode($raw, true);
if ($e['event'] === 'invoice.paid') activate($e['invoice']['externalOrderId']);
```

Для магазина на WordPress/WooCommerce есть плагин: `packages/woocommerce-qutpay`.

### Python

`packages/sdk-python/qutpay.py` (только стандартная библиотека, 3.8+):

```python
from qutpay import QutPay, verify_webhook
qp = QutPay(api_key=API_KEY)
inv = qp.create_invoice(amount=2500, description="Премиум", external_order_id="1001", success_url="https://site.kz/ok", idempotency_key="1001")
return redirect(inv["payUrl"])

# webhook (Flask / Django: request.get_data() / request.body)
if not verify_webhook(SECRET, request.get_data(), request.headers): abort(401)
```

## 3. Sandbox

В режиме `sandbox` счета организации не уходят в Kaspi. `POST /invoices/{id}/simulate {"status":"paid"}` или кнопка «[Sandbox] оплатить» на странице оплаты имитируют платёж. Webhook'и отправляются по-настоящему (адрес может быть http/localhost).

## 3.0. Одна организация, несколько проектов: привязка ключа к кассиру

Если в одной организации несколько кассиров Kaspi и несколько проектов (например, два сайта), создайте для каждого проекта свой API-ключ и **привяжите его к одному кассиру** (кабинет → Интеграции → при создании ключа выберите «Кассир», или поле `connectionId`). Тогда:

- live-счета, созданные этим ключом, проходят только через этого кассира и не попадают к другому;
- ключ видит только live-счета этого кассира (`GET /invoices`, `GET /invoices/{id}`, возврат, отмена) — счета другого проекта ему не видны;
- если привязать к этому ключу и адрес webhook, каждый проект будет получать только свои события.

Sandbox-счета к кассиру не привязываются — это общие тестовые данные организации. Кассира, к которому привязан ключ, удалить нельзя — сначала переведите ключ на другого кассира (`PATCH /panel/orgs/{org}/api-keys/{id}`, `connectionId`).

## 3.1. Если для вас важна задержка

Если клиент стоит перед устройством и ждёт результата сразу после оплаты (станция пауэрбанков, вендинг, турникет, шлагбаум), не делайте webhook единственным механизмом. Правильнее включить оба канала параллельно:

1. **Webhook** остаётся основным каналом, обычно он приходит первым.
2. Параллельно с момента создания счёта **в первые 30 секунд** опрашивайте статус через `GET /invoices/{id}`, примерно раз в секунду.
3. Что пришло раньше, то и принимайте, второе игнорируйте. Поэтому обработка должна быть **идемпотентной**: если один и тот же счёт дважды пришёл как «оплачен», устройство не должно открыться дважды.

Через тридцать секунд прекратите опрос и полагайтесь только на webhook. Это не создаёт лишней нагрузки, а время ожидания клиента сокращает.

Причина проста: о платеже мы узнаём, опрашивая Kaspi, а цикл опроса меняется в зависимости от нагрузки. Webhook уходит в тот же момент, когда мы узнали о платеже, но сеть и ответ вашего сервера тоже занимают время. Когда оба канала работают параллельно, побеждает самый быстрый.

Если вы записали в `metadata` счёта номер станции или ячейки, он вернётся в том же виде и в webhook, и в ответе на запрос статуса — вы сразу будете знать, какое устройство открыть.

## 4. Ошибки

`{ "error": "<код>", "message": "<текст>" }`. Основные: `unauthorized`, `insufficient_scope`, `invalid_amount`, `invalid_phone`, `phone_required`, `amount_must_be_whole_tenge`, `invalid_url`, `invoice_not_found`, `invoice_not_open`, `invoice_not_refundable`, `kaspi_session_not_configured`, `kaspi_session_expired`, `tariff_limit_reached`, `request_rate_limited` (429 + `Retry-After`), `kaspi_error`, `refund_failed`.

## 5. Подписка (счёт по расписанию), bulk, форма-хуки

- **Подписка**: `POST /api/v1/subscriptions {amount, interval: month|week|day, every, dayOfMonth?, kind: phone|qr, customer:{phone,email,name}, maxRuns?, startAt?}` — счёт создаётся автоматически в каждом периоде (`kind: phone` → в приложение Kaspi клиента; `qr` → ссылка, при наличии email — письмо). Управление: `/pause`, `/resume` (если передать `{"catchUp": true}`, пропущенный запуск выполняется сразу; иначе работа продолжается по следующему расписанию), `/cancel`, `/run` (внеочередной запуск). **Политика повторов и пропусков**: если создать счёт не удалось (Kaspi не ответил, сессия прервана, тариф), попытка повторяется по ступеням `retryDelaysMin`, по умолчанию `[15, 60, 360]` минут; после исчерпания ступеней этот запуск отбрасывается, мерчанту уходит уведомление в Telegram/email, расписание продолжается (в ответе `retryAttempt`, `failedRuns`, `lastError`, `lastRunStatus`: `ok | retrying | failed | skipped`). `misfirePolicy`: `run_once` (по умолчанию) — опоздавший запуск выполняется один раз; `skip` — если опоздание больше `misfireAfterMin` (по умолчанию 1440), запуск пропускается и работа переходит к следующему расписанию. Счёт несёт `metadata.subscriptionId` и `metadata.run`, webhook'и приходят как обычно.
- **Bulk**: `POST /api/v1/invoices/bulk {invoices:[…]}` — до 100 счетов, ответ 207 и результат по каждому элементу.
- **Форма-хуки** (Tilda, Webflow, любая HTML-форма): кабинет → Интеграции → «Форма-хук» → URL `https://api.qut.kz/hooks/form/<token>`. Поля формы могут называться свободно (`amount|sum|price|Paymentsum`, `phone|Phone|tel`, `email`, `name`, `comment`); ответ `{ok, invoiceId, payUrl}`, при `?redirect=1` — 302 на страницу оплаты. Пинг «test» от Tilda принимается. Если `defaults.kind = phone`, счёт уходит клиенту в приложение Kaspi.
- **Partner API**: [docs/PARTNER.md](PARTNER.md).
- **1C**: [docs/1C.md](1C.md). **n8n**: `packages/n8n-qutpay`. **OpenCart**: `packages/opencart-qutpay`.

## 6. WordPress / WooCommerce

Готовый плагин: https://api.qut.kz/downloads/qutpay-for-woocommerce.zip. Полная инструкция по установке (ключ, webhook, настройка, тестирование, ошибки): [WORDPRESS.md](WORDPRESS.md) → https://api.qut.kz/docs/guide/wordpress. OpenCart 4: https://api.qut.kz/downloads/qutpay.ocmod.zip (README внутри архива).
