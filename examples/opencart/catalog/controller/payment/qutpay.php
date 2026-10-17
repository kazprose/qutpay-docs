<?php
/**
 * Qut Pay (Kaspi QR) — OpenCart 4.0.2+ payment extension, catalog controller.
 *
 * Routes (all under extension/qutpay/payment/qutpay):
 *   index    — payment block on the checkout confirm step (button "Pay with Kaspi")
 *   confirm  — AJAX: creates a Qut Pay invoice and returns {redirect: payUrl}
 *   callback — customer comes back from the Kaspi payment page (successUrl / failUrl)
 *   webhook  — POST from Qut Pay: invoice.paid / invoice.expired / invoice.cancelled / invoice.refunded
 */

namespace Opencart\Catalog\Controller\Extension\Qutpay\Payment;

class Qutpay extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/qutpay/payment/qutpay';
	private const CODE = 'qutpay.qutpay';
	private const DEFAULT_API_BASE = 'https://api.qut.kz';

	/** Seconds an open invoice must still have left before we reuse it instead of creating a new one. */
	private const REUSE_MARGIN = 20;

	/** Fallback lifetime of a QR invoice when the API does not return expiresAt. */
	private const DEFAULT_LIFETIME = 170;

	/**
	 * Payment block rendered inside the checkout confirm step.
	 */
	public function index(): string {
		$this->load->language(self::ROUTE);

		$data['language'] = $this->config->get('config_language');
		$data['confirm'] = $this->url->link(self::ROUTE . '.confirm', 'language=' . $this->config->get('config_language'), true);

		return $this->load->view(self::ROUTE, $data);
	}

	/**
	 * Creates the invoice and hands the customer over to the hosted Kaspi payment page.
	 * Response: {"redirect": payUrl} or {"error": message}.
	 */
	public function confirm(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		$order_id = 0;
		$order_info = [];
		$row = [];
		$invoice = [];
		$amount = 0.0;

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		}

		if (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != self::CODE) {
			$json['error'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			$this->load->model('checkout/order');

			$order_id = (int)$this->session->data['order_id'];

			$order_info = $this->model_checkout_order->getOrder($order_id);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

		if (!$json && strtoupper((string)$order_info['currency_code']) !== 'KZT') {
			$json['error'] = $this->language->get('error_currency');
		}

		if (!$json && trim((string)$this->config->get('payment_qutpay_api_key')) === '') {
			$json['error'] = $this->language->get('error_config');
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			$amount = $this->getOrderAmount($order_info);

			$row = $this->model_extension_qutpay_payment_qutpay->getInvoice($order_id);

			// The customer pressed "Pay" again while the previous invoice is still open: reuse it.
			if ($row && $row['status'] === 'pending' && $row['pay_url'] !== '' && (int)$row['expires'] > time() + self::REUSE_MARGIN && abs((float)$row['amount'] - $amount) < 0.005) {
				$json['redirect'] = $row['pay_url'];
			}
		}

		if (!$json) {
			$attempt = $row ? (int)$row['attempt'] + 1 : 1;
			$token = ($row && $row['token'] !== '') ? $row['token'] : bin2hex(random_bytes(16));

			$return_url = $this->url->link(self::ROUTE . '.callback', 'language=' . $this->config->get('config_language') . '&order_id=' . $order_id . '&token=' . $token, true);

			$customer = [
				'name'  => trim((string)$order_info['firstname'] . ' ' . (string)$order_info['lastname']),
				'email' => (string)$order_info['email'],
				'phone' => $this->normalisePhone((string)$order_info['telephone']),
			];

			$params = [
				'amount'          => $amount,
				'kind'            => 'qr',
				'description'     => $this->truncate(sprintf($this->language->get('text_invoice_description'), (string)$this->config->get('config_name'), $order_id), 100),
				'externalOrderId' => (string)$order_id,
				'customer'        => array_filter($customer, static function ($v) {
					return $v !== '' && $v !== null;
				}),
				'successUrl'      => $return_url,
				'failUrl'         => $return_url,
				'metadata'        => [
					'oc_order_id' => $order_id,
					'oc_store_id' => (int)$this->config->get('config_store_id'),
					'oc_token'    => $token,
					'oc_attempt'  => $attempt,
				],
				'idempotencyKey'  => $this->getIdempotencyKey($order_id, $amount, $attempt),
			];

			if (!$params['customer']) {
				unset($params['customer']);
			}

			try {
				$invoice = $this->getClient()->createInvoice($params);
			} catch (\Throwable $e) {
				$this->log('createInvoice failed for order ' . $order_id . ': ' . $e->getMessage());

				$json['error'] = sprintf($this->language->get('error_create'), htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
			}
		}

		if (!$json && (empty($invoice['id']) || empty($invoice['payUrl']) || !is_string($invoice['payUrl']))) {
			$this->log('createInvoice returned an unexpected payload for order ' . $order_id . ': ' . json_encode($invoice));

			$json['error'] = sprintf($this->language->get('error_create'), 'invalid response');
		}

		if (!$json) {
			$expires = !empty($invoice['expiresAt']) ? (int)strtotime((string)$invoice['expiresAt']) : 0;

			if ($expires <= 0) {
				$expires = time() + self::DEFAULT_LIFETIME;
			}

			$this->model_extension_qutpay_payment_qutpay->setInvoice([
				'order_id'   => $order_id,
				'invoice_id' => (string)$invoice['id'],
				'pay_url'    => (string)$invoice['payUrl'],
				'amount'     => $amount,
				'status'     => 'pending',
				'token'      => $token,
				'attempt'    => $attempt,
				'expires'    => $expires,
			]);

			$status_id = (int)$this->config->get('payment_qutpay_pending_status_id');

			if (!$status_id) {
				$status_id = (int)$this->config->get('config_order_status_id');
			}

			$this->model_checkout_order->addHistory($order_id, $status_id, sprintf($this->language->get('text_history_created'), (string)$invoice['id']), false);

			$this->log('invoice ' . $invoice['id'] . ' created for order ' . $order_id . ' (attempt ' . $attempt . ', amount ' . $amount . ')');

			$json['redirect'] = (string)$invoice['payUrl'];
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Customer returns from the Kaspi payment page. The webhook may not have arrived yet,
	 * so the invoice status is fetched live from the API before deciding where to send the customer.
	 */
	public function callback(): void {
		$this->load->language(self::ROUTE);

		$this->load->model('checkout/order');
		$this->load->model(self::ROUTE);

		$language = (string)$this->config->get('config_language');

		$order_id = 0;

		// Prefer the signed link (works even when the session was lost), fall back to the checkout session.
		$get_order_id = (int)($this->request->get['order_id'] ?? 0);
		$get_token = (string)($this->request->get['token'] ?? '');

		if ($get_order_id && $get_token !== '') {
			$row = $this->model_extension_qutpay_payment_qutpay->getInvoice($get_order_id);

			if ($row && $row['token'] !== '' && hash_equals($row['token'], $get_token)) {
				$order_id = $get_order_id;
			}
		}

		if (!$order_id && isset($this->session->data['order_id'])) {
			$order_id = (int)$this->session->data['order_id'];
		}

		$row = $order_id ? $this->model_extension_qutpay_payment_qutpay->getInvoice($order_id) : [];
		$order_info = $row ? $this->model_checkout_order->getOrder($order_id) : [];

		if (!$row || !$order_info) {
			$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $language, true));

			return;
		}

		if ($row['status'] !== 'paid' && $row['invoice_id'] !== '') {
			try {
				$invoice = $this->getClient()->getInvoice($row['invoice_id'], true);

				$result = $this->applyInvoice($order_info, $invoice, 'return');

				$this->log('return sync for order ' . $order_id . ': ' . $result);

				$row = $this->model_extension_qutpay_payment_qutpay->getInvoice($order_id);
			} catch (\Throwable $e) {
				$this->log('getInvoice failed for order ' . $order_id . ': ' . $e->getMessage());
			}
		}

		if ($row && $row['status'] === 'paid') {
			// checkout/success clears the cart and the checkout session.
			$this->response->redirect($this->url->link('checkout/success', 'language=' . $language, true));

			return;
		}

		// Not paid (yet): show a waiting page with "refresh" / "pay again" / "back to checkout".
		$this->document->setTitle($this->language->get('text_return_heading'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', 'language=' . $language)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_return_heading'),
			'href' => $this->url->link(self::ROUTE . '.callback', 'language=' . $language . '&order_id=' . $order_id . '&token=' . $row['token'])
		];

		$data['heading_title'] = $this->language->get('text_return_heading');
		$data['order_id'] = $order_id;
		$data['status'] = $row['status'];

		switch ($row['status']) {
			case 'expired':
				$data['text_message'] = $this->language->get('text_return_expired');
				break;
			case 'cancelled':
			case 'failed':
				$data['text_message'] = $this->language->get('text_return_cancelled');
				break;
			case 'refunded':
			case 'partially_refunded':
				$data['text_message'] = $this->language->get('text_return_refunded');
				break;
			default:
				$data['text_message'] = $this->language->get('text_return_pending');
				break;
		}

		$data['refresh'] = $this->url->link(self::ROUTE . '.callback', 'language=' . $language . '&order_id=' . $order_id . '&token=' . $row['token'], true);
		$data['checkout'] = $this->url->link('checkout/checkout', 'language=' . $language, true);
		$data['home'] = $this->url->link('common/home', 'language=' . $language, true);

		if ($row['status'] === 'pending' && $row['pay_url'] !== '' && (int)$row['expires'] > time() + self::REUSE_MARGIN) {
			$data['pay_url'] = $row['pay_url'];
		} else {
			$data['pay_url'] = '';
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view(self::ROUTE . '_return', $data));
	}

	/**
	 * Signed notifications from Qut Pay. Always answers JSON; a 2xx tells Qut Pay to stop retrying.
	 */
	public function webhook(): void {
		$json = [];
		$code = 200;

		$secret = trim((string)$this->config->get('payment_qutpay_webhook_secret'));

		// The body must be verified byte-for-byte, and OpenCart's Request::clean() would
		// htmlspecialchars() header values, so read both straight from PHP.
		$raw = (string)file_get_contents('php://input');

		$headers = [
			'X-Webhook-Signature' => (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''),
			'X-Webhook-Timestamp' => (string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''),
		];

		if (!$this->config->get('payment_qutpay_status')) {
			$code = 503;
			$json['error'] = 'payment_disabled';
		} elseif ($secret === '') {
			$code = 503;
			$json['error'] = 'no_secret';
		} else {
			$this->loadSdk();

			if (!\QutPay\Webhook::verify($secret, $raw, $headers)) {
				$this->log('webhook rejected: bad signature or stale timestamp');

				$code = 401;
				$json['error'] = 'bad_signature';
			}
		}

		if (!$json) {
			$data = json_decode($raw, true);

			if (!is_array($data) || empty($data['event']) || !is_string($data['event'])) {
				$code = 400;
				$json['error'] = 'bad_payload';
			} elseif ($data['event'] === 'webhook.test') {
				$this->log('webhook.test received OK');

				$json['ok'] = true;
				$json['test'] = true;
			} else {
				$invoice = (isset($data['invoice']) && is_array($data['invoice'])) ? $data['invoice'] : [];

				$order_info = $this->findOrder($invoice);

				if (!$order_info) {
					// Not one of our orders (another site on the same key?) — acknowledge so it is not retried.
					$this->log('webhook ' . $data['event'] . ': order not found for invoice ' . (string)($invoice['id'] ?? '?'));

					$json['ok'] = true;
					$json['ignored'] = 'order_not_found';
				} else {
					$result = $this->applyInvoice($order_info, $invoice, 'webhook ' . $data['event']);

					$this->log('webhook ' . $data['event'] . ' for order ' . $order_info['order_id'] . ': ' . $result);

					$json['ok'] = true;
					$json['result'] = $result;
				}
			}
		}

		if ($code !== 200) {
			http_response_code($code);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Resolves the OpenCart order an invoice belongs to. Returns [] when it is not ours.
	 */
	private function findOrder(array $invoice): array {
		$this->load->model('checkout/order');
		$this->load->model(self::ROUTE);

		$order_id = 0;

		if (isset($invoice['metadata']['oc_order_id'])) {
			$order_id = (int)$invoice['metadata']['oc_order_id'];
		}

		if (!$order_id && !empty($invoice['externalOrderId']) && ctype_digit((string)$invoice['externalOrderId'])) {
			$order_id = (int)$invoice['externalOrderId'];
		}

		if (!$order_id && !empty($invoice['id']) && is_string($invoice['id'])) {
			$row = $this->model_extension_qutpay_payment_qutpay->getInvoiceByInvoiceId($invoice['id']);

			if ($row) {
				$order_id = (int)$row['order_id'];
			}
		}

		if (!$order_id) {
			return [];
		}

		// Only orders that went through this extension have a row here.
		if (!$this->model_extension_qutpay_payment_qutpay->getInvoice($order_id)) {
			return [];
		}

		$order_info = $this->model_checkout_order->getOrder($order_id);

		return $order_info ? $order_info : [];
	}

	/**
	 * Applies an invoice status to the order. Idempotent on (invoice id, status).
	 * Used by both the webhook and the return handler.
	 *
	 * @return string applied | unchanged | ignored | mismatch | noted
	 */
	private function applyInvoice(array $order_info, array $invoice, string $source): string {
		$this->load->language(self::ROUTE);
		$this->load->model('checkout/order');
		$this->load->model(self::ROUTE);

		$model = $this->model_extension_qutpay_payment_qutpay;

		$order_id = (int)$order_info['order_id'];

		$row = $model->getInvoice($order_id);

		if (!$row) {
			return 'ignored';
		}

		$invoice_id = isset($invoice['id']) && is_string($invoice['id']) ? $invoice['id'] : '';
		$status = isset($invoice['status']) && is_string($invoice['status']) ? $invoice['status'] : '';

		if ($invoice_id === '' || $status === '') {
			return 'ignored';
		}

		$is_current = ($invoice_id === $row['invoice_id']);

		// Ownership: either the invoice we have on file, or an earlier attempt for the same order
		// (every invoice we create carries the order's secret token in its metadata).
		$meta_token = isset($invoice['metadata']['oc_token']) && is_string($invoice['metadata']['oc_token']) ? $invoice['metadata']['oc_token'] : '';

		if (!$is_current && ($row['token'] === '' || $meta_token === '' || !hash_equals($row['token'], $meta_token))) {
			return 'ignored';
		}

		// A superseded invoice may only ever report a (late) payment — its expiry must not touch the order.
		if (!$is_current && $status !== 'paid') {
			return 'ignored';
		}

		if ($status === $row['status']) {
			return 'unchanged';
		}

		if ($row['status'] === 'paid' && $status !== 'refunded' && $status !== 'partially_refunded') {
			// Out-of-order delivery (e.g. "expired" arriving after "paid") — money is in, keep the order paid.
			return 'ignored';
		}

		if (($row['status'] === 'refunded' || $row['status'] === 'partially_refunded') && $status === 'paid') {
			// A late/retried "paid" snapshot must never re-mark a refunded order as paid.
			return 'ignored';
		}

		$result = 'applied';

		switch ($status) {
			case 'paid':
				$amount = $this->getOrderAmount($order_info);
				$paid = isset($invoice['amount']) ? (float)$invoice['amount'] : $amount;

				if (abs($paid - $amount) > 0.01) {
					// Leave the status alone and flag it for the manager.
					$comment = sprintf($this->language->get('text_history_mismatch'), $invoice_id, $this->formatAmount($paid), $this->formatAmount($amount), $source);

					$this->model_checkout_order->addHistory($order_id, (int)$order_info['order_status_id'], $comment, false);

					$result = 'mismatch';
				} else {
					$comment = sprintf($this->language->get('text_history_paid'), $invoice_id, $source);

					if (!empty($invoice['late'])) {
						$comment .= ' ' . $this->language->get('text_history_late');
					}

					if (!empty($invoice['receiptNumber']) && is_scalar($invoice['receiptNumber'])) {
						$comment .= ' ' . sprintf($this->language->get('text_history_receipt'), (string)$invoice['receiptNumber']);
					}

					if (!empty($invoice['receiptUrl']) && is_string($invoice['receiptUrl'])) {
						$comment .= ' ' . $invoice['receiptUrl'];
					}

					$status_id = (int)$this->config->get('payment_qutpay_paid_status_id');

					if ($status_id) {
						$this->model_checkout_order->addHistory($order_id, $status_id, $comment, true);
					}
				}
				break;

			case 'expired':
			case 'cancelled':
			case 'failed':
				$status_id = (int)$this->config->get('payment_qutpay_' . ($status === 'expired' ? 'expired' : 'cancelled') . '_status_id');

				// Never downgrade an order a manager has already moved into processing/complete.
				if ($status_id && !$this->isOrderProcessed($order_info)) {
					$this->model_checkout_order->addHistory($order_id, $status_id, sprintf($this->language->get('text_history_' . ($status === 'expired' ? 'expired' : 'cancelled')), $invoice_id, $source), false);
				} else {
					$result = 'noted';
				}
				break;

			case 'refunded':
				$status_id = (int)$this->config->get('payment_qutpay_refunded_status_id');

				$comment = sprintf($this->language->get('text_history_refunded'), $invoice_id, $this->formatAmount(isset($invoice['refundedAmount']) ? (float)$invoice['refundedAmount'] : (float)($invoice['amount'] ?? 0)), $source);

				if ($status_id) {
					$this->model_checkout_order->addHistory($order_id, $status_id, $comment, false);
				} else {
					$this->model_checkout_order->addHistory($order_id, (int)$order_info['order_status_id'], $comment, false);

					$result = 'noted';
				}
				break;

			case 'partially_refunded':
				$comment = sprintf($this->language->get('text_history_partial_refund'), $invoice_id, $this->formatAmount((float)($invoice['refundedAmount'] ?? 0)), $source);

				$this->model_checkout_order->addHistory($order_id, (int)$order_info['order_status_id'], $comment, false);

				$result = 'noted';
				break;

			default:
				return 'ignored';
		}

		$update = [
			'invoice_id' => $invoice_id,
			'status'     => $status,
		];

		if (!empty($invoice['receiptUrl']) && is_string($invoice['receiptUrl'])) {
			$update['receipt_url'] = $invoice['receiptUrl'];
		}

		if ($result === 'mismatch') {
			// Keep the row on "pending" so a corrected payment can still be applied; the note is in the history.
			$update['status'] = $row['status'];
		}

		$model->editInvoice($order_id, $update);

		return $result;
	}

	/** True when the order sits in one of the store's "processing" or "complete" statuses. */
	private function isOrderProcessed(array $order_info): bool {
		$processed = array_merge(
			(array)$this->config->get('config_processing_status'),
			(array)$this->config->get('config_complete_status')
		);

		return in_array((int)$order_info['order_status_id'], array_map('intval', $processed), true);
	}

	/** Order total in the order's own currency (KZT), rounded to tiyn. */
	private function getOrderAmount(array $order_info): float {
		$value = (float)($order_info['currency_value'] ?? 1);

		if ($value <= 0) {
			$value = 1.0;
		}

		return round((float)$order_info['total'] * $value, 2);
	}

	private function getIdempotencyKey(int $order_id, float $amount, int $attempt): string {
		$key = 'oc-' . $order_id . '-' . md5(number_format($amount, 2, '.', ''));

		// A new attempt (previous invoice expired/cancelled) must produce a new invoice.
		if ($attempt > 1) {
			$key .= '-' . $attempt;
		}

		return $key;
	}

	private function formatAmount(float $amount): string {
		return number_format($amount, 2, '.', ' ') . ' KZT';
	}

	/** Kaspi wants 7XXXXXXXXXX; anything that does not normalise cleanly is dropped. */
	private function normalisePhone(string $phone): string {
		$digits = preg_replace('/\D+/', '', $phone);

		if ($digits === null || $digits === '') {
			return '';
		}

		if (strlen($digits) === 10 && $digits[0] === '7') {
			$digits = '7' . $digits;
		}

		if (strlen($digits) === 11 && $digits[0] === '8') {
			$digits = '7' . substr($digits, 1);
		}

		if (strlen($digits) !== 11 || $digits[0] !== '7') {
			return '';
		}

		return $digits;
	}

	private function truncate(string $text, int $limit): string {
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $limit, 'UTF-8');
		}

		return substr($text, 0, $limit);
	}

	private function getClient(): \QutPay\Client {
		$this->loadSdk();

		$api_base = trim((string)$this->config->get('payment_qutpay_api_base'));

		if ($api_base === '') {
			$api_base = self::DEFAULT_API_BASE;
		}

		return new \QutPay\Client(trim((string)$this->config->get('payment_qutpay_api_key')), $api_base, 25);
	}

	private function loadSdk(): void {
		if (!class_exists('QutPay\\Client', false)) {
			// extension/qutpay/catalog/controller/payment → extension/qutpay
			require_once dirname(__DIR__, 3) . '/system/library/qutpay/QutPay.php';
		}
	}

	private function log(string $message): void {
		if (!$this->config->get('payment_qutpay_debug')) {
			return;
		}

		try {
			$log = new \Opencart\System\Library\Log('qutpay.log');

			$log->write($message);
		} catch (\Throwable $e) {
			// Logging must never break a payment.
		}
	}
}
