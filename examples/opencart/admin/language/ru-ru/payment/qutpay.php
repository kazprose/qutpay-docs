<?php
// Heading
$_['heading_title']          = 'Qut Pay (Kaspi QR)';

// Text
$_['text_extension']         = 'Расширения';
$_['text_home']              = 'Главная';
$_['text_success']           = 'Настройки Qut Pay сохранены!';
$_['text_no_secret']         = 'Внимание: без секрета webhook заказы не будут отмечаться оплаченными автоматически.';
$_['text_edit']              = 'Настройки Qut Pay (Kaspi QR)';
$_['text_intro']             = 'Покупатель оплачивает через Kaspi: сканирует QR или открывает ссылку в приложении Kaspi.kz. Деньги поступают напрямую на ваш Kaspi Pay. Ключ API и секрет webhook — в кабинете Qut Pay: <a href="https://qut.kz/app" target="_blank" rel="noopener">qut.kz/app</a>. Валюта магазина должна быть KZT (тенге).';
$_['text_connection']        = 'Подключение';
$_['text_order_statuses']    = 'Статусы заказа';
$_['text_display']           = 'Отображение';
$_['text_all_zones']         = 'Все зоны';
$_['text_leave_unchanged']   = '— не менять статус заказа —';
$_['text_check_ok']          = 'Подключение успешно: ключ API действителен (%s).';
$_['text_check_fail']        = 'Не удалось подключиться: %s';

// Entry
$_['entry_api_key']          = 'API-ключ';
$_['entry_webhook_url']      = 'Адрес webhook';
$_['entry_webhook_secret']   = 'Секрет webhook';
$_['entry_api_base']         = 'Адрес API';
$_['entry_pending_status']   = 'Ожидание оплаты';
$_['entry_paid_status']      = 'Оплачен';
$_['entry_expired_status']   = 'Счёт истёк';
$_['entry_cancelled_status'] = 'Счёт отменён';
$_['entry_refunded_status']  = 'Возврат';
$_['entry_geo_zone']         = 'Географическая зона';
$_['entry_status']           = 'Статус';
$_['entry_sort_order']       = 'Порядок сортировки';
$_['entry_debug']            = 'Лог отладки';

// Help
$_['help_api_key']           = 'Кабинет Qut Pay → API кілттері → «Жасау». Ключ qp_live_… — реальные платежи, qp_test_… — песочница.';
$_['help_webhook_url']       = 'Скопируйте этот адрес в кабинет Qut Pay → Webhook → «Добавить адрес». Сюда Qut Pay сообщит, что счёт оплачен, истёк, отменён или возвращён.';
$_['help_webhook_secret']    = 'Показывается один раз при добавлении адреса в кабинете. Нужен для проверки подписи входящих уведомлений.';
$_['help_api_base']          = 'Обычно https://api.qut.kz — менять только по указанию Qut Pay.';
$_['help_pending_status']    = 'Ставится сразу после подтверждения заказа, когда покупатель отправлен на страницу оплаты Kaspi.';
$_['help_paid_status']       = 'Ставится, когда Qut Pay сообщает об оплате счёта. Для цифровых товаров обычно «Выполнен».';
$_['help_debug']             = 'Записывать вызовы API и события webhook в storage/logs/qutpay.log.';

// Button
$_['button_check']           = 'Проверить ключ';

// Error
$_['error_permission']       = 'У вас нет прав для изменения настроек Qut Pay!';
$_['error_api_key']          = 'Для включения способа оплаты нужен API-ключ!';
$_['error_api_base']         = 'Адрес API должен быть http(s)-ссылкой, например https://api.qut.kz';
