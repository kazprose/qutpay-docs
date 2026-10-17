=== Qut Pay for WooCommerce ===
Contributors: qutpay
Tags: kaspi, kaspi pay, kazakhstan, payment gateway, qr
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Приём оплаты через Kaspi (QR / ссылка Kaspi) в WooCommerce с помощью Qut Pay. Деньги поступают напрямую на ваш Kaspi Pay.

== Description ==

* Покупатель выбирает «Оплата через Kaspi», попадает на страницу оплаты с QR и ссылкой Kaspi, платит в приложении Kaspi.kz.
* Заказ автоматически становится оплаченным (webhook), покупатель получает чек.
* Возвраты — прямо из карточки заказа WooCommerce.
* Поддержка классической кассы и Checkout Blocks, HPOS.
* Валюта магазина должна быть KZT.

Сервис Qut Pay — независимый сервис поверх вашего Kaspi Pay, не партнёр Kaspi Bank. Нужен бизнес-аккаунт Kaspi Pay (ИП/ТОО).

== Installation ==

1. Зарегистрируйтесь на https://qut.kz/app, подключите кассира Kaspi (SMS).
2. Кабинет → API кілттері → создайте ключ → скопируйте.
3. Кабинет → Webhook → добавьте адрес `https://ВАШ-САЙТ/wp-json/qutpay/v1/webhook` → скопируйте секрет.
4. WordPress → Плагины → загрузите zip → активируйте.
5. WooCommerce → Настройки → Платежи → Qut Pay (Kaspi QR): вставьте ключ и секрет, включите.
6. Сделайте тестовый заказ (в песочнице Qut Pay платёж можно имитировать кнопкой на странице оплаты).

== Changelog ==

= 0.1.0 =
* Первый выпуск: создание счёта, редирект на страницу оплаты, webhook с проверкой подписи, синхронизация на странице «спасибо», возвраты, Checkout Blocks.
