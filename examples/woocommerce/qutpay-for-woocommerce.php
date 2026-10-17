<?php
/**
 * Plugin Name: Qut Pay for WooCommerce
 * Plugin URI:  https://qut.kz/
 * Description: Приём оплаты через Kaspi (QR / ссылка) с помощью Qut Pay. Деньги поступают напрямую на ваш Kaspi Pay.
 * Version:     0.1.0
 * Author:      Qut Pay
 * Author URI:  https://qut.kz/
 * License:     GPLv2 or later
 * Text Domain: qutpay-for-woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUTPAY_WC_VERSION', '0.1.0' );
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
			echo '<div class="notice notice-error"><p><strong>Qut Pay for WooCommerce:</strong> требуется установленный и активированный WooCommerce.</p></div>';
		} );
		return;
	}
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-api.php';
	require_once QUTPAY_WC_DIR . 'includes/class-wc-gateway-qutpay.php';
	require_once QUTPAY_WC_DIR . 'includes/class-qutpay-webhook.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_QutPay';
		return $gateways;
	} );

	QutPay_Webhook::init();
}, 11 );

// "Settings" link on the plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=qutpay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );
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
