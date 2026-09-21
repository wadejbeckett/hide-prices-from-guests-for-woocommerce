<?php
/**
 * Purchasing module: guests are refused add-to-cart on every path, sent from
 * cart and checkout to the login page, and offered a login link instead of an
 * add-to-cart button. Logged-in shoppers are untouched, and nothing this
 * module registers can empty a cart.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Unit\Modules;

use PriceCloak\Modules;
use PriceCloak\Modules\Purchasing;
use PriceCloak\Options;
use PriceCloak\Plugin;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * The module with its process-ending exit() replaced, so a redirect can be
 * asserted without the test run stopping.
 */
final class PurchasingSpy extends Purchasing {
	public int $halted = 0;

	protected function halt(): void {
		++$this->halted;
	}
}

final class PurchasingTest extends TestCase {
	protected function setUp(): void {
		pricecloak_test_reset();
	}

	private function guest(): Purchasing {
		return new Purchasing();
	}

	private function logged_in(): Purchasing {
		$GLOBALS['pricecloak_test']['logged_in'] = true;
		return new Purchasing();
	}

	// -- identity and wiring ----------------------------------------------

	public function test_id_is_purchasing(): void {
		$this->assertSame( 'purchasing', $this->guest()->id() );
	}

	public function test_module_is_in_the_registry(): void {
		$ids = array_map( static fn( $m ) => $m->id(), Modules::all() );
		$this->assertContains( 'purchasing', $ids );
	}

	public function test_it_is_off_by_default_even_with_the_master_switch_on(): void {
		update_option( Options::ENABLED, 'yes' );
		$plugin = new Plugin( Modules::all() );
		$plugin->boot();

		$this->assertFalse(
			in_array( 'purchasing', $plugin->registered_module_ids(), true ),
			'Purchasing must stay off until pricecloak_block_purchasing is yes.'
		);
	}

	public function test_block_purchasing_yes_turns_it_on(): void {
		update_option( Options::ENABLED, 'yes' );
		update_option( Options::BLOCK_PURCHASING, 'yes' );
		$plugin = new Plugin( Modules::all() );
		$plugin->boot();

		$this->assertContains( 'purchasing', $plugin->registered_module_ids() );
	}

	public function test_the_master_switch_still_gates_it(): void {
		update_option( Options::BLOCK_PURCHASING, 'yes' );
		$plugin = new Plugin( Modules::all() );
		$plugin->boot();

		$this->assertSame( [], $plugin->registered_module_ids() );
	}

	public function test_there_is_no_separate_module_checkbox_for_purchasing(): void {
		$ids = array_column( ( new \PriceCloak\Settings( [ 'price_html', 'purchasing' ] ) )->fields(), 'id' );

		$this->assertContains( Options::BLOCK_PURCHASING, $ids );
		$this->assertFalse(
			in_array( Options::MODULE_PREFIX . 'purchasing', $ids, true ),
			'One behaviour, one checkbox.'
		);
	}

	public function test_uninstall_still_covers_the_purchasing_toggle(): void {
		$names = Options::all_names( [ 'price_html', 'purchasing' ] );

		$this->assertContains( Options::BLOCK_PURCHASING, $names );
		$this->assertSame( array_values( array_unique( $names ) ), $names, 'No option name listed twice.' );
	}

	public function test_register_hooks_every_surface(): void {
		$this->guest()->register();
		$hooks = array_keys( $GLOBALS['pricecloak_test']['filters'] );

		foreach (
			[
				'woocommerce_add_to_cart_validation',
				'woocommerce_store_api_validate_add_to_cart',
				'rest_request_before_callbacks',
				'woocommerce_before_checkout_process',
				'woocommerce_store_api_checkout_update_customer_from_request',
				'template_redirect',
				'woocommerce_loop_add_to_cart_link',
				'woocommerce_product_add_to_cart_text',
				'woocommerce_product_add_to_cart_url',
				'render_block',
				'woocommerce_single_product_summary',
				'woocommerce_single_variation',
			] as $hook
		) {
			$this->assertContains( $hook, $hooks, "$hook must be registered." );
		}
	}

	public function test_unregister_removes_exactly_what_register_added(): void {
		$module = $this->guest();
		$module->register();
		$module->unregister();

		// WooCommerce's own template callbacks are put back, so those two hooks
		// remain -- with core's function, not this module's.
		$left = $GLOBALS['pricecloak_test']['filters'];
		$this->assertSame(
			[ 'woocommerce_single_product_summary', 'woocommerce_single_variation' ],
			array_keys( $left )
		);
		$this->assertSame( 'woocommerce_template_single_add_to_cart', $left['woocommerce_single_product_summary'][0][0] );
		$this->assertSame( 'woocommerce_single_variation_add_to_cart_button', $left['woocommerce_single_variation'][0][0] );
	}

	// -- 4. carts built before logout ------------------------------------

	public function test_no_registered_hook_can_empty_or_expire_a_cart(): void {
		$this->guest()->register();
		$hooks = array_keys( $GLOBALS['pricecloak_test']['filters'] );

		foreach (
			[
				'wp_logout',
				'wp_login',
				'woocommerce_cart_emptied',
				'woocommerce_before_cart',
				'woocommerce_cart_loaded_from_session',
				'woocommerce_load_cart_from_session',
				'woocommerce_persistent_cart_enabled',
				'woocommerce_cart_session_initialize',
				'init',
			] as $forbidden
		) {
			$this->assertFalse(
				in_array( $forbidden, $hooks, true ),
				"$forbidden touches cart persistence and must not be hooked."
			);
		}
	}

	public function test_the_module_source_calls_nothing_that_empties_a_cart(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Purchasing.php' );

		foreach ( [ 'empty_cart', 'set_cart_cookies', 'destroy_session', 'forget_session', 'wc_clear_cart' ] as $call ) {
			$this->assertStringNotContainsString( $call . '(', $source, "$call() would discard a shopper's cart." );
		}
	}

	// -- 1. add-to-cart refusal -------------------------------------------

	public function test_guest_add_to_cart_is_refused_with_a_notice(): void {
		$this->guest()->register();

		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, 10, 1 );

		$this->assertFalse( $passed );
		$this->assertCount( 1, $GLOBALS['pricecloak_test']['notices'] );
		$this->assertSame( 'error', $GLOBALS['pricecloak_test']['notices'][0]['type'] );
		$this->assertStringContainsString( 'log in', strtolower( $GLOBALS['pricecloak_test']['notices'][0]['message'] ) );
	}

	public function test_logged_in_add_to_cart_passes_untouched(): void {
		$this->logged_in()->register();

		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, 10, 1 ) );
		$this->assertSame( [], $GLOBALS['pricecloak_test']['notices'] );
	}

	public function test_an_earlier_refusal_is_not_overturned(): void {
		$this->logged_in()->register();

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', false, 10, 1 ) );
	}

	public function test_store_api_validation_throws_for_a_guest(): void {
		$thrown = null;
		try {
			$this->guest()->refuse_store_api_add_to_cart();
		} catch ( \Throwable $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( \Throwable::class, $thrown );
		$this->assertStringContainsString( 'log in', strtolower( (string) $thrown->getMessage() ) );
	}

	public function test_store_api_validation_is_silent_for_a_member(): void {
		$this->logged_in()->refuse_store_api_add_to_cart();

		$this->assertTrue( true, 'No exception for a logged-in shopper.' );
	}

	public function test_guest_store_api_cart_writes_are_refused(): void {
		$module = $this->guest();

		foreach (
			[
				[ '/wc/store/v1/cart/add-item', 'POST' ],
				[ '/wc/store/v1/cart/update-item', 'POST' ],
				[ '/wc/store/v1/cart/remove-item', 'POST' ],
				[ '/wc/store/v1/batch', 'POST' ],
				[ '/wc/store/v1/checkout', 'POST' ],
				[ '/wc/store/v1/checkout/20', 'POST' ],
				[ '/wc/store/v1/cart', 'PUT' ],
				// The unversioned alias WooCommerce registers every v1 route under.
				[ '/wc/store/checkout', 'POST' ],
				[ '/wc/store/cart/update-item', 'POST' ],
				[ '/wc/store/cart/apply-coupon', 'POST' ],
				[ '/wc/store/batch', 'POST' ],
				[ '/wc/store/v2/checkout', 'POST' ],
				[ 'wc/store/v1/checkout', 'post' ],
			] as [ $route, $method ]
		) {
			$result = $module->refuse_store_api_cart_write( null, null, new WP_REST_Request( $route, $method ) );
			$this->assertInstanceOf( WP_Error::class, $result, "$method $route must be refused." );
			$this->assertSame( [ 'status' => 403 ], $result->get_error_data() );
			$this->assertSame( 'pricecloak_login_required', $result->get_error_code() );
		}
	}

	public function test_guest_store_api_cart_reads_still_work(): void {
		$module = $this->guest();

		foreach ( [ '/wc/store/v1/cart', '/wc/store/v1/cart/items', '/wc/store/cart', '/wc/store/v1/checkout', '/wc/store/v1/products' ] as $route ) {
			$this->assertNull(
				$module->refuse_store_api_cart_write( null, null, new WP_REST_Request( $route, 'GET' ) ),
				"GET $route must still be served: a pre-logout cart has to stay visible."
			);
		}
	}

	public function test_other_routes_and_members_are_not_refused(): void {
		$module = $this->guest();
		$this->assertNull( $module->refuse_store_api_cart_write( null, null, new WP_REST_Request( '/wp/v2/posts', 'POST' ) ) );
		$this->assertNull( $module->refuse_store_api_cart_write( null, null, new WP_REST_Request( '/wc/store/v1/cartography', 'POST' ) ) );
		$this->assertNull( $module->refuse_store_api_cart_write( null, null, new WP_REST_Request( '/wc/storefront/cart', 'POST' ) ) );
		$this->assertNull( $module->refuse_store_api_cart_write( null, null, new WP_REST_Request( '/x/wc/store/v1/cart', 'POST' ) ) );

		$member = $this->logged_in();
		$this->assertNull( $member->refuse_store_api_cart_write( null, null, new WP_REST_Request( '/wc/store/v1/cart/add-item', 'POST' ) ) );
	}

	// -- 2. cart and checkout redirects ------------------------------------

	public function test_guest_on_the_cart_page_is_sent_to_the_login_page(): void {
		$GLOBALS['pricecloak_test']['is_cart'] = true;
		$_SERVER['HTTP_HOST']                  = 'example.test';
		$_SERVER['REQUEST_URI']                = '/';

		$target = $this->guest()->redirect_target();
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

		$this->assertSame( 'http://example.test/my-account/?redirect_to=' . rawurlencode( 'http://example.test/' ), $target );
	}

	public function test_guest_on_the_checkout_page_is_sent_to_the_login_page(): void {
		$GLOBALS['pricecloak_test']['is_checkout'] = true;
		$_SERVER['HTTP_HOST']                      = 'example.test';
		$_SERVER['REQUEST_URI']                    = '/checkout/';

		$target = $this->guest()->redirect_target();
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

		$this->assertStringContainsString( '/my-account/', (string) $target );
		$this->assertStringContainsString( rawurlencode( 'http://example.test/checkout/' ), (string) $target );
	}

	public function test_the_order_received_page_is_left_alone(): void {
		$GLOBALS['pricecloak_test']['is_checkout']       = true;
		$GLOBALS['pricecloak_test']['is_order_received'] = true;

		$this->assertNull( $this->guest()->redirect_target() );
	}

	public function test_the_order_pay_page_is_left_alone(): void {
		// /checkout/order-pay/{id}/?key=... is is_checkout() too, but paying an
		// order that already exists is not catalogue purchasing.
		$GLOBALS['pricecloak_test']['is_checkout']     = true;
		$GLOBALS['pricecloak_test']['is_checkout_pay'] = true;

		$this->assertNull( $this->guest()->redirect_target() );
	}

	public function test_members_and_other_pages_are_left_alone(): void {
		$this->assertNull( $this->guest()->redirect_target(), 'A shop page is not a redirect.' );

		$GLOBALS['pricecloak_test']['is_cart'] = true;
		$this->assertNull( $this->logged_in()->redirect_target() );
	}

	public function test_the_redirect_is_issued_and_the_request_ends(): void {
		$GLOBALS['pricecloak_test']['is_cart'] = true;
		$module                                = new PurchasingSpy();

		$module->redirect_cart_and_checkout();

		$this->assertCount( 1, $GLOBALS['pricecloak_test']['redirects'] );
		$this->assertStringContainsString( '/my-account/', $GLOBALS['pricecloak_test']['redirects'][0]['location'] );
		$this->assertSame( 1, $module->halted );
	}

	public function test_no_redirect_means_no_halt(): void {
		$module = new PurchasingSpy();
		$module->redirect_cart_and_checkout();

		$this->assertSame( [], $GLOBALS['pricecloak_test']['redirects'] );
		$this->assertSame( 0, $module->halted );
	}

	// -- 3. buttons --------------------------------------------------------

	public function test_loop_button_becomes_a_login_link_for_guests(): void {
		$this->guest()->register();

		$link = apply_filters( 'woocommerce_loop_add_to_cart_link', '<a href="?add-to-cart=10" class="button add_to_cart_button">Add to cart</a>' );

		$this->assertStringContainsString( 'Log in to buy', $link );
		$this->assertStringNotContainsString( 'add_to_cart_button', $link );
		$this->assertStringNotContainsString( 'add-to-cart=', $link );
		$this->assertStringContainsString( 'my-account', $link );
	}

	public function test_loop_button_is_untouched_for_members(): void {
		$original = '<a href="?add-to-cart=10" class="button add_to_cart_button">Add to cart</a>';
		$this->logged_in()->register();

		$this->assertSame( $original, apply_filters( 'woocommerce_loop_add_to_cart_link', $original ) );
	}

	public function test_the_login_button_is_a_link_even_when_price_text_is_not(): void {
		update_option( Options::LINK_TO_LOGIN, 'no' );

		$this->assertStringContainsString( '<a ', $this->guest()->login_button() );
	}

	public function test_classic_text_and_url_filters_are_replaced_for_guests(): void {
		$this->guest()->register();

		$this->assertSame( 'Log in to buy', apply_filters( 'woocommerce_product_add_to_cart_text', 'Add to cart' ) );
		$this->assertStringContainsString( 'my-account', apply_filters( 'woocommerce_product_add_to_cart_url', '?add-to-cart=10' ) );
	}

	public function test_classic_text_and_url_filters_are_untouched_for_members(): void {
		$this->logged_in()->register();

		$this->assertSame( 'Add to cart', apply_filters( 'woocommerce_product_add_to_cart_text', 'Add to cart' ) );
		$this->assertSame( '?add-to-cart=10', apply_filters( 'woocommerce_product_add_to_cart_url', '?add-to-cart=10' ) );
	}

	public function test_add_to_cart_blocks_are_replaced_for_guests(): void {
		$module = $this->guest();

		foreach ( Purchasing::BLOCKED_BLOCKS as $name ) {
			$rendered = $module->replace_block( '<button class="wc-block-components-product-button__button">Add to cart</button>', [ 'blockName' => $name ] );
			$this->assertStringContainsString( 'Log in to buy', (string) $rendered, "$name must become a login link." );
			$this->assertStringNotContainsString( 'product-button__button', (string) $rendered );
		}
	}

	public function test_other_blocks_and_members_keep_their_block_output(): void {
		$original = '<p>Anything</p>';

		$this->assertSame( $original, $this->guest()->replace_block( $original, [ 'blockName' => 'core/paragraph' ] ) );
		$this->assertSame( $original, $this->guest()->replace_block( $original, null ) );
		$this->assertSame(
			$original,
			$this->logged_in()->replace_block( $original, [ 'blockName' => 'woocommerce/product-button' ] )
		);
	}

	public function test_the_single_product_form_is_replaced_for_guests(): void {
		ob_start();
		$this->guest()->render_login_button();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Log in to buy', $html );
		$this->assertStringContainsString( 'pricecloak-login-to-buy', $html );
	}

	public function test_purchasability_is_never_filtered_so_variation_forms_keep_working(): void {
		$this->guest()->register();

		$this->assertFalse(
			in_array( 'woocommerce_is_purchasable', array_keys( $GLOBALS['pricecloak_test']['filters'] ), true ),
			'Filtering is_purchasable would break the variation selector module 2 protects.'
		);
	}
	// -- 5. checkout processing --------------------------------------------

	public function test_classic_checkout_processing_throws_for_a_guest(): void {
		$thrown = null;
		try {
			$this->guest()->refuse_classic_checkout();
		} catch ( \Exception $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( \Exception::class, $thrown, 'process_checkout() catches Exception and turns it into an error notice.' );
		$this->assertStringContainsString( 'log in', strtolower( (string) $thrown->getMessage() ) );
		$this->assertStringNotContainsString( '<', (string) $thrown->getMessage() );
	}

	public function test_classic_checkout_processing_is_silent_for_a_member(): void {
		$this->logged_in()->refuse_classic_checkout();

		$this->assertTrue( true, 'No exception for a logged-in shopper.' );
	}

	public function test_classic_checkout_refusal_runs_on_the_before_process_action(): void {
		$this->guest()->register();

		$thrown = null;
		try {
			do_action( 'woocommerce_before_checkout_process' );
		} catch ( \Exception $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( \Exception::class, $thrown );
	}

	public function test_store_api_checkout_throws_for_a_guest(): void {
		$thrown = null;
		try {
			$this->guest()->refuse_store_api_checkout( new \stdClass(), new WP_REST_Request( '/wc/store/checkout', 'POST' ) );
		} catch ( \Throwable $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( \Throwable::class, $thrown );
		$this->assertStringContainsString( 'log in', strtolower( (string) $thrown->getMessage() ) );
	}

	public function test_store_api_checkout_is_silent_for_a_member(): void {
		$this->logged_in()->refuse_store_api_checkout( new \stdClass(), new WP_REST_Request( '/wc/store/checkout', 'POST' ) );

		$this->assertTrue( true, 'No exception for a logged-in shopper.' );
	}
}
