<?php
/**
 * Option defaults agreed in docs/SPEC.md.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
	}

	public function test_defaults_match_spec(): void {
		$this->assertFalse( Options::enabled() );
		$this->assertSame( 'Sign in to see prices', Options::replacement_text() );
		$this->assertTrue( Options::link_to_login() );
		$this->assertFalse( Options::block_purchasing() );
		$this->assertTrue( Options::module_enabled( 'anything' ) );
	}

	public function test_blank_replacement_text_falls_back_to_default(): void {
		update_option( Options::REPLACEMENT_TEXT, '' );
		$this->assertSame( 'Sign in to see prices', Options::replacement_text() );
		update_option( Options::REPLACEMENT_TEXT, 'Log in for trade prices' );
		$this->assertSame( 'Log in for trade prices', Options::replacement_text() );
	}

	public function test_all_names_covers_master_and_modules(): void {
		$names = Options::all_names( [ 'price_html', 'store_api' ] );
		$this->assertContains( 'pricecloak_enabled', $names );
		$this->assertContains( 'pricecloak_module_price_html', $names );
		$this->assertContains( 'pricecloak_module_store_api', $names );
		$this->assertCount( 6, $names );
	}
}
