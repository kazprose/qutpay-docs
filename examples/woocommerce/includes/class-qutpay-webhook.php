<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** REST endpoint /wp-json/qutpay/v1/webhook — receives signed events from Qut Pay. */
class QutPay_Webhook {

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'qutpay/v1', '/webhook', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public on purpose: the request is authenticated by the HMAC-SHA256 signature checked in handle(), not by a WordPress user.
				'permission_callback' => '__return_true',
			) );
		} );
	}

	public static function handle( WP_REST_Request $req ) {
		$gateway = QutPay_WC_Gateway::instance();
		if ( ! $gateway ) {
			return new WP_REST_Response( array( 'error' => 'gateway_unavailable' ), 503 );
		}
		$secret = (string) $gateway->get_option( 'webhook_secret' );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'no_secret' ), 503 );
		}

		$raw = $req->get_body();
		$sig = (string) $req->get_header( 'x-webhook-signature' );
		$ts  = (string) $req->get_header( 'x-webhook-timestamp' );
		if ( ! preg_match( '/^\d{1,12}$/', $ts ) || abs( time() - (int) $ts ) > 300 ) {
			return new WP_REST_Response( array( 'error' => 'stale_timestamp' ), 401 );
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $raw, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			$gateway->log( 'webhook signature mismatch', 'warning' );
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 401 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['event'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}
		if ( 'webhook.test' === $data['event'] ) {
			$gateway->log( 'webhook.test received OK' );
			return new WP_REST_Response( array( 'ok' => true, 'test' => true ), 200 );
		}
		$invoice = isset( $data['invoice'] ) && is_array( $data['invoice'] ) ? $data['invoice'] : array();
		$order   = self::find_order( $invoice );
		if ( ! $order ) {
			// Not our order (another site on the same key?) — acknowledge so it is not retried.
			$gateway->log( 'webhook: order not found for invoice ' . ( isset( $invoice['id'] ) ? $invoice['id'] : '?' ), 'warning' );
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'order_not_found' ), 200 );
		}
		$result = QutPay_WC_Gateway::apply_invoice( $order, $invoice, 'webhook ' . $data['event'] );
		$gateway->log( 'webhook ' . $data['event'] . ' for order ' . $order->get_id() . ': ' . $result );
		return new WP_REST_Response( array( 'ok' => true, 'result' => $result ), 200 );
	}

	private static function find_order( array $invoice ) {
		$meta_order = isset( $invoice['metadata']['wc_order_id'] ) ? (int) $invoice['metadata']['wc_order_id'] : 0;
		if ( $meta_order ) {
			$order = wc_get_order( $meta_order );
			if ( $order && ( empty( $invoice['id'] ) || $order->get_meta( '_qutpay_invoice_id' ) === $invoice['id'] ) ) {
				return $order;
			}
		}
		if ( ! empty( $invoice['id'] ) ) {
			$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => '_qutpay_invoice_id', 'meta_value' => $invoice['id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $orders ) {
				return $orders[0];
			}
		}
		return null;
	}
}
