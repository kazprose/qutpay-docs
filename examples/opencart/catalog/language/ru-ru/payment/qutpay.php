<?php
// Heading
$_['heading_title']               = 'Оплата через Kaspi (QR)';

// Text
$_['text_home']                   = 'Главная';
$_['text_title']                  = 'Оплата через Kaspi (QR)';
$_['text_description']            = 'Отсканируйте QR или откройте ссылку в приложении Kaspi.kz и подтвердите платёж. Деньги поступают напрямую на Kaspi Pay продавца.';
$_['text_redirect']               = 'После подтверждения заказа вы перейдёте на страницу оплаты Kaspi.';
$_['text_invoice_description']    = '%s — заказ №%s';
$_['text_order_number']           = 'Номер заказа:';

// Order history comments
$_['text_history_created']        = 'Qut Pay: счёт %s создан, ожидание оплаты через Kaspi.';
$_['text_history_paid']           = 'Qut Pay: оплачено через Kaspi, счёт %s (%s).';
$_['text_history_late']           = 'Поздняя оплата: деньги пришли после истечения или отмены счёта.';
$_['text_history_receipt']        = 'Чек %s.';
$_['text_history_mismatch']       = 'Qut Pay: по счёту %s оплачено %s, а сумма заказа %s (%s). Проверьте вручную.';
$_['text_history_expired']        = 'Qut Pay: счёт %s истёк без оплаты (%s). Покупатель может оплатить заново.';
$_['text_history_cancelled']      = 'Qut Pay: счёт %s отменён (%s). Покупатель может оплатить заново.';
$_['text_history_refunded']       = 'Qut Pay: по счёту %s выполнен возврат, %s (%s).';
$_['text_history_partial_refund'] = 'Qut Pay: по счёту %s частичный возврат, всего %s (%s).';

// Return page
$_['text_return_heading']         = 'Оплата через Kaspi';
$_['text_return_pending']         = 'Оплата ещё не подтверждена. Если вы уже оплатили в Kaspi, подождите несколько секунд и обновите страницу.';
$_['text_return_expired']         = 'Время оплаты истекло, платёж не поступил. Вернитесь к оформлению заказа и оплатите заново.';
$_['text_return_cancelled']       = 'Платёж отменён. Вернитесь к оформлению заказа и оплатите заново.';
$_['text_return_refunded']        = 'Оплата по этому заказу возвращена.';

// Button
$_['button_confirm']              = 'Подтвердить заказ';
$_['button_pay']                  = 'Открыть страницу оплаты Kaspi';
$_['button_refresh']              = 'Проверить ещё раз';
$_['button_checkout']             = 'Вернуться к оформлению';
$_['button_home']                 = 'Продолжить покупки';

// Error
$_['error_order']                 = 'Заказ не найден! Начните оформление заново.';
$_['error_payment_method']        = 'Неверный способ оплаты!';
$_['error_currency']              = 'Оплата через Kaspi доступна только в тенге (KZT).';
$_['error_config']                = 'Оплата через Kaspi ещё не настроена. Выберите другой способ оплаты.';
$_['error_create']                = 'Не удалось начать оплату через Kaspi: %s';
