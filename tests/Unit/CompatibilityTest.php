<?php
/**
 * The plugin file declares compatibility with the WooCommerce features it
 * supports, so WooCommerce never shows an "incompatible plugin" notice.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Plugin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/features-util-stub.php';

final class CompatibilityTest extends TestCase {
	private const PLUGIN_FILE = 'pricecloak-for-woocommerce.php';

	protected function setUp(): void {
		pricecloak_test_reset();
	}

	/**
	 * Load the plugin file once, remember what it hooked to
	 * before_woocommerce_init, and run those callbacks.
	 *
	 * The snapshot matters because pricecloak_test_reset() empties the recorded
	 * hooks between tests while require_once loads the file only once.
	 *
	 * @var array<int, array{0: callable, 1: int, 2: int}>|null
	 */
	private static ?array $hooked = null;

	/**
	 * Run the callbacks the plugin file attached to before_woocommerce_init.
	 */
	private function boot_declarations(): void {
		if ( null === self::$hooked ) {
			require_once dirname( __DIR__, 2 ) . '/' . self::PLUGIN_FILE;
			self::$hooked = $GLOBALS['pricecloak_test']['filters']['before_woocommerce_init'] ?? [];
		}
		foreach ( self::$hooked as $entry ) {
			( $entry[0] )();
		}
	}

	public function test_hpos_and_cart_checkout_blocks_are_declared_compatible(): void {
		$this->boot_declarations();

		$declared = $GLOBALS['pricecloak_test']['compatibility'];
		$this->assertArrayHasKey( 'custom_order_tables', $declared );
		$this->assertArrayHasKey( 'cart_checkout_blocks', $declared );

		foreach ( [ 'custom_order_tables', 'cart_checkout_blocks' ] as $feature ) {
			$this->assertTrue( $declared[ $feature ]['compatible'], "$feature must be declared compatible, not incompatible." );
			$this->assertSame(
				self::PLUGIN_FILE,
				basename( $declared[ $feature ]['file'] ),
				'The declaration must name this plugin file; WooCommerce keys the feature screen by it.'
			);
			$this->assertFileExists( $declared[ $feature ]['file'] );
		}
	}

	public function test_declarations_run_on_before_woocommerce_init(): void {
		$this->boot_declarations();

		$this->assertNotSame(
			[],
			self::$hooked,
			'FeaturesUtil must be called on before_woocommerce_init; any later hook is too late for the features screen.'
		);
	}

	/**
	 * Read one "Key: value" header line from a file, the way WordPress's
	 * get_file_data() does for the plugin header and the readme.
	 */
	private function header( string $file, string $key ): string {
		$contents = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		$this->assertSame(
			1,
			preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':\s*(.+?)\s*$/mi', $contents, $match ),
			"$file must carry a '$key:' header line."
		);
		return $match[1];
	}

	public function test_plugin_version_agrees_with_the_header_and_the_stable_tag(): void {
		$this->assertSame( $this->header( self::PLUGIN_FILE, 'Version' ), Plugin::VERSION, 'Plugin::VERSION must equal the Version: header.' );
		$this->assertSame( $this->header( 'readme.txt', 'Stable tag' ), Plugin::VERSION, 'Plugin::VERSION must equal the Stable tag: in readme.txt.' );
	}
}
