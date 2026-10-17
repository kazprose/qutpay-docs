<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** Checkout Blocks support: shows the gateway as a redirect-based method. */
final class QutPay_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'qutpay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_qutpay_settings', array() );
	}

	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'qutpay-blocks',
			plugins_url( 'assets/blocks.js', QUTPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			QUTPAY_WC_VERSION,
			true
		);
		return array( 'qutpay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : 'Kaspi арқылы төлеу / Оплата через Kaspi',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products', 'refunds' ),
		);
	}
}
