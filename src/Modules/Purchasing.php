<?php
/**
 * Module 6: purchasing. Off by default. When on, a logged-out visitor cannot
 * add anything to a cart, cannot place an order on any checkout path, cannot
 * reach the cart or checkout pages, and is offered a "Log in to buy" link
 * wherever WooCommerce would print an add-to-cart button. A cart built
 * before logging out is left exactly as it was: this module refuses new
 * purchases, it never empties anything. Paying an order that already exists
 * (the order-pay endpoint, reached with the order key) is not purchasing
 * from the catalogue and is left to WooCommerce.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;
use PriceCloak\Replacement;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses guest purchasing and replaces add-to-cart buttons with a login link.
 *
 * Where the refusal happens, and why there.
 *
 * `woocommerce_add_to_cart_validation` is the one filter every add-to-cart
 * path in WooCommerce 11 runs through: the form POST
 * (`WC_Form_Handler::add_to_cart_action()`, once per product type),
 * `?wc-ajax=add_to_cart` (`WC_AJAX::add_to_cart()`), "Order again" in
 * `WC_Cart_Session::populate_cart_from_order()`, and the Store API's
 * `CartController::validate_add_to_cart()`. Returning false there, with a
 * `wc_add_notice()` error alongside, refuses all of them: the classic paths
 * show the notice, and the Store API converts pending error notices into a
 * `RouteException` (`NoticeHandler::convert_notices_to_exceptions()`), so the
 * REST response is a 4xx carrying the same sentence.
 *
 * The Store API is also refused a step earlier, at `rest_request_before_callbacks`,
 * for any writing method on a `cart`, `checkout` or `batch` route under
 * `wc/store/v1` *or* the unversioned `wc/store` namespace WooCommerce registers
 * every v1 route under as well (`RoutesController::register_all_routes()`).
 * That is deliberate belt and braces covering the routes the add-to-cart
 * filter does not see -- `cart/update-item`, `cart/apply-coupon`, the checkout
 * POST -- with one rule and no dependency on WooCommerce's exception classes.
 * Read methods are left alone on purpose: a guest who filled a cart before
 * logging out must still be able to *see* it.
 *
 * Checkout processing is refused at the process stage as well, because a
 * cart filled before the switch was flipped (or before logging out) is
 * otherwise still orderable. Classic checkout: `WC_Form_Handler::checkout_action()`
 * (the form POST, on `wp_loaded` at priority 20) and `WC_AJAX::checkout()`
 * (`?wc-ajax=checkout`, on `template_redirect` at priority 0) both run
 * before this module's page redirect and both call
 * `WC_Checkout::process_checkout()`, which fires `woocommerce_before_checkout_process`
 * inside its `try` and catches `Exception` into an error notice
 * (`class-wc-checkout.php`, WooCommerce 11.1). Throwing there is therefore
 * the one refusal both paths share: the form re-renders with the notice and
 * `wc-ajax=checkout` answers `{"result":"failure","messages":...}`, and no
 * order is created. Store API checkout: the route gate above already refuses
 * the POST, and `woocommerce_store_api_checkout_update_customer_from_request`
 * -- the first action `Checkout::process_order()` fires, before an order
 * exists, and one `CheckoutOrder` (`/checkout/{id}`) fires too -- throws a
 * `RouteException` for a guest as defence in depth, so any future alias or
 * third-party route that builds an order from the guest cart through the
 * same code is refused as well; `AbstractRoute::get_response()` turns it
 * into the 403 error response.
 *
 * `woocommerce_is_purchasable` is deliberately NOT filtered. Turning it false
 * would make WooCommerce drop the variation form entirely, hide stock status
 * and strip the variation payload, so the variation selector -- which module 2
 * works hard to keep functional -- would stop resolving. Purchasability stays
 * true and the buttons are replaced instead.
 *
 * Not final, unlike the other modules: halt() below is a seam a test subclass
 * replaces so the cart redirect can be asserted without exit() ending the run.
 */
class Purchasing implements Module {
	public const ID = 'purchasing';

	/**
	 * Store API routes whose writing methods a guest may not use: the cart,
	 * checkout and batch routes under `wc/store`, with or without a version
	 * segment.
	 */
	public const BLOCKED_STORE_ROUTE_PATTERN = '#^/wc/store(?:/v\d+)?/(?:cart|checkout|batch)(?:/|$)#i';

	/**
	 * The error code every Store API refusal carries, so a client (and the
	 * probe) can tell this plugin's refusal from WooCommerce's own.
	 */
	public const ERROR_CODE = 'pricecloak_login_required';

	/**
	 * Methods that only read. Everything else on a blocked route is refused.
	 */
	public const READ_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

	/**
	 * Blocks whose rendered output is an add-to-cart control.
	 */
	public const BLOCKED_BLOCKS = [
		'woocommerce/add-to-cart-form',
		'woocommerce/product-button',
	];

	/**
	 * Stable identifier. Its settings toggle is `pricecloak_block_purchasing`.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Attach every hook. WooCommerce's own template hooks are already in place
	 * by the time this runs -- `wc-template-hooks.php` is included from the
	 * `WooCommerce` constructor, which fires while the plugin file loads, well
	 * before the `plugins_loaded` action this plugin boots on -- so the
	 * remove_action() calls below really do detach core's callbacks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'refuse_add_to_cart' ], PHP_INT_MAX );
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'refuse_store_api_add_to_cart' ], PHP_INT_MAX );
		add_filter( 'rest_request_before_callbacks', [ $this, 'refuse_store_api_cart_write' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_before_checkout_process', [ $this, 'refuse_classic_checkout' ], PHP_INT_MIN );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'refuse_store_api_checkout' ], PHP_INT_MIN, 2 );

		add_action( 'template_redirect', [ $this, 'redirect_cart_and_checkout' ] );

		add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'replace_loop_button' ], PHP_INT_MAX );
		add_filter( 'woocommerce_product_add_to_cart_text', [ $this, 'replace_button_text' ], PHP_INT_MAX );
		add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'replace_button_text' ], PHP_INT_MAX );
		add_filter( 'woocommerce_product_add_to_cart_url', [ $this, 'replace_button_url' ], PHP_INT_MAX );
		add_filter( 'render_block', [ $this, 'replace_block' ], PHP_INT_MAX, 2 );

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_single_add_to_cart' ], 30 );
		remove_action( 'woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20 );
		add_action( 'woocommerce_single_variation', [ $this, 'render_single_variation_button' ], 20 );
	}

	/**
	 * Remove exactly what register() added, and put WooCommerce's own template
	 * callbacks back where they were.
	 */
	public function unregister(): void {
		remove_filter( 'woocommerce_add_to_cart_validation', [ $this, 'refuse_add_to_cart' ], PHP_INT_MAX );
		remove_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'refuse_store_api_add_to_cart' ], PHP_INT_MAX );
		remove_filter( 'rest_request_before_callbacks', [ $this, 'refuse_store_api_cart_write' ], PHP_INT_MAX );
		remove_action( 'woocommerce_before_checkout_process', [ $this, 'refuse_classic_checkout' ], PHP_INT_MIN );
		remove_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'refuse_store_api_checkout' ], PHP_INT_MIN );

		remove_action( 'template_redirect', [ $this, 'redirect_cart_and_checkout' ] );

		remove_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'replace_loop_button' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_product_add_to_cart_text', [ $this, 'replace_button_text' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'replace_button_text' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_product_add_to_cart_url', [ $this, 'replace_button_url' ], PHP_INT_MAX );
		remove_filter( 'render_block', [ $this, 'replace_block' ], PHP_INT_MAX );

		remove_action( 'woocommerce_single_product_summary', [ $this, 'render_single_add_to_cart' ], 30 );
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		remove_action( 'woocommerce_single_variation', [ $this, 'render_single_variation_button' ], 20 );
		add_action( 'woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20 );
	}

	// -- refusal ----------------------------------------------------------

	/**
	 * The sentence a refused shopper is shown, wherever the refusal surfaces.
	 */
	public function message(): string {
		return __( 'Please log in to your account to add products to your cart.', 'pricecloak-for-woocommerce' );
	}

	/**
	 * Refuse the add for guests, with a notice the classic paths render and the
	 * Store API turns into its error response.
	 *
	 * @param mixed $passed Whether validation has passed so far.
	 * @return mixed
	 */
	public function refuse_add_to_cart( mixed $passed ): mixed {
		if ( ! Context::is_guest() ) {
			return $passed;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $this->message(), 'error' );
		}
		return false;
	}

	/**
	 * Store API add-to-cart validation. Anything thrown here stops the add;
	 * WooCommerce's own RouteException is used when it is available so the
	 * response carries a proper status code, and a plain exception otherwise.
	 *
	 * @throws \Exception Always, for a guest: WooCommerce's RouteException
	 *                    where that class exists, a plain exception otherwise.
	 */
	public function refuse_store_api_add_to_cart(): void {
		if ( ! Context::is_guest() ) {
			return;
		}

		throw $this->store_api_exception();
	}

	/**
	 * Store API checkout, on the first action the checkout routes fire: a
	 * guest's order is refused before it exists. Defence in depth behind the
	 * route gate below.
	 *
	 * @param mixed $customer The customer being updated. Unused.
	 * @param mixed $request  The checkout request. Unused.
	 *
	 * @throws \Exception Always, for a guest: WooCommerce's RouteException
	 *                    where that class exists, a plain exception otherwise.
	 */
	public function refuse_store_api_checkout( mixed $customer = null, mixed $request = null ): void {
		if ( ! Context::is_guest() ) {
			return;
		}

		throw $this->store_api_exception();
	}

	/**
	 * Classic checkout, on `woocommerce_before_checkout_process`: the one
	 * point the form POST and `?wc-ajax=checkout` share, inside the `try`
	 * that `WC_Checkout::process_checkout()` turns into an error notice.
	 *
	 * @throws \Exception Always, for a guest; the message is the notice shown.
	 */
	public function refuse_classic_checkout(): void {
		if ( ! Context::is_guest() ) {
			return;
		}

		throw new \Exception( esc_html( $this->message() ) );
	}

	/**
	 * The exception a Store API refusal throws: WooCommerce's RouteException
	 * when it is available, so the response carries a proper status code and
	 * this plugin's error code, and a plain exception otherwise.
	 */
	private function store_api_exception(): \Exception {
		$route_exception = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		return class_exists( $route_exception )
			? new $route_exception( self::ERROR_CODE, esc_html( $this->message() ), 403 )
			: new \Exception( esc_html( $this->message() ) );
	}

	/**
	 * Refuse any writing request a guest makes to a Store API cart, checkout or
	 * batch route. Returning a WP_Error from this filter short-circuits the
	 * dispatch, so the route callback never runs.
	 *
	 * @param mixed $response Response so far, or a WP_Error.
	 * @param mixed $handler  Matched route handler. Unused.
	 * @param mixed $request  The request being dispatched.
	 * @return mixed
	 */
	public function refuse_store_api_cart_write( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		if ( ! Context::is_guest() || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( ! $this->is_blocked_store_write( (string) $request->get_route(), (string) $request->get_method() ) ) {
			return $response;
		}

		return new WP_Error( self::ERROR_CODE, $this->message(), [ 'status' => 403 ] );
	}

	/**
	 * Whether a route and method pair is a guest write to a cart, checkout
	 * or batch route, versioned or not.
	 *
	 * @param string $route  REST route, e.g. `/wc/store/v1/cart/add-item` or `/wc/store/checkout`.
	 * @param string $method HTTP method.
	 */
	public function is_blocked_store_write( string $route, string $method ): bool {
		if ( in_array( strtoupper( $method ), self::READ_METHODS, true ) ) {
			return false;
		}

		return (bool) preg_match( self::BLOCKED_STORE_ROUTE_PATTERN, RestResponseFilter::normalise_route( $route ) );
	}

	// -- cart and checkout pages ------------------------------------------

	/**
	 * Send guests from the cart and checkout pages to the login page, with the
	 * page they wanted as the post-login destination.
	 *
	 * Two checkout endpoints are excluded, because `is_checkout()` is true on
	 * both. The order-received endpoint is where a guest lands after paying.
	 * The order-pay endpoint (`/checkout/order-pay/{id}/?key=...`) is where a
	 * guest pays an order that already exists -- an invoice created in the
	 * admin, or a pending order WooCommerce sent a "pay now" link for. Paying
	 * an existing order is not catalogue purchasing, and both endpoints
	 * authorise themselves with the order key in the URL rather than with a
	 * session (WooCommerce 11.1 `WC_Shortcode_Checkout::order_pay()` checks
	 * the key and `pay_for_order`, and shows its own login form when a guest
	 * cannot pay), so they are left to WooCommerce. Add-to-cart and checkout
	 * processing stay refused regardless.
	 */
	public function redirect_cart_and_checkout(): void {
		$target = $this->redirect_target();
		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target );
		$this->halt();
	}

	/**
	 * Where this request should be sent, or null to leave it alone.
	 *
	 * Split out from redirect_cart_and_checkout() so the decision can be
	 * tested without a redirect and without ending the process.
	 */
	public function redirect_target(): ?string {
		if ( ! Context::is_guest() ) {
			return null;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return null;
		}
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return null;
		}
		$on_cart     = function_exists( 'is_cart' ) && is_cart();
		$on_checkout = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! $on_cart && ! $on_checkout ) {
			return null;
		}

		return Replacement::login_url();
	}

	/**
	 * End the request after a redirect. A seam: the test double overrides it
	 * so a unit test can assert the redirect without the process exiting.
	 *
	 * @codeCoverageIgnore
	 */
	protected function halt(): void {
		exit;
	}

	// -- buttons ----------------------------------------------------------

	/**
	 * The "Log in to buy" markup, styled as a button so themes pick it up.
	 */
	public function login_button(): string {
		return Replacement::markup_for( $this->button_text(), 'button pricecloak-login-to-buy', true );
	}

	/**
	 * Button wording.
	 */
	public function button_text(): string {
		return __( 'Log in to buy', 'pricecloak-for-woocommerce' );
	}

	/**
	 * Catalogue loop: the whole add-to-cart anchor becomes a login link.
	 *
	 * @param mixed $link The anchor WooCommerce built.
	 * @return mixed
	 */
	public function replace_loop_button( mixed $link ): mixed {
		return Context::is_guest() ? $this->login_button() : $link;
	}

	/**
	 * Fallback for themes and blocks that build their own button from the
	 * product's add-to-cart text and URL rather than from the loop filter.
	 *
	 * @param mixed $text Button wording.
	 * @return mixed
	 */
	public function replace_button_text( mixed $text ): mixed {
		return Context::is_guest() ? $this->button_text() : $text;
	}

	/**
	 * The same fallback's href.
	 *
	 * @param mixed $url Add-to-cart URL.
	 * @return mixed
	 */
	public function replace_button_url( mixed $url ): mixed {
		return Context::is_guest() ? Replacement::login_url() : $url;
	}

	/**
	 * Block editor output: the Add to Cart Form block and the Products block's
	 * button become the login link.
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block, with its `blockName`.
	 * @return mixed
	 */
	public function replace_block( mixed $content, mixed $block = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $block ) ) {
			return $content;
		}
		$name = $block['blockName'] ?? '';
		if ( ! in_array( $name, self::BLOCKED_BLOCKS, true ) ) {
			return $content;
		}

		return '<div class="wp-block-button pricecloak-login-to-buy-block">' . $this->login_button() . '</div>';
	}

	/**
	 * Single product: no add-to-cart form for guests.
	 *
	 * A variable product keeps WooCommerce's own form, because that form is the
	 * variation selector module 2 exists to keep working; its button has been
	 * detached separately and render_login_button() takes its place inside the
	 * form. Every other product type loses the form altogether.
	 */
	public function render_single_add_to_cart(): void {
		if ( ! Context::is_guest() ) {
			woocommerce_template_single_add_to_cart();
			return;
		}

		global $product;
		if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
			woocommerce_template_single_add_to_cart();
			return;
		}

		$this->render_login_button();
	}

	/**
	 * Inside the variation form: the login button in place of the variation's
	 * own add-to-cart button. Logged-in shoppers get WooCommerce's button back,
	 * because register() detached it for everyone.
	 */
	public function render_single_variation_button(): void {
		if ( ! Context::is_guest() ) {
			woocommerce_single_variation_add_to_cart_button();
			return;
		}

		$this->render_login_button();
	}

	/**
	 * Print the login button.
	 */
	public function render_login_button(): void {
		echo wp_kses_post( $this->login_button() );
	}
}
