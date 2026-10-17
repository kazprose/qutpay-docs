# Qut Pay для n8n

Два готовых workflow для n8n 1.x (self-hosted или Cloud). Ничего устанавливать не нужно — только стандартные узлы (Webhook, Set, HTTP Request, Code, IF, Respond to Webhook).

| Файл | Что делает |
|---|---|
| `workflows/qutpay-create-invoice.json` | `POST /webhook/qutpay-order` → создаёт счёт в Qut Pay → отвечает `{ id, payUrl, qrUrl, … }` |
| `workflows/qutpay-webhook-receiver.json` | `POST /webhook/qutpay-events` ← события Qut Pay; проверяет HMAC-подпись, ветвит по `invoice.paid`, отвечает 200 |

Спецификация API: https://api.qut.kz/docs · общая инструкция по интеграции: `docs/INTEGRATION.md`.

## 1. Импорт

n8n → **Workflows → Add workflow → ⋯ → Import from File** → выбрать JSON. Повторить для второго файла.
(Или скопировать содержимое файла и вставить `Ctrl+V` на пустой холст.)

После импорта workflow неактивны — сначала настройте credential и секрет (шаги 2–3), затем включите переключатель **Active**.

## 2. Credential «Qut Pay API key» (Header Auth)

Ключ берётся в кабинете Qut Pay → **API кілттері → Создать**. Для теста создайте ключ в режиме sandbox (`qp_test_…`), для продакшена — `qp_live_…`.

n8n → **Credentials → Add credential → Header Auth**:

| Поле | Значение |
|---|---|
| Credential name | `Qut Pay API key` (ровно так — узел ссылается на это имя) |
| Name | `X-API-Key` |
| Value | `qp_live_…` или `qp_test_…` |

Откройте workflow «Qut Pay — создать счёт», узел **«Qut Pay: создать счёт»** → в поле *Credential for Header Auth* выберите созданный credential (после импорта он показан красным, пока не выбран).

## 3. Секрет webhook

Секрет выдаётся кабинетом один раз при добавлении адреса webhook (шаг 4). Узел «Проверка подписи» ищет его в таком порядке:

1. переменная окружения `QUTPAY_WEBHOOK_SECRET` (`$env`) — self-hosted;
2. n8n **Variables** `QUTPAY_WEBHOOK_SECRET` (`$vars`) — Cloud или если доступ к `$env` закрыт.

Self-hosted (docker-compose / `.env` n8n):

```
QUTPAY_WEBHOOK_SECRET=whsec_…
N8N_BLOCK_ENV_ACCESS_IN_NODE=false     # иначе $env недоступен в Code-узле
NODE_FUNCTION_ALLOW_BUILTIN=crypto     # разрешить require('crypto') в Code-узле
```

Перезапустите n8n после изменения переменных. В n8n Cloud `crypto` уже разрешён, а секрет задайте через **Settings → Variables**.

## 4. Регистрация адреса webhook в кабинете Qut Pay

1. Активируйте workflow «Qut Pay — приём webhook».
2. Откройте узел «Webhook: события Qut Pay» → скопируйте **Production URL**, например `https://n8n.example.kz/webhook/qutpay-events`.
   *Test URL* (`/webhook-test/…`) работает только пока нажата кнопка «Listen for test event» — для кабинета он не подходит.
3. Кабинет Qut Pay → **Webhook → Добавить адрес** → вставьте URL → сохраните **секрет** (показывается один раз) → шаг 3.
4. Кнопка «Отправить тестовое событие» в кабинете шлёт `webhook.test` — в n8n оно пройдёт проверку подписи и попадёт в ветку «Другие события» с ответом 200. Если приходит 401 — секрет не совпадает или часы сервера n8n расходятся более чем на 5 минут.

В продакшене адрес должен быть `https://` с реальным доменом. В sandbox допустим `http://` и localhost (например, через туннель `N8N_EDITOR_BASE_URL`/`WEBHOOK_URL`).

## 5. Проверка в sandbox

Переведите организацию в режим sandbox (кабинет), используйте ключ `qp_test_…`.

**Создать счёт через n8n:**

```bash
curl -X POST https://n8n.example.kz/webhook/qutpay-order \
  -H 'Content-Type: application/json' \
  -d '{"amount": 2500, "description": "Заказ 1001", "externalOrderId": "1001",
       "customer": {"name": "Асан", "phone": "+77011234567", "email": "asan@example.kz"},
       "successUrl": "https://site.kz/ok", "failUrl": "https://site.kz/fail",
       "metadata": {"cart": 42}}'
```

Ответ (201): `{ "id": "inv_…", "status": "pending", "payUrl": "https://api.qut.kz/pay/inv_…", "qrUrl": "…", "deepLink": "…", "expiresAt": "…" }`. Клиента отправляйте на `payUrl`.

**Имитировать оплату** (только sandbox; вместо `inv_…` — `id` из ответа):

```bash
curl -X POST https://api.qut.kz/api/v1/invoices/inv_…/simulate \
  -H 'X-API-Key: qp_test_…' -H 'Content-Type: application/json' \
  -d '{"status": "paid"}'
```

Допустимые статусы: `paid`, `failed`, `expired`. Через несколько секунд Qut Pay отправит `invoice.paid` на ваш n8n — проверьте **Executions** workflow «Qut Pay — приём webhook»: ветка «Оплачен? → Оплачено: ваше действие» должна выполниться, в `$json` будут `externalOrderId`, `amount`, `invoice.receiptUrl`, `invoice.paidAt`, `late`.

Альтернатива без curl: откройте `payUrl` в браузере и нажмите «[Sandbox] төлеу».

## 6. Что менять под себя

**Создание счёта.** Узел «Поля счёта» принимает тело входящего запроса (`$json.body.*`) — поменяйте выражения, если ваш источник (CRM, Tilda, форма) присылает другие имена полей. `Idempotency-Key` по умолчанию `n8n-<externalOrderId>` — повторный запрос с тем же номером заказа вернёт тот же счёт (HTTP 200, `idempotentReplay: true`), а не создаст новый. `kind: "phone"` (счёт прямо в Kaspi клиента) добавьте в JSON-тело узла «Qut Pay: создать счёт», при этом `customer.phone` обязателен, а сумма — целая.

**Приём webhook.** Замените узел «Оплачено: ваше действие» (NoOp) на своё: Google Sheets, Gmail, HTTP Request в CRM/1С, Telegram (пример-заглушка есть, отключён). Остальные события (`invoice.failed`, `invoice.expired`, `invoice.cancelled`, `invoice.refunded`, `invoice.partially_refunded`, `webhook.test`) уходят в узел «Другие события» — при необходимости добавьте Switch по `$json.event`.

**Идемпотентность.** Одно событие может прийти повторно (ретраи 11 раз при не-2xx ответе). Ключ дедупликации — пара `(invoice.id, status)`. Оплата может прийти и после `expired`/`cancelled` — тогда в `invoice` есть `late: true`: выдайте товар или сделайте возврат.

## 7. Если что-то не работает

| Симптом | Причина / решение |
|---|---|
| `QUTPAY_WEBHOOK_SECRET не задан` | Нет `$env`/`$vars` с секретом или `N8N_BLOCK_ENV_ACCESS_IN_NODE=true` |
| `Модуль crypto недоступен` | Добавьте `NODE_FUNCTION_ALLOW_BUILTIN=crypto` и перезапустите n8n |
| `В узле Webhook включите опцию «Raw Body»` | Опция Webhook → Options → Raw Body должна быть включена: подпись считается по исходным байтам тела |
| `X-Webhook-Timestamp вне допуска` | Часы сервера n8n расходятся с реальным временем > 5 мин — настройте NTP |
| `Подпись webhook неверна` | Секрет от другого адреса/организации, либо тело изменено прокси (сжатие, переформатирование JSON) |
| В кабинете доставка `HTTP 404` | Workflow не активирован, или указан Test URL вместо Production URL |
| `401 unauthorized` при создании счёта | Неверный ключ или credential не выбран в узле HTTP Request |
| `429 request_rate_limited` | Слишком много запросов — уважайте `Retry-After` |
