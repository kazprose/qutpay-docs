<?php
/**
 * Plugin Name: Qut Pay for WooCommerce
 * Plugin URI:  https://api.qut.kz/docs/guide/en/wordpress
 * Description: Accept Kaspi QR payments in WooCommerce through the Qut Pay service. Money settles directly in your own Kaspi Pay account. Independent service, not affiliated with Kaspi Bank.
 * Version:     1.0.0
 * Author:      Qut Pay
 * Author URI:  https://qut.kz/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qut-pay-for-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUTPAY_WC_VERSION', '1.0.0' );
define( 'QUTPAY_WC_FILE', __FILE__ );
define( 'QUTPAY_WC_DIR', plugin_dir_path( __FILE__ ) );

// HPOS (custom order tables) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Qut Pay for WooCommerce:</strong> ' . esc_html__( 'требуется установленный и активированный WooCommerce.', 'qut-pay-for-woocommerce' ) . '</p></div>';
		} );
		return;
	}
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-api.php';
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-wc-gateway.php';
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-webhook.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'QutPay_WC_Gateway';
		return $gateways;
	} );

	QutPay_Webhook::init();
}, 11 );

// "Settings" link on the plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=qutpay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Настройки', 'qut-pay-for-woocommerce' ) . '</a>' );
	return $links;
} );

// Blocks checkout support (WooCommerce Blocks): register a minimal integration when available.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new QutPay_Blocks_Support() );
	} );
} );
