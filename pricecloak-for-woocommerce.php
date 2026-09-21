<?php
/**
 * Plugin Name:       PriceCloak for WooCommerce
 * Plugin URI:        https://github.com/wadejbeckett/pricecloak-for-woocommerce
 * Description:       One switch: logged-out visitors see no prices anywhere WooCommerce renders or serves them. Optional purchasing block.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:  11.1.0
 * Author:            Wade Beckett
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pricecloak-for-woocommerce
 *
 * @package PriceCloak
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'PRICECLOAK_FILE', __FILE__ );
define( 'PRICECLOAK_DIR', __DIR__ . '/' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Autoloader.php';
	\PriceCloak\Autoloader::register( __DIR__ . '/src' );
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\PriceCloak\Options::migrate_legacy( \PriceCloak\Plugin::module_ids() );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\PriceCloak\Options::migrate_legacy( \PriceCloak\Plugin::module_ids() );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$plugin = new \PriceCloak\Plugin( \PriceCloak\Modules::all() );
		$plugin->boot();
	}
);
