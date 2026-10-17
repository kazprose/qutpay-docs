# Qut Pay PHP SDK

Бір файл — `src/QutPay.php` (PHP 7.4+, curl + json). Composer арқылы да, тікелей `require` арқылы да қосуға болады.

```php
require 'QutPay.php';
$qp = new \QutPay\Client(getenv('QUTPAY_API_KEY')); // sandbox кілті qp_test_…, live — qp_live_…

$inv = $qp->createInvoice([
    'amount' => 5000, 'description' => 'Премиум жазылым', 'externalOrderId' => 'inv-1001',
    'customer' => ['email' => 'a@b.kz'], 'successUrl' => 'https://site.kz/ok',
    'idempotencyKey' => 'inv-1001',
]);
header('Location: ' . $inv['payUrl']);
```

Webhook (`https://site.kz/qutpay-webhook.php`):
```php
$raw = file_get_contents('php://input');
if (!\QutPay\Webhook::verify($secret, $raw, getallheaders())) { http_response_code(401); exit; }
$e = json_decode($raw, true);
if ($e['event'] === 'invoice.paid') activate($e['invoice']['externalOrderId']);
http_response_code(200);
```

Әдістер: `createInvoice`, `getInvoice($id, $live)`, `listInvoices($filters)`, `cancelInvoice`, `refundInvoice($id, $amount = null, $reason = null)`, `simulateInvoice` (sandbox), `account`. Қателер — `\QutPay\ApiException` (`->status`, `->errorCode`).
Толық API: https://api.qut.kz/docs
