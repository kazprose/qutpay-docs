<?php
// Heading
$_['heading_title']          = 'Qut Pay (Kaspi QR)';

// Text
$_['text_extension']         = 'Extensions';
$_['text_home']              = 'Home';
$_['text_success']           = 'Success: You have modified the Qut Pay settings!';
$_['text_no_secret']         = 'Warning: without a webhook secret orders will not be marked as paid automatically.';
$_['text_edit']              = 'Edit Qut Pay (Kaspi QR)';
$_['text_intro']             = 'Customers pay through Kaspi: they scan a QR code or open the link in the Kaspi.kz app. Money goes straight to your Kaspi Pay account. Get the API key and the webhook secret in the Qut Pay cabinet: <a href="https://qut.kz/app" target="_blank" rel="noopener">qut.kz/app</a>. The store currency must be KZT.';
$_['text_connection']        = 'Connection';
$_['text_order_statuses']    = 'Order statuses';
$_['text_display']           = 'Display';
$_['text_all_zones']         = 'All Zones';
$_['text_leave_unchanged']   = '— leave the order status unchanged —';
$_['text_check_ok']          = 'Connection OK: the API key is valid (%s).';
$_['text_check_fail']        = 'Connection failed: %s';

// Entry
$_['entry_api_key']          = 'API key';
$_['entry_webhook_url']      = 'Webhook URL';
$_['entry_webhook_secret']   = 'Webhook secret';
$_['entry_api_base']         = 'API address';
$_['entry_pending_status']   = 'Awaiting payment';
$_['entry_paid_status']      = 'Paid';
$_['entry_expired_status']   = 'Invoice expired';
$_['entry_cancelled_status'] = 'Invoice cancelled';
$_['entry_refunded_status']  = 'Refunded';
$_['entry_geo_zone']         = 'Geo Zone';
$_['entry_status']           = 'Status';
$_['entry_sort_order']       = 'Sort Order';
$_['entry_debug']            = 'Debug log';

// Help
$_['help_api_key']           = 'Qut Pay cabinet → API keys → Create. A qp_live_… key charges real money, qp_test_… works in the sandbox.';
$_['help_webhook_url']       = 'Copy this address into Qut Pay cabinet → Webhook → Add address. Qut Pay will notify the store here when an invoice is paid, expired, cancelled or refunded.';
$_['help_webhook_secret']    = 'Shown once when the webhook address is added in the cabinet. Used to verify the signature of incoming notifications.';
$_['help_api_base']          = 'Normally https://api.qut.kz — change only if Qut Pay tells you to.';
$_['help_pending_status']    = 'Set right after the customer confirms the order and is redirected to the Kaspi payment page.';
$_['help_paid_status']       = 'Set when Qut Pay reports that the invoice has been paid. Use "Complete" for digital goods.';
$_['help_debug']             = 'Writes API calls and webhook events to storage/logs/qutpay.log.';

// Button
$_['button_check']           = 'Check key';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify the Qut Pay payment extension!';
$_['error_api_key']          = 'API key is required to enable the payment method!';
$_['error_api_base']         = 'API address must be an http(s) URL, e.g. https://api.qut.kz';
