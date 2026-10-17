<?php
/**
 * Qut Pay (Kaspi QR) — OpenCart 4.0.2+ payment extension, admin model.
 *
 * Owns the small `oc_qutpay_invoice` table that links an OpenCart order to a Qut Pay invoice.
 * Loaded as $this->load->model('extension/qutpay/payment/qutpay') → $this->model_extension_qutpay_payment_qutpay.
 */

namespace Opencart\Admin\Model\Extension\Qutpay\Payment;

class Qutpay extends \Opencart\System\Engine\Model {
	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "qutpay_invoice` (
			`order_id` INT(11) NOT NULL,
			`invoice_id` VARCHAR(64) NOT NULL DEFAULT '',
			`pay_url` VARCHAR(512) NOT NULL DEFAULT '',
			`amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
			`status` VARCHAR(32) NOT NULL DEFAULT 'pending',
			`token` VARCHAR(64) NOT NULL DEFAULT '',
			`attempt` INT(11) NOT NULL DEFAULT '1',
			`expires` INT(11) NOT NULL DEFAULT '0',
			`receipt_url` VARCHAR(512) NOT NULL DEFAULT '',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`order_id`),
			KEY `invoice_id` (`invoice_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "qutpay_invoice`");
	}
}
