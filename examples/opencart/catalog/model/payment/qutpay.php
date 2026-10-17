<?php
/**
 * Qut Pay (Kaspi QR) — OpenCart 4.0.2+ payment extension, catalog model.
 *
 * getMethods() decides whether the method is offered at checkout; the remaining methods
 * manage the `oc_qutpay_invoice` table (one row per order, the latest invoice attempt).
 */

namespace Opencart\Catalog\Model\Extension\Qutpay\Payment;

class Qutpay extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = []): array {
		$this->load->language('extension/qutpay/payment/qutpay');

		$status = true;

		if (!$this->config->get('payment_qutpay_status') || trim((string)$this->config->get('payment_qutpay_api_key')) === '') {
			$status = false;
		}

		// Kaspi invoices are in tenge only.
		$currency = isset($this->session->data['currency']) ? (string)$this->session->data['currency'] : (string)$this->config->get('config_currency');

		if ($status && strtoupper($currency) !== 'KZT') {
			$status = false;
		}

		if ($status && method_exists($this->cart, 'hasSubscription') && $this->cart->hasSubscription()) {
			$status = false;
		}

		if ($status && $this->config->get('config_checkout_payment_address') && $this->config->get('payment_qutpay_geo_zone_id')) {
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_qutpay_geo_zone_id') . "' AND `country_id` = '" . (int)($address['country_id'] ?? 0) . "' AND (`zone_id` = '" . (int)($address['zone_id'] ?? 0) . "' OR `zone_id` = '0')");

			if (!$query->num_rows) {
				$status = false;
			}
		}

		$method_data = [];

		if ($status) {
			$option_data['qutpay'] = [
				'code' => 'qutpay.qutpay',
				'name' => $this->language->get('heading_title')
			];

			$method_data = [
				'code'       => 'qutpay',
				'name'       => $this->language->get('heading_title'),
				'option'     => $option_data,
				'sort_order' => (int)$this->config->get('payment_qutpay_sort_order')
			];
		}

		return $method_data;
	}

	public function getInvoice(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "qutpay_invoice` WHERE `order_id` = '" . (int)$order_id . "'");

		return $query->row;
	}

	public function getInvoiceByInvoiceId(string $invoice_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "qutpay_invoice` WHERE `invoice_id` = '" . $this->db->escape($invoice_id) . "' ORDER BY `date_modified` DESC LIMIT 1");

		return $query->row;
	}

	/** Inserts or replaces the row for an order (a new payment attempt overwrites the previous one). */
	public function setInvoice(array $data): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "qutpay_invoice` SET
			`order_id` = '" . (int)$data['order_id'] . "',
			`invoice_id` = '" . $this->db->escape((string)$data['invoice_id']) . "',
			`pay_url` = '" . $this->db->escape((string)$data['pay_url']) . "',
			`amount` = '" . (float)$data['amount'] . "',
			`status` = '" . $this->db->escape((string)$data['status']) . "',
			`token` = '" . $this->db->escape((string)$data['token']) . "',
			`attempt` = '" . (int)$data['attempt'] . "',
			`expires` = '" . (int)$data['expires'] . "',
			`receipt_url` = '',
			`date_added` = NOW(),
			`date_modified` = NOW()
			ON DUPLICATE KEY UPDATE
			`invoice_id` = VALUES(`invoice_id`),
			`pay_url` = VALUES(`pay_url`),
			`amount` = VALUES(`amount`),
			`status` = VALUES(`status`),
			`token` = VALUES(`token`),
			`attempt` = VALUES(`attempt`),
			`expires` = VALUES(`expires`),
			`receipt_url` = '',
			`date_modified` = NOW()");
	}

	/** Updates selected columns (invoice_id, status, receipt_url, pay_url, expires) of an existing row. */
	public function editInvoice(int $order_id, array $data): void {
		$set = [];

		foreach (['invoice_id', 'status', 'receipt_url', 'pay_url'] as $field) {
			if (isset($data[$field])) {
				$set[] = "`" . $field . "` = '" . $this->db->escape((string)$data[$field]) . "'";
			}
		}

		if (isset($data['expires'])) {
			$set[] = "`expires` = '" . (int)$data['expires'] . "'";
		}

		if (!$set) {
			return;
		}

		$set[] = "`date_modified` = NOW()";

		$this->db->query("UPDATE `" . DB_PREFIX . "qutpay_invoice` SET " . implode(', ', $set) . " WHERE `order_id` = '" . (int)$order_id . "'");
	}
}
