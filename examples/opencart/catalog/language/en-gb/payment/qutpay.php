<?php
// Heading
$_['heading_title']               = 'Pay with Kaspi (QR)';

// Text
$_['text_home']                   = 'Home';
$_['text_title']                  = 'Pay with Kaspi (QR)';
$_['text_description']            = 'Scan the QR code or open the link in the Kaspi.kz app and confirm the payment. The money goes straight to the seller\'s Kaspi Pay.';
$_['text_redirect']               = 'After you confirm the order you will be taken to the Kaspi payment page.';
$_['text_invoice_description']    = '%s — order #%s';
$_['text_order_number']           = 'Order number:';

// Order history comments
$_['text_history_created']        = 'Qut Pay: invoice %s created, awaiting payment via Kaspi.';
$_['text_history_paid']           = 'Qut Pay: paid via Kaspi, invoice %s (%s).';
$_['text_history_late']           = 'Late payment: the invoice had already expired or been cancelled when the money arrived.';
$_['text_history_receipt']        = 'Receipt %s.';
$_['text_history_mismatch']       = 'Qut Pay: invoice %s was paid for %s but the order total is %s (%s). Please check manually.';
$_['text_history_expired']        = 'Qut Pay: invoice %s expired without payment (%s). The customer may pay again.';
$_['text_history_cancelled']      = 'Qut Pay: invoice %s was cancelled (%s). The customer may pay again.';
$_['text_history_refunded']       = 'Qut Pay: invoice %s refunded, %s (%s).';
$_['text_history_partial_refund'] = 'Qut Pay: invoice %s partially refunded, %s in total (%s).';

// Return page
$_['text_return_heading']         = 'Kaspi payment';
$_['text_return_pending']         = 'The payment has not been confirmed yet. If you have already paid in Kaspi, wait a few seconds and refresh this page.';
$_['text_return_expired']         = 'The payment window has expired and no payment was received. You can go back to checkout and pay again.';
$_['text_return_cancelled']       = 'The payment was cancelled. You can go back to checkout and pay again.';
$_['text_return_refunded']        = 'The payment for this order has been refunded.';

// Button
$_['button_confirm']              = 'Confirm Order';
$_['button_pay']                  = 'Open the Kaspi payment page';
$_['button_refresh']              = 'Check again';
$_['button_checkout']             = 'Back to checkout';
$_['button_home']                 = 'Continue shopping';

// Error
$_['error_order']                 = 'Order not found! Please start the checkout again.';
$_['error_payment_method']        = 'Payment method is invalid!';
$_['error_currency']              = 'Payment via Kaspi is available in tenge (KZT) only.';
$_['error_config']                = 'Payment via Kaspi is not configured yet. Please choose another payment method.';
$_['error_create']                = 'Could not start the Kaspi payment: %s';
