<?php
/**
 * uninstall.php really runs and really removes every option, and nothing else.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Modules;
use PriceCloak\Options;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
	}

	public function test_uninstall_removes_every_plugin_option_and_nothing_else(): void {
		$ids   = array_map( static fn( $module ) => $module->id(), Modules::all() );
		$names = Options::all_names( $ids );
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			update_option( $name, 'yes' );
		}
		update_option( 'unrelated_option', 'keep' );
		$this->assertCount( count( $names ) + 1, $GLOBALS['pricecloak_test']['options'] );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['pricecloak_test']['options'] );
	}

	public function test_uninstall_bootstraps_its_own_autoloader(): void {
		// Uninstall runs with no Composer autoloader and no plugin file loaded,
		// so the script must load src/ itself before touching any class.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( "require_once __DIR__ . '/src/Autoloader.php';", $source );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $source );
	}
}
