# Qut Pay (Kaspi QR) для OpenCart 4

Расширение оплаты для OpenCart 4.0.2+: покупатель выбирает «Оплата через Kaspi (QR)», после подтверждения заказа попадает на страницу оплаты Qut Pay (QR + ссылка Kaspi), платит в приложении Kaspi.kz. Деньги поступают напрямую на ваш Kaspi Pay. Статус заказа обновляется автоматически через webhook.

Сервис Qut Pay — независимый сервис поверх вашего Kaspi Pay, не партнёр Kaspi Bank. Нужен бизнес-аккаунт Kaspi Pay (ИП/ТОО). Описание API: `docs/INTEGRATION.md`, https://api.qut.kz/docs.

## Требования

- OpenCart 4.0.2.0 или новее (проверено по коду 4.0.2.x; на 4.1 ожидается работа, но не проверялось).
- PHP 8.0+ с расширениями `curl` и `json`.
- Валюта заказа — **KZT** (тенге). При другой валюте способ оплаты не показывается.
- Сайт доступен из интернета по https (иначе Qut Pay не сможет доставить webhook в продакшене).

## Установка

1. Соберите архив `qutpay.ocmod.zip`, в корне которого лежат `install.json`, `admin/`, `catalog/`, `system/`:

   ```bash
   cd packages/opencart-qutpay
   zip -r qutpay.ocmod.zip install.json admin catalog system
   ```

   Имя файла важно: OpenCart берёт код расширения (`qutpay`) из имени архива и распаковывает его в `extension/qutpay/`.

2. Админка → **Extensions → Installer** → Upload → выберите `qutpay.ocmod.zip` → после загрузки нажмите зелёную кнопку **Install** напротив «Qut Pay (Kaspi QR)».

3. **Extensions → Extensions → Payments** → в списке найдите «Qut Pay (Kaspi QR)» → **Install** (зелёный плюс). На этом шаге создаётся таблица `oc_qutpay_invoice`.

4. Там же нажмите **Edit** и заполните настройки:

   | Поле | Что вставить |
   |---|---|
   | API-ключ | Кабинет Qut Pay (https://qut.kz/app) → API кілттері → «Жасау». `qp_live_…` — реальные платежи, `qp_test_…` — песочница. Кнопка «Проверить ключ» делает запрос к API. |
   | Адрес webhook | Только для чтения. Скопируйте его в кабинет Qut Pay → Webhook → «Добавить адрес». |
   | Секрет webhook | Показывается один раз при добавлении адреса в кабинете. Без него заказы **не** будут отмечаться оплаченными. |
   | Адрес API | `https://api.qut.kz` — не менять. |
   | Статусы заказа | «Ожидание оплаты» (после подтверждения), «Оплачен», «Счёт истёк», «Счёт отменён», «Возврат». Для истёк/отменён/возврат можно выбрать «не менять статус». |
   | Геозона, статус, порядок | Как у остальных способов оплаты. |
   | Лог отладки | Пишет вызовы API и события webhook в `storage/logs/qutpay.log`. |

   Включите переключатель **Статус** и сохраните.

5. Сделайте тестовый заказ. В песочнице (ключ `qp_test_…`) на странице оплаты есть кнопка «[Sandbox] төлеу», имитирующая платёж; webhook при этом приходит по-настоящему.

Адрес webhook имеет вид:

```
https://ВАШ-МАГАЗИН/index.php?route=extension/qutpay/payment/qutpay.webhook
```

## Как это работает

1. **Подтверждение заказа.** На шаге «Подтверждение» покупатель нажимает кнопку. Расширение вызывает `POST /api/v1/invoices` (через `system/library/qutpay/QutPay.php` — копия `packages/sdk-php`) с полями:
   - `amount` — сумма заказа в KZT;
   - `description` — «Название магазина — заказ №N» (до 100 символов);
   - `externalOrderId` — номер заказа OpenCart;
   - `customer` — имя, email, телефон (телефон нормализуется до `7XXXXXXXXXX`, иначе не передаётся);
   - `successUrl`/`failUrl` — адрес возврата `…qutpay.callback&order_id=N&token=…`;
   - `metadata` — `{oc_order_id, oc_store_id, oc_token, oc_attempt}`;
   - заголовок `Idempotency-Key` — `oc-{order_id}-{md5(amount)}`, для повторной попытки (после истечения/отмены счёта) добавляется суффикс `-{attempt}`.

   Счёт сохраняется в `oc_qutpay_invoice`, заказу ставится статус «Ожидание оплаты» (если не выбран — статус по умолчанию магазина), покупатель перенаправляется на `payUrl`. Если покупатель вернулся и нажал кнопку снова, а прежний счёт ещё открыт и сумма не менялась, повторно используется тот же `payUrl`.

2. **Webhook.** Qut Pay присылает `POST` с заголовками `X-Webhook-Signature: sha256=HMAC-SHA256(secret, timestamp + "." + rawBody)` и `X-Webhook-Timestamp`. Подпись проверяется по сырому телу (`php://input`), отметки времени старше 5 минут отклоняются (`\QutPay\Webhook::verify`). Обработка идемпотентна по паре (счёт, статус):
   - `paid` → статус «Оплачен» (покупатель получает уведомление), в историю пишется номер и ссылка чека. Если сумма счёта не совпадает с суммой заказа — статус не меняется, в историю добавляется предупреждение;
   - `expired` / `cancelled` → соответствующий статус, но только если заказ ещё не переведён менеджером в processing/complete;
   - `refunded` → статус «Возврат»; `partially_refunded` → только запись в историю;
   - поздняя оплата (`late: true`) по истёкшему счёту всё равно отмечает заказ оплаченным;
   - события по чужим заказам (другой магазин на том же ключе) подтверждаются `200 {ignored}` и не повторяются.

   Ответы: `200` — принято, `401` — плохая подпись, `400` — битое тело, `503` — расширение выключено или секрет не задан (Qut Pay повторит доставку).

3. **Возврат покупателя.** Обработчик `qutpay.callback` запрашивает `GET /api/v1/invoices/{id}?live=1`, применяет статус (та же логика, что у webhook) и, если счёт оплачен, отправляет покупателя на стандартную страницу `checkout/success` (корзина очищается). Иначе показывается страница ожидания с кнопками «Проверить ещё раз» (автообновление через 8 с), «Открыть страницу оплаты Kaspi» и «Вернуться к оформлению».

## Файлы

```
install.json
admin/controller/payment/qutpay.php        настройки, save/check, install/uninstall
admin/model/payment/qutpay.php             создание/удаление таблицы oc_qutpay_invoice
admin/view/template/payment/qutpay.twig
admin/language/{en-gb,ru-ru}/payment/qutpay.php
catalog/controller/payment/qutpay.php      index / confirm / callback / webhook
catalog/model/payment/qutpay.php           getMethods + работа с oc_qutpay_invoice
catalog/view/template/payment/qutpay.twig          кнопка на шаге подтверждения
catalog/view/template/payment/qutpay_return.twig   страница ожидания оплаты
catalog/language/{en-gb,ru-ru}/payment/qutpay.php
system/library/qutpay/QutPay.php           PHP SDK (копия packages/sdk-php/src/QutPay.php)
```

Таблица `oc_qutpay_invoice`: `order_id` (PK), `invoice_id`, `pay_url`, `amount`, `status`, `token`, `attempt`, `expires`, `receipt_url`, `date_added`, `date_modified`. Хранится только последняя попытка оплаты по заказу.

## Допущения и ограничения

- Код написан по образцу встроенных расширений OpenCart 4.0.2.x (`bank_transfer`, `cod`): AJAX-форма настроек с `data-oc-toggle="ajax"`, код способа `qutpay.qutpay`, `confirm()` возвращает `{"redirect": …}`, история заказа через `model_checkout_order->addHistory()`. Локально PHP и OpenCart не запускались — перед продакшеном обязательно прогоните тестовый заказ в песочнице.
- Статусы по умолчанию при первом открытии формы — id стандартной установки OpenCart (1 Pending, 2 Processing, 14 Expired, 7 Canceled, 11 Refunded). Если статусы в магазине переопределены, выберите нужные вручную.
- Адрес webhook строится из `HTTP_CATALOG` админки. Если магазин работает за прокси или на нестандартном домене, проверьте адрес и при необходимости зарегистрируйте в кабинете правильный.
- Режим обслуживания (Maintenance) OpenCart блокирует входящие webhook — Qut Pay будет повторять доставку до 11 раз (10 с → 1 ч); после выхода из режима статус также подтянется при возврате покупателя.
- Ответ `GET /api/v1/account` документацией не зафиксирован: кнопка «Проверить ключ» показывает поле `name`/`mode`, если они есть, иначе просто «ключ действителен».
- Возвраты из админки OpenCart не реализованы (делайте возврат в кабинете Qut Pay — заказ обновится по webhook `invoice.refunded`).
- Расширение хранит `token` заказа в `metadata.oc_token` счёта и сверяет его в webhook: события по счетам, созданным другим магазином на том же API-ключе, игнорируются.
- Webhook-адрес не содержит `language=`; OpenCart подставляет язык по умолчанию — для обработчика это не важно.
