<?php
/**
 * The login link: where it goes and what it carries as the return target.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit;

use PriceCloak\Replacement;
use PHPUnit\Framework\TestCase;

final class ReplacementTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
		$_SERVER['HTTP_HOST']   = 'example.test';
		$_SERVER['REQUEST_URI'] = '/product/probe-simple/?variant=2';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	// -- current_url() -----------------------------------------------------

	public function test_the_current_url_is_scheme_host_and_request_uri(): void {
		$this->assertSame( 'http://example.test/product/probe-simple/?variant=2', Replacement::current_url() );
	}

	public function test_https_is_used_when_the_request_is_ssl(): void {
		$GLOBALS['pricecloak_test']['is_ssl'] = true;

		$this->assertSame( 'https://example.test/product/probe-simple/?variant=2', Replacement::current_url() );
	}

	public function test_a_subdirectory_install_does_not_double_the_base_path(): void {
		$GLOBALS['pricecloak_test']['home_url'] = 'http://example.test/shop';
		$_SERVER['REQUEST_URI']                 = '/shop/product/probe-simple/';

		$this->assertSame( 'http://example.test/shop/product/probe-simple/', Replacement::current_url() );
	}

	public function test_a_forged_host_header_falls_back_to_the_home_url(): void {
		$_SERVER['HTTP_HOST'] = 'evil.example';

		$this->assertSame( 'http://example.test/', Replacement::current_url() );
	}

	public function test_a_forged_host_header_on_a_subdirectory_install_falls_back_to_the_home_url(): void {
		$GLOBALS['pricecloak_test']['home_url'] = 'http://example.test/shop';
		$_SERVER['HTTP_HOST']                   = 'evil.example';

		$this->assertSame( 'http://example.test/shop/', Replacement::current_url() );
	}

	public function test_a_host_header_with_a_port_is_the_home_host(): void {
		$GLOBALS['pricecloak_test']['home_url'] = 'http://example.test:8888';
		$_SERVER['HTTP_HOST']                   = 'example.test:8888';

		$this->assertSame( 'http://example.test:8888/product/probe-simple/?variant=2', Replacement::current_url() );
	}

	public function test_a_missing_host_header_falls_back_to_the_home_url(): void {
		unset( $_SERVER['HTTP_HOST'] );

		$this->assertSame( 'http://example.test/', Replacement::current_url() );
	}

	public function test_a_missing_request_uri_is_the_home_url(): void {
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertSame( 'http://example.test/', Replacement::current_url() );
	}

	// -- login_url() -------------------------------------------------------

	public function test_the_link_goes_to_my_account_with_the_current_url_as_redirect_to(): void {
		$this->assertSame(
			'http://example.test/my-account/?redirect_to=' . rawurlencode( 'http://example.test/product/probe-simple/?variant=2' ),
			Replacement::login_url()
		);
	}

	public function test_without_a_my_account_page_the_link_goes_to_wp_login(): void {
		$GLOBALS['pricecloak_test']['pages']['myaccount'] = '';

		$this->assertSame(
			'http://example.test/wp-login.php?redirect_to=' . rawurlencode( 'http://example.test/product/probe-simple/?variant=2' ),
			Replacement::login_url()
		);
	}

	public function test_the_login_link_never_carries_an_off_site_target(): void {
		$_SERVER['HTTP_HOST'] = 'evil.example';

		$url = Replacement::login_url();

		$this->assertStringNotContainsString( 'evil', $url );
		$this->assertStringContainsString( rawurlencode( 'http://example.test/' ), $url );
	}
}
