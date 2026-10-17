<?php
/**
 * Qut Pay — PHP клиенті (PHP 7.4+, тек curl/json кеңейтулері).
 *
 *   require 'QutPay.php';
 *   $qp = new \QutPay\Client(getenv('QUTPAY_API_KEY')); // baseUrl әдепкі https://api.qut.kz
 *   $inv = $qp->createInvoice(['amount' => 5000, 'description' => 'Премиум', 'externalOrderId' => 'inv-1001',
 *                              'customer' => ['email' => 'a@b.kz'], 'successUrl' => 'https://site.kz/ok']);
 *   header('Location: ' . $inv['payUrl']);
 *
 * Webhook:
 *   $raw = file_get_contents('php://input');
 *   if (!\QutPay\Webhook::verify($secret, $raw, getallheaders())) { http_response_code(401); exit; }
 *   $e = json_decode($raw, true);
 *   if ($e['event'] === 'invoice.paid') activate($e['invoice']['externalOrderId']);
 */

namespace QutPay;

class ApiException extends \RuntimeException
{
    /** @var string|null машиналық код: invalid_amount, invoice_not_found, rate_limited … */
    public $errorCode;
    /** @var int HTTP статус (0 — желі қатесі) */
    public $status;

    public function __construct(string $message, int $status = 0, ?string $errorCode = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

class Client
{
    private $apiKey;
    private $baseUrl;
    private $timeout;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.qut.kz', int $timeout = 20)
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * @param array $params amount (міндетті), kind (qr|phone), description, externalOrderId,
     *                      customer{name,phone,email}, successUrl, failUrl, metadata, idempotencyKey
     * @return array id, status, payUrl, qrUrl, deepLink, qrImageUrl, expiresAt …
     */
    public function createInvoice(array $params): array
    {
        $headers = [];
        if (!empty($params['idempotencyKey'])) {
            $headers['Idempotency-Key'] = (string) $params['idempotencyKey'];
            unset($params['idempotencyKey']);
        }
        if (!isset($params['kind'])) {
            $params['kind'] = 'qr';
        }
        return $this->call('POST', '/api/v1/invoices', $params, $headers);
    }

    public function getInvoice(string $id, bool $live = false): array
    {
        return $this->call('GET', '/api/v1/invoices/' . rawurlencode($id) . ($live ? '?live=1' : ''));
    }

    /** @param array $filters status, externalOrderId, search, from, to, limit, offset */
    public function listInvoices(array $filters = []): array
    {
        $qs = http_build_query(array_filter($filters, static function ($v) { return $v !== null && $v !== ''; }));
        return $this->call('GET', '/api/v1/invoices' . ($qs ? '?' . $qs : ''));
    }

    public function cancelInvoice(string $id): array
    {
        return $this->call('POST', '/api/v1/invoices/' . rawurlencode($id) . '/cancel');
    }

    /** Толық қайтару үшін $amount = null. */
    public function refundInvoice(string $id, $amount = null, ?string $reason = null): array
    {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        return $this->call('POST', '/api/v1/invoices/' . rawurlencode($id) . '/refund', $body);
    }

    /** Тек sandbox. */
    public function simulateInvoice(string $id, string $status = 'paid'): array
    {
        return $this->call('POST', '/api/v1/invoices/' . rawurlencode($id) . '/simulate', ['status' => $status]);
    }

    public function account(): array
    {
        return $this->call('GET', '/api/v1/account');
    }

    private function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json', 'User-Agent: qutpay-php/0.1'];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method !== 'GET') {
            $json = json_encode($body ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
            $h[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException('Network error: ' . $err, 0, 'network_error');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException($data['message'] ?? ($data['error'] ?? 'HTTP ' . $status), $status, $data['error'] ?? null);
        }
        return $data;
    }
}

class Webhook
{
    /**
     * X-Webhook-Signature (sha256=HMAC(secret, "timestamp.rawBody")) тексеру, 5 минут replay-қорғанысымен.
     * $headers — getallheaders() немесе ['X-Webhook-Signature' => …, 'X-Webhook-Timestamp' => …].
     */
    public static function verify(string $secret, string $rawBody, array $headers, int $toleranceSec = 300): bool
    {
        if ($secret === '') {
            return false;
        }
        $get = static function (array $headers, string $name): string {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return is_array($v) ? (string) reset($v) : (string) $v;
                }
            }
            return '';
        };
        $signature = $get($headers, 'X-Webhook-Signature');
        $timestamp = $get($headers, 'X-Webhook-Timestamp');
        if (strpos($signature, 'sha256=') !== 0 || !preg_match('/^\d{1,12}$/', $timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $toleranceSec) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, substr($signature, 7));
    }
}
