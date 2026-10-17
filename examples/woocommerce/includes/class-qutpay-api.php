<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Tiny HTTP client for the Qut Pay merchant API. */
class QutPay_API {

	private $base;
	private $key;

	public function __construct( $api_key, $base_url = 'https://api.qut.kz' ) {
		$this->key  = trim( (string) $api_key );
		$this->base = rtrim( (string) $base_url, '/' );
	}

	/**
	 * @return array|WP_Error decoded JSON
	 */
	private function request( $method, $path, $body = null, $headers = array() ) {
		if ( '' === $this->key ) {
			return new WP_Error( 'qutpay_no_key', 'API-ключ Qut Pay не задан.' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array_merge(
				array(
					'X-API-Key'    => $this->key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'QutPay-WooCommerce/' . QUTPAY_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : ( 'HTTP ' . $code );
			$err = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'qutpay_http_' . $code;
			return new WP_Error( $err, $msg, array( 'status' => $code ) );
		}
		return $data;
	}

	public function create_invoice( array $payload, $idempotency_key = '' ) {
		$headers = $idempotency_key ? array( 'Idempotency-Key' => (string) $idempotency_key ) : array();
		return $this->request( 'POST', '/api/v1/invoices', $payload, $headers );
	}

	public function get_invoice( $id, $live = false ) {
		return $this->request( 'GET', '/api/v1/invoices/' . rawurlencode( $id ) . ( $live ? '?live=1' : '' ) );
	}

	public function refund_invoice( $id, $amount = null, $reason = '' ) {
		$body = array();
		if ( null !== $amount ) {
			$body['amount'] = (float) $amount;
		}
		if ( $reason ) {
			$body['reason'] = (string) $reason;
		}
		return $this->request( 'POST', '/api/v1/invoices/' . rawurlencode( $id ) . '/refund', $body ?: new stdClass() );
	}

	public function account() {
		return $this->request( 'GET', '/api/v1/account' );
	}
}
