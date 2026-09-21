<?php
/**
 * Remove every option this plugin stored.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\PriceCloak\Autoloader::register( __DIR__ . '/src' );

$pricecloak_ids = array_map( static fn( $m ) => $m->id(), \PriceCloak\Modules::all() );
foreach ( \PriceCloak\Options::all_names( $pricecloak_ids ) as $pricecloak_name ) {
	delete_option( $pricecloak_name );
	// A site that upgraded from the plugin's previous name may still hold the
	// `hpfg_*` row if it was never activated under the new name.
	delete_option( \PriceCloak\Options::legacy_name( $pricecloak_name ) );
}

// Bookkeeping, not a setting, so it is not part of all_names().
delete_option( \PriceCloak\Options::MIGRATED );
