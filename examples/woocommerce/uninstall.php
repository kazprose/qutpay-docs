<?php
/**
 * Removes the plugin's own settings when it is deleted from the Plugins screen.
 * Orders and their meta stay — they are the merchant's accounting records.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'woocommerce_qutpay_settings' );
