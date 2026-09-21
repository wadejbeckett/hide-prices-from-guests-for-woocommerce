<?php
/**
 * The release archive ships the plugin and nothing else, and the plugin
 * runs without Composer.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseHygieneTest extends TestCase {
	/**
	 * Development-only paths that must never reach a release archive.
	 */
	private const DEV_ONLY = [
		'.github/',
		'.gitignore',
		'.gitattributes',
		'.distignore',
		'.phpcs.xml.dist',
		'.wp-env.json',
		'phpunit.xml.dist',
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'docs/',
		'tests/',
		'bin/',
		'.remember/',
		'.claude/',
		'vendor/',
	];

	private function root(): string {
		return dirname( __DIR__, 2 ) . '/';
	}

	public function test_gitattributes_export_ignores_every_dev_only_path(): void {
		$lines   = preg_split( '/\R/', (string) file_get_contents( $this->root() . '.gitattributes' ) );
		$ignored = [];
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^(\S+)\s+export-ignore\b/', (string) $line, $match ) ) {
				$ignored[] = $match[1];
			}
		}
		foreach ( self::DEV_ONLY as $path ) {
			$this->assertContains( $path, $ignored, ".gitattributes must mark $path export-ignore." );
		}
	}

	public function test_distignore_lists_every_dev_only_path(): void {
		$lines = array_map( 'trim', (array) preg_split( '/\R/', (string) file_get_contents( $this->root() . '.distignore' ) ) );
		foreach ( self::DEV_ONLY as $path ) {
			$this->assertContains( $path, $lines, ".distignore must list $path." );
		}
	}

	public function test_gitignore_keeps_local_state_out_of_the_repository(): void {
		$lines = array_map( 'trim', (array) preg_split( '/\R/', (string) file_get_contents( $this->root() . '.gitignore' ) ) );
		foreach ( [ 'vendor/', 'dist/', '.remember/', '.claude/settings.local.json', 'tests/probe/seed.json' ] as $path ) {
			$this->assertContains( $path, $lines, ".gitignore must list $path." );
		}
	}

	public function test_the_plugin_loads_its_own_autoloader_when_composer_is_absent(): void {
		$main = (string) file_get_contents( $this->root() . 'pricecloak-for-woocommerce.php' );
		$this->assertStringContainsString( "if ( file_exists( __DIR__ . '/vendor/autoload.php' ) )", $main );
		$this->assertStringContainsString( "require_once __DIR__ . '/src/Autoloader.php';", $main );
		$this->assertStringContainsString( "\\PriceCloak\\Autoloader::register( __DIR__ . '/src' );", $main );
		$this->assertFileExists( $this->root() . 'src/Autoloader.php' );
	}

	public function test_the_build_script_names_the_archive_after_the_slug_and_version(): void {
		$script = (string) file_get_contents( $this->root() . 'bin/build-zip.sh' );
		$this->assertStringContainsString( 'git -C "$ROOT" archive', $script );
		$this->assertStringContainsString( '--prefix="$SLUG/"', $script );
		$this->assertStringContainsString( 'dist/$SLUG-$VERSION.zip', $script );
	}
}
