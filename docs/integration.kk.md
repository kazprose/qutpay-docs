# Сайтқа интеграция (Qut Pay API)

Әлі қосылмаған болсаңыз, алдымен [Қалай қосу керек](START.md) бетін оқыңыз — Kaspi кассирін жалғау сонда жазылған.

Толық спецификация: **https://api.qut.kz/docs** (OpenAPI: `/openapi.json`, AI-көмекшіге арналған қысқа нұсқаулық: `/for-ai`).

**Бағасы:** тұрақты айлық жазылым, платформа төлемнен пайыз алмайды — Бастау 4 990 ₸/ай (айына 800 счёт), Бизнес 14 900 ₸ (4 000), Про 39 900 ₸ (15 000); 7 күн тегін, бірінші нақты счёттан. Kaspi өз QR комиссиясын (0,95 %) бөлек ұстайды. Толығы: [Тарифтер](https://qut.kz/kk/tarifter/) · [Тарифы (ru)](https://qut.kz/tarify/).

Сайт екі жерде ғана сөйлеседі:

1. **Счёт жасау** — `POST https://api.qut.kz/api/v1/invoices`, клиентті `payUrl`-ге жібереді.
2. **Нәтижені алу** — webhook (`invoice.paid` т.б.) немесе `GET /api/v1/invoices/{id}`.

Аутентификация: `X-API-Key: <кілт>` (кабинет → API кілттері). Кілт `qp_live_…` (live) немесе `qp_test_…` (sandbox).

```
Сайт ──POST /api/v1/invoices──▶ Qut Pay ──QR/счёт──▶ Kaspi
 │◀── payUrl (redirect) ────────┘
клиент төлейді → Qut Pay 3 сек сайын тексереді
 │◀── POST webhook: invoice.paid (HMAC) ──┘
```

## 1. Счёт жасау

| Өріс | Тип | Сипаттама |
|---|---|---|
| `amount` | number ✅ | Теңге, ең көбі 2 ондық (телефонға счёт — бүтін) |
| `kind` | `qr` \| `phone` | `qr` (әдепкі): QR + сілтеме; `phone`: клиенттің Kaspi-іне push (`customer.phone` керек) |
| `description` | string | Клиент көреді (100 таңба; phone — 60) |
| `externalOrderId` | string | Сайттағы тапсырыс нөмірі, webhook-та қайта келеді |
| `customer.name/phone/email` | string | Email берілсе клиентке чек хаты (SMTP бапталса) |
| `successUrl`, `failUrl` | url | Тек http(s) |
| `metadata` | object | Кез келген JSON |
| header `Idempotency-Key` | string | Қайталауға қауіпсіз: сол кілт → сол счёт (HTTP 200, `idempotentReplay: true`) |

Жауап (201): `id`, `status: "pending"`, `payUrl`, `qrUrl`, `deepLink`, `qrImageUrl`, `expiresAt` (QR — Kaspi сканерлеу терезесі, ~3 мин).

Ескі `/api/v1/orders` жолы мен `merchantRef`, `method: "invoice"` өрістері де қабылданады (alias).

## 2. Webhook

Кабинет → Webhook → адрес қосу (продакшенде тек https және нақты домен). Құпия бір рет көрсетіледі.

```
POST /qutpay-webhook
X-Webhook-Event: invoice.paid
X-Webhook-Timestamp: 1757167000
X-Webhook-Signature: sha256=3f1a…
X-Webhook-Delivery: 17
{ "event": "invoice.paid", "invoice": { "id": "inv_…", "externalOrderId": "1001", "status": "paid", "amount": 2500, "metadata": {…}, "receiptUrl": "…", "paidAt": "…" }, "sentAt": "…" }
```

Қолтаңба: `HMAC-SHA256(secret, timestamp + "." + rawBody)`, hex. Дене өзгертілмеген байт күйінде тексерілсін. 5 минуттан ескі timestamp қабылданбасын. 2xx емес жауапта 11 рет қайталанады (10 с → 1 сағ). Өңдеу **идемпотентті** болсын: `(invoice.id, status)` жұбы бойынша.

**Кеш төлем.** `cancelled`/`expired` счётқа ақша кешігіп келсе, `invoice.paid` (`late: true`) кейін де келеді — қызметті беріңіз немесе қайтарыңыз.

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

`packages/sdk-php/src/QutPay.php` (бір файл, PHP 7.4+):

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

WordPress/WooCommerce дүкенге плагин бар: `packages/woocommerce-qutpay`.

### Python

`packages/sdk-python/qutpay.py` (стандартты кітапхана ғана, 3.8+):

```python
from qutpay import QutPay, verify_webhook
qp = QutPay(api_key=API_KEY)
inv = qp.create_invoice(amount=2500, description="Премиум", external_order_id="1001", success_url="https://site.kz/ok", idempotency_key="1001")
return redirect(inv["payUrl"])

# webhook (Flask / Django: request.get_data() / request.body)
if not verify_webhook(SECRET, request.get_data(), request.headers): abort(401)
```

## 3. Sandbox

Ұйым `sandbox` режимінде счёттар Kaspi-ге кетпейді. `POST /invoices/{id}/simulate {"status":"paid"}` немесе төлем бетіндегі «[Sandbox] төлеу» батырмасы төлемді имитациялайды. Webhook-тар шынайы жіберіледі (адрес http/localhost болуы мүмкін).

## 3.0. Бір ұйым, бірнеше жоба: кілтті кассирге байлау

Бір ұйымда бірнеше Kaspi кассирі және бірнеше жоба болса (мысалы, екі сайт), әр жобаға өз API кілтін жасап, оны **бір кассирге байлаңыз** (кабинет → Интеграциялар → кілт жасағанда «Кассир» таңдау, немесе `connectionId` өрісі). Сонда:

- сол кілтпен жасалған live счёттар тек сол кассир арқылы жүреді, басқа кассирге түспейді;
- кілт тек сол кассирдің live счёттарын көреді (`GET /invoices`, `GET /invoices/{id}`, қайтару, болдырмау) — басқа жобаның счёттары оған көрінбейді;
- webhook адресін де сол кілтке байласаңыз, әр жоба өз оқиғаларын ғана алады.

Sandbox счёттары кассирге тіркелмейді, олар ұйымның ортақ тест деректері. Кілті бар кассирді жою мүмкін емес — алдымен кілтті басқа кассирге ауыстырыңыз (`PATCH /panel/orgs/{org}/api-keys/{id}`, `connectionId`).

## 3.1. Кідіріске сезімтал болсаңыз

Клиент құрылғының алдында тұрып, төлегеннен кейін бірден нәтиже күтетін болса (повербанк станциясы, вендинг, турникет, шлагбаум), webhook-ты жалғыз механизм етпеңіз. Дұрысы — екеуін қатар қосу:

1. **Webhook** негізгі арна болып қалады, ол әдетте бірінші келеді.
2. Қатар счёт жасалған сәттен бастап **алғашқы 30 секундта** `GET /invoices/{id}` арқылы күйін сұрап отырыңыз, шамамен секундына бір рет.
3. Қайсысы бұрын келсе, соны қабылдап, екіншісін елемеңіз. Сондықтан өңдеу **идемпотентті** болуы керек: бір счёт екі рет «төленді» деп келгенде, құрылғы екі рет ашылмауы тиіс.

Отыз секундтан кейін сұрауды тоқтатып, тек webhook-қа сүйеніңіз. Бұл артық жүктеме жасамайды, ал клиенттің күту уақытын қысқартады.

Себебі қарапайым: біз төлемді Kaspi-ден сұрап отырып білеміз, ал сұрау циклі жүктемеге қарай өзгереді. Webhook сол білген сәтте кетеді, бірақ желі мен сіздің серверіңіздің жауап беруі де уақыт алады. Екі арна қатар жүргенде ең жылдамы жеңеді.

Егер счёт `metadata` ішіне станция немесе ұяшық нөмірін жазып қойсаңыз, ол webhook жауабында да, күй сұрауында да сол күйінде қайтады — қай құрылғыны ашу керегін бірден білесіз.

## 4. Қателер

`{ "error": "<код>", "message": "<мәтін>" }`. Негізгілері: `unauthorized`, `insufficient_scope`, `invalid_amount`, `invalid_phone`, `phone_required`, `amount_must_be_whole_tenge`, `invalid_url`, `invoice_not_found`, `invoice_not_open`, `invoice_not_refundable`, `kaspi_session_not_configured`, `kaspi_session_expired`, `tariff_limit_reached`, `request_rate_limited` (429 + `Retry-After`), `kaspi_error`, `refund_failed`.

## 5. Жазылым (кестелі счёт), bulk, форма-хуктар

- **Жазылым**: `POST /api/v1/subscriptions {amount, interval: month|week|day, every, dayOfMonth?, kind: phone|qr, customer:{phone,email,name}, maxRuns?, startAt?}` — әр кезеңде счёт автоматты жасалады (`kind: phone` → клиенттің Kaspi қосымшасына; `qr` → сілтеме, email болса хат). Басқару: `/pause`, `/resume` (`{"catchUp": true}` десеңіз, өткізіп алған кезек бірден орындалады; әйтпесе келесі кестеден жалғасады), `/cancel`, `/run` (кезектен тыс). **Қайталау және өткізіп алу саясаты**: счёт жасау сәтсіз болса (Kaspi жауап бермеді, сессия үзілді, тариф) `retryDelaysMin` сатысы бойынша қайталанады, әдепкісі `[15, 60, 360]` минут; саты біткен соң сол кезек тасталады, мерчантқа Telegram/email хабар кетеді, кесте жалғаса береді (жауапта `retryAttempt`, `failedRuns`, `lastError`, `lastRunStatus`: `ok | retrying | failed | skipped`). `misfirePolicy`: `run_once` (әдепкі) — кешіккен кезек бір рет орындалады; `skip` — `misfireAfterMin`-нен (әдепкі 1440) кеш болса өткізіліп, келесі кестеге көшеді. Счёт `metadata.subscriptionId` және `metadata.run` алып жүреді, webhook-тар әдеттегідей келеді.
- **Bulk**: `POST /api/v1/invoices/bulk {invoices:[…]}` — 100-ге дейін, жауап 207 және әр элемент бойынша нәтиже.
- **Форма-хуктар** (Tilda, Webflow, кез келген HTML форма): кабинет → Интеграциялар → «Форма-хук» → URL `https://api.qut.kz/hooks/form/<token>`. Форма өрістері еркін аталады (`amount|sum|price|Paymentsum`, `phone|Phone|tel`, `email`, `name`, `comment`); жауап `{ok, invoiceId, payUrl}`, `?redirect=1` болса — төлем бетіне 302. Tilda-ның «test» пингі қабылданады. `defaults.kind = phone` болса клиентке счёт Kaspi қосымшасына кетеді.
- **Partner API**: [docs/PARTNER.md](PARTNER.md).
- **1C**: [docs/1C.md](1C.md). **n8n**: `packages/n8n-qutpay`. **OpenCart**: `packages/opencart-qutpay`.

## 6. WordPress / WooCommerce

Дайын плагин: https://api.qut.kz/downloads/qutpay-for-woocommerce.zip. Толық орнату нұсқаулығы (кілт, webhook, баптау, сынау, қателер): [WORDPRESS.md](WORDPRESS.md) → https://api.qut.kz/docs/guide/wordpress. OpenCart 4: https://api.qut.kz/downloads/qutpay.ocmod.zip (README архивтің ішінде).
