<?php
/**
 * Unit test bootstrap: no WordPress, no WooCommerce. Pure PHP plus stubs.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pricecloak_composer = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $pricecloak_composer ) ) {
	require_once $pricecloak_composer;
} else {
	require_once dirname( __DIR__ ) . '/src/Autoloader.php';
	\PriceCloak\Autoloader::register( dirname( __DIR__ ) . '/src' );
}

// After Composer so the shim sees PHPUnit's real TestCase and defines nothing.
require_once __DIR__ . '/Support/testcase-shim.php';
require_once __DIR__ . '/Support/wp-stubs.php';
require_once __DIR__ . '/Support/rest-stubs.php';
require_once __DIR__ . '/Support/wc-stubs.php';
