<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QutPay_WC_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'qutpay';
		$this->method_title       = 'Qut Pay (Kaspi QR)';
		$this->method_description = 'Оплата через Kaspi: покупатель сканирует QR или открывает ссылку в приложении Kaspi.kz. Деньги поступают напрямую на ваш Kaspi Pay. Ключ API и секрет webhook — в кабинете <a href="https://qut.kz/app" target="_blank" rel="noopener">qut.kz/app</a>.';
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = apply_filters( 'qutpay_wc_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', 'Kaspi арқылы төлеу / Оплата через Kaspi' );
		$this->description = $this->get_option( 'description', 'QR немесе Kaspi сілтемесі арқылы төлеңіз. Ақша сатушының Kaspi Pay шотына тікелей түседі.' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_sync' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function init_form_fields() {
		$webhook_url = rest_url( 'qutpay/v1/webhook' );
		$this->form_fields = array(
			'enabled'        => array( 'title' => 'Включить', 'type' => 'checkbox', 'label' => 'Включить оплату через Kaspi (Qut Pay)', 'default' => 'no' ),
			'title'          => array( 'title' => 'Название на кассе', 'type' => 'text', 'default' => 'Kaspi арқылы төлеу / Оплата через Kaspi', 'desc_tip' => true, 'description' => 'Что видит покупатель при выборе способа оплаты.' ),
			'description'    => array( 'title' => 'Описание', 'type' => 'textarea', 'default' => 'QR немесе Kaspi сілтемесі арқылы төлеңіз. Ақша сатушының Kaspi Pay шотына тікелей түседі.' ),
			'api_key'        => array( 'title' => 'API-ключ', 'type' => 'password', 'description' => 'Кабинет Qut Pay → API кілттері → «Жасау». Ключ qp_live_… для реальных платежей, qp_test_… — песочница.', 'default' => '' ),
			'webhook_secret' => array( 'title' => 'Секрет webhook', 'type' => 'password', 'description' => 'Кабинет Qut Pay → Webhook → добавьте адрес <code>' . esc_html( $webhook_url ) . '</code> и вставьте сюда показанный секрет.', 'default' => '' ),
			'api_base'       => array( 'title' => 'Адрес API', 'type' => 'text', 'default' => 'https://api.qut.kz', 'description' => 'Менять не нужно.' ),
			'order_status'   => array( 'title' => 'Статус после оплаты', 'type' => 'select', 'default' => 'processing', 'options' => array( 'processing' => 'Обработка (processing)', 'completed' => 'Выполнен (completed)' ), 'description' => 'Для цифровых услуг обычно «Выполнен».' ),
			'debug'          => array( 'title' => 'Лог', 'type' => 'checkbox', 'label' => 'Писать события в WooCommerce → Статус → Логи (qutpay)', 'default' => 'no' ),
		);
	}

	public function admin_notices() {
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		if ( ! $this->get_option( 'api_key' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Qut Pay:</strong> ' . esc_html__( 'укажите API-ключ в настройках способа оплаты.', 'qut-pay-for-woocommerce' ) . '</p></div>';
		}
		if ( ! $this->get_option( 'webhook_secret' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Qut Pay:</strong> ' . esc_html__( 'без секрета webhook заказы не будут отмечаться как оплаченные автоматически.', 'qut-pay-for-woocommerce' ) . '</p></div>';
		}
	}

	private function api() {
		return new QutPay_API( $this->get_option( 'api_key' ), $this->get_option( 'api_base', 'https://api.qut.kz' ) );
	}

	public function log( $msg, $level = 'info' ) {
		if ( 'yes' === $this->get_option( 'debug' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $msg, array( 'source' => 'qutpay' ) );
		}
	}

	/** Creates the Qut Pay invoice and redirects the customer to the hosted payment page. */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( 'KZT' !== $order->get_currency() ) {
			wc_add_notice( 'Оплата через Kaspi доступна только в тенге (KZT).', 'error' );
			return array( 'result' => 'failure' );
		}

		// Reuse an open invoice for the same order (customer went back and pressed "pay" again).
		$existing = $order->get_meta( '_qutpay_invoice_id' );
		$existing_url = $order->get_meta( '_qutpay_pay_url' );
		$existing_exp = (int) $order->get_meta( '_qutpay_expires' );
		if ( $existing && $existing_url && $existing_exp > time() + 20 && $order->get_meta( '_qutpay_amount' ) == $order->get_total() ) {
			return array( 'result' => 'success', 'redirect' => $existing_url );
		}

		$payload = array(
			'amount'          => round( (float) $order->get_total(), 2 ),
			'kind'            => 'qr',
			'description'     => mb_substr( sprintf( '%s — заказ #%s', get_bloginfo( 'name' ), $order->get_order_number() ), 0, 100 ),
			'externalOrderId' => (string) $order->get_order_number(),
			'customer'        => array_filter( array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'phone' => preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ) ?: null,
				'email' => $order->get_billing_email() ?: null,
			) ),
			'successUrl'      => $this->get_return_url( $order ),
			'failUrl'         => $order->get_checkout_payment_url( false ),
			'metadata'        => array( 'wc_order_id' => $order->get_id(), 'wc_order_key' => $order->get_order_key(), 'site' => home_url() ),
		);
		// Kaspi phone invoices need 7XXXXXXXXXX; only pass phones that normalise cleanly.
		if ( ! empty( $payload['customer']['phone'] ) ) {
			$p = $payload['customer']['phone'];
			if ( 10 === strlen( $p ) && '7' === $p[0] ) { $p = '7' . $p; }
			if ( 11 === strlen( $p ) && '8' === $p[0] ) { $p = '7' . substr( $p, 1 ); }
			if ( 11 !== strlen( $p ) || '7' !== $p[0] ) { unset( $payload['customer']['phone'] ); } else { $payload['customer']['phone'] = $p; }
		}

		// A fresh key per attempt: the API replays any invoice with the same key, so after a QR expires
		// the old key would send the customer to a dead invoice page.
		$attempt = (int) $order->get_meta( '_qutpay_attempt' ) + 1;
		$idem    = 'wc-' . $order->get_id() . '-' . substr( md5( $order->get_order_key() . $order->get_total() ), 0, 8 ) . '-' . $attempt;
		$inv     = $this->api()->create_invoice( $payload, $idem );
		if ( is_wp_error( $inv ) ) {
			$this->log( 'create_invoice failed for order ' . $order_id . ': ' . $inv->get_error_message(), 'error' );
			$order->add_order_note( 'Qut Pay: не удалось создать счёт — ' . $inv->get_error_message() );
			wc_add_notice( 'Не удалось начать оплату через Kaspi: ' . esc_html( $inv->get_error_message() ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_qutpay_invoice_id', $inv['id'] );
		$order->update_meta_data( '_qutpay_pay_url', $inv['payUrl'] );
		$order->update_meta_data( '_qutpay_amount', $order->get_total() );
		$order->update_meta_data( '_qutpay_expires', ! empty( $inv['expiresAt'] ) ? strtotime( $inv['expiresAt'] ) : time() + 170 );
		$order->update_meta_data( '_qutpay_mode', isset( $inv['mode'] ) ? $inv['mode'] : '' );
		$order->update_status( 'pending', 'Qut Pay: счёт ' . $inv['id'] . ' создан, ожидание оплаты через Kaspi.' );
		$order->save();
		$this->log( 'invoice ' . $inv['id'] . ' created for order ' . $order_id );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			// keep the cart until payment is confirmed (customer may come back)
		}
		return array( 'result' => 'success', 'redirect' => $inv['payUrl'] );
	}

	/** Applies an invoice status to the order (used by webhook and thank-you fallback). Idempotent. */
	public static function apply_invoice( WC_Order $order, array $invoice, $source = 'webhook' ) {
		$status = isset( $invoice['status'] ) ? $invoice['status'] : '';
		$last   = $order->get_meta( '_qutpay_last_status' );
		if ( $status === $last ) {
			return 'unchanged';
		}
		$gateway = self::instance();
		$id      = isset( $invoice['id'] ) ? $invoice['id'] : '';

		switch ( $status ) {
			case 'paid':
				if ( $order->has_status( 'refunded' ) || (float) $order->get_total_refunded() >= (float) $order->get_total() - 0.01 ) {
					// a late/retried "paid" snapshot must never re-open a refunded order
					$order->add_order_note( 'Qut Pay: событие paid после возврата проигнорировано (' . $source . ').' );
					break;
				}
				if ( ! $order->is_paid() ) {
					if ( isset( $invoice['amount'] ) && abs( (float) $invoice['amount'] - (float) $order->get_total() ) > 0.01 ) {
						$order->add_order_note( sprintf( 'Qut Pay: сумма счёта %s не совпадает с суммой заказа %s — проверьте вручную.', $invoice['amount'], $order->get_total() ) );
						$order->update_status( 'on-hold' );
					} else {
						$order->payment_complete( $id );
						$note = 'Qut Pay: оплачено через Kaspi (' . $source . ').';
						if ( ! empty( $invoice['receiptNumber'] ) ) {
							$note .= ' Чек ' . $invoice['receiptNumber'] . ( ! empty( $invoice['receiptUrl'] ) ? ' — ' . $invoice['receiptUrl'] : '' );
						}
						$order->add_order_note( $note );
						$target = $gateway ? $gateway->get_option( 'order_status', 'processing' ) : 'processing';
						if ( 'completed' === $target && 'completed' !== $order->get_status() ) {
							$order->update_status( 'completed' );
						}
					}
				}
				break;
			case 'failed':
			case 'expired':
			case 'cancelled':
				if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
					$order->update_status( 'failed' === $status ? 'failed' : 'cancelled', 'Qut Pay: счёт ' . $status . ' (' . $source . '). Покупатель может оплатить заново.' );
				}
				break;
			case 'refunded':
			case 'partially_refunded':
				$order->add_order_note( sprintf( 'Qut Pay: возврат %s, всего возвращено %s ₸ (%s).', $status, isset( $invoice['refundedAmount'] ) ? $invoice['refundedAmount'] : '?', $source ) );
				if ( 'refunded' === $status && ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded' );
				}
				break;
			default:
				return 'ignored';
		}
		$order->update_meta_data( '_qutpay_last_status', $status );
		if ( ! empty( $invoice['receiptUrl'] ) ) {
			$order->update_meta_data( '_qutpay_receipt_url', $invoice['receiptUrl'] );
		}
		$order->save();
		return 'applied';
	}

	/** Thank-you page fallback: if the webhook has not arrived yet, ask the API directly. */
	public function thankyou_sync( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$inv_id = $order->get_meta( '_qutpay_invoice_id' );
		if ( ! $inv_id ) {
			return;
		}
		$inv = $this->api()->get_invoice( $inv_id, true );
		if ( ! is_wp_error( $inv ) ) {
			self::apply_invoice( $order, $inv, 'thank-you' );
		}
		if ( ! $order->is_paid() ) {
			echo '<p class="qutpay-pending">' . esc_html__( 'Төлем әлі расталған жоқ. Kaspi-де төлеп болсаңыз, бірнеше секундтан кейін бетті жаңартыңыз. / Оплата ещё не подтверждена, обновите страницу через несколько секунд.', 'qut-pay-for-woocommerce' ) . '</p>';
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = wc_get_order( $order_id );
		$inv_id = $order ? $order->get_meta( '_qutpay_invoice_id' ) : '';
		if ( ! $inv_id ) {
			return new WP_Error( 'qutpay_no_invoice', 'У заказа нет счёта Qut Pay.' );
		}
		$r = $this->api()->refund_invoice( $inv_id, $amount, $reason );
		if ( is_wp_error( $r ) ) {
			$this->log( 'refund failed for order ' . $order_id . ': ' . $r->get_error_message(), 'error' );
			return $r;
		}
		$order->add_order_note( sprintf( 'Qut Pay: возврат %s ₸ выполнен (%s).', $amount, isset( $r['refund']['id'] ) ? $r['refund']['id'] : '' ) );
		return true;
	}

	/** @return QutPay_WC_Gateway|null */
	public static function instance() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['qutpay'] ) ? $gateways['qutpay'] : null;
	}
}
