<?php
/**
 * Qut Pay (Kaspi QR) — OpenCart 4.0.2+ payment extension, admin controller.
 *
 * Route: extension/qutpay/payment/qutpay
 * Settings are stored under the code `payment_qutpay` (payment_qutpay_status, payment_qutpay_api_key, …).
 */

namespace Opencart\Admin\Controller\Extension\Qutpay\Payment;

class Qutpay extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/qutpay/payment/qutpay';
	private const DEFAULT_API_BASE = 'https://api.qut.kz';

	/**
	 * Order status ids pre-selected the first time the form is opened
	 * (ids of a stock OpenCart install: Pending, Processing, Expired, Canceled, Refunded).
	 */
	private const DEFAULT_STATUS = [
		'pending'   => 1,
		'paid'      => 2,
		'expired'   => 14,
		'cancelled' => 7,
		'refunded'  => 11,
	];

	public function index(): void {
		$this->load->language(self::ROUTE);

		$this->document->setTitle($this->language->get('heading_title'));

		$user_token = (string)$this->session->data['user_token'];

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $user_token)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $user_token . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link(self::ROUTE, 'user_token=' . $user_token)
		];

		$data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $user_token);
		$data['check'] = $this->url->link(self::ROUTE . '.check', 'user_token=' . $user_token);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $user_token . '&type=payment');

		// The merchant registers this address in the Qut Pay cabinet (Webhook section).
		$data['webhook_url'] = $this->getWebhookUrl();

		$data['payment_qutpay_status'] = (bool)$this->config->get('payment_qutpay_status');
		$data['payment_qutpay_api_key'] = (string)$this->config->get('payment_qutpay_api_key');
		$data['payment_qutpay_webhook_secret'] = (string)$this->config->get('payment_qutpay_webhook_secret');

		$api_base = trim((string)$this->config->get('payment_qutpay_api_base'));
		$data['payment_qutpay_api_base'] = ($api_base !== '') ? $api_base : self::DEFAULT_API_BASE;

		foreach (self::DEFAULT_STATUS as $key => $default) {
			$value = $this->config->get('payment_qutpay_' . $key . '_status_id');

			$data['payment_qutpay_' . $key . '_status_id'] = ($value === null || $value === '') ? $default : (int)$value;
		}

		$data['payment_qutpay_geo_zone_id'] = (int)$this->config->get('payment_qutpay_geo_zone_id');
		$data['payment_qutpay_sort_order'] = (int)$this->config->get('payment_qutpay_sort_order');
		$data['payment_qutpay_debug'] = (bool)$this->config->get('payment_qutpay_debug');

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');

		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function save(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;

		$api_key = trim((string)($post['payment_qutpay_api_key'] ?? ''));
		$webhook_secret = trim((string)($post['payment_qutpay_webhook_secret'] ?? ''));
		$api_base = rtrim(trim((string)($post['payment_qutpay_api_base'] ?? '')), '/');

		if ($api_base === '') {
			$api_base = self::DEFAULT_API_BASE;
		}

		if (!empty($post['payment_qutpay_status']) && $api_key === '') {
			$json['error']['api_key'] = $this->language->get('error_api_key');
		}

		if (!preg_match('~^https?://[^\s/]+$~i', $api_base)) {
			$json['error']['api_base'] = $this->language->get('error_api_base');
		}

		if (!$json) {
			// Normalise what we store; editSetting() persists every key that starts with `payment_qutpay`.
			$post['payment_qutpay_api_key'] = $api_key;
			$post['payment_qutpay_webhook_secret'] = $webhook_secret;
			$post['payment_qutpay_api_base'] = $api_base;
			$post['payment_qutpay_sort_order'] = (int)($post['payment_qutpay_sort_order'] ?? 0);
			$post['payment_qutpay_geo_zone_id'] = (int)($post['payment_qutpay_geo_zone_id'] ?? 0);
			$post['payment_qutpay_status'] = !empty($post['payment_qutpay_status']) ? 1 : 0;
			$post['payment_qutpay_debug'] = !empty($post['payment_qutpay_debug']) ? 1 : 0;

			foreach (array_keys(self::DEFAULT_STATUS) as $key) {
				$post['payment_qutpay_' . $key . '_status_id'] = (int)($post['payment_qutpay_' . $key . '_status_id'] ?? 0);
			}

			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('payment_qutpay', $post);

			$json['success'] = $this->language->get('text_success');

			if ($webhook_secret === '') {
				$json['success'] .= ' ' . $this->language->get('text_no_secret');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** "Check key" button: calls GET /api/v1/account with the key currently typed into the form. */
	public function check(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$api_key = trim((string)($this->request->post['payment_qutpay_api_key'] ?? ''));
		$api_base = rtrim(trim((string)($this->request->post['payment_qutpay_api_base'] ?? '')), '/');

		if ($api_base === '') {
			$api_base = self::DEFAULT_API_BASE;
		}

		if (!$json && $api_key === '') {
			$json['error']['api_key'] = $this->language->get('error_api_key');
		}

		if (!$json) {
			try {
				$this->loadSdk();

				$client = new \QutPay\Client($api_key, $api_base, 15);

				$account = $client->account();

				$label = '';

				// The account payload shape is not pinned down by the docs; pick a human label if one is present.
				foreach (['name', 'title', 'organization', 'org'] as $field) {
					if (!empty($account[$field]) && is_string($account[$field])) {
						$label = $account[$field];
						break;
					}

					if (!empty($account[$field]['name']) && is_string($account[$field]['name'])) {
						$label = $account[$field]['name'];
						break;
					}
				}

				if (!empty($account['mode']) && is_string($account['mode'])) {
					$label .= ($label !== '' ? ', ' : '') . $account['mode'];
				}

				$json['success'] = sprintf($this->language->get('text_check_ok'), htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
			} catch (\Throwable $e) {
				$json['error']['warning'] = sprintf($this->language->get('text_check_fail'), htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** Called by Extensions → Payments → Install. */
	public function install(): void {
		if ($this->user->hasPermission('modify', self::ROUTE)) {
			$this->load->model(self::ROUTE);

			$this->model_extension_qutpay_payment_qutpay->install();
		}
	}

	/** Called by Extensions → Payments → Uninstall. The settings themselves are removed by OpenCart. */
	public function uninstall(): void {
		if ($this->user->hasPermission('modify', self::ROUTE)) {
			$this->load->model(self::ROUTE);

			$this->model_extension_qutpay_payment_qutpay->uninstall();
		}
	}

	private function getWebhookUrl(): string {
		if (defined('HTTPS_CATALOG') && HTTPS_CATALOG) {
			$catalog = HTTPS_CATALOG;
		} elseif (defined('HTTP_CATALOG') && HTTP_CATALOG) {
			$catalog = HTTP_CATALOG;
		} else {
			$catalog = '/';
		}

		return rtrim($catalog, '/') . '/index.php?route=extension/qutpay/payment/qutpay.webhook';
	}

	private function loadSdk(): void {
		if (!class_exists('QutPay\\Client', false)) {
			// extension/qutpay/admin/controller/payment → extension/qutpay
			require_once dirname(__DIR__, 3) . '/system/library/qutpay/QutPay.php';
		}
	}
}
