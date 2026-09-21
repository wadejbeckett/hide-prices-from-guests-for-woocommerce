<?php
/**
 * Shared machinery for the two REST modules: match a route, walk a response
 * body, rewrite the keys that carry a price. Modules 4 and 5 differ only in
 * which routes they claim and which keys they rewrite.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;
use stdClass;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites price-bearing fields in REST responses for logged-out visitors.
 *
 * `rest_request_after_callbacks` is used rather than the Store API's
 * ExtendSchema mechanism because an extension can only *add* data: it writes
 * into an `extensions` envelope and cannot touch, let alone remove, a core
 * field such as `prices.price`. Removing numeric values is the whole point
 * here, so the response has to be filtered. Of the two response filters,
 * `rest_request_after_callbacks` runs immediately after the route callback,
 * before `rest_post_dispatch` and before the response is served from any
 * later caching layer, and it receives the request object directly -- so the
 * route test needs no globals. It also fires for both Store API and legacy
 * REST, since both are ordinary WP REST namespaces.
 *
 * `woocommerce_hydration_request_after_callbacks` is hooked with the same
 * callback because WooCommerce's Hydration service does not dispatch through
 * `WP_REST_Server` at all: `Hydration::get_response_from_controller()` builds
 * a `WP_REST_Request`, calls the route's callback directly and runs *this*
 * filter in place of `rest_request_after_callbacks`, with the same
 * `($response, $handler, $request)` arguments. Every hydrated Store API
 * payload in WooCommerce 11.1 goes through it: the Cart, Checkout and All
 * Products blocks' preloaded `/wc/store/v1/cart` and `/checkout`
 * (`AssetDataRegistry::hydrate_api_request()` / `hydrate_data_from_api_request()`),
 * the Mini Cart's `woocommerce.cart` state (`BlocksSharedState::load_cart_state()`),
 * and the `woocommerce/products` Interactivity state that `ProductsStore`
 * loads for `SingleProductTemplate`, `ProductTemplate`, `SingleProduct`,
 * `ProductQuery` and `AddToCartWithOptions` (`load_product()`,
 * `load_purchasable_child_products()`, `load_variations()`), all of which call
 * `Hydration::get_rest_api_response_data()`. A path the service cannot match
 * to a Store API controller falls back to `rest_preload_api_request()`, a
 * full `WP_REST_Server` dispatch, which fires `rest_request_after_callbacks`
 * instead -- so between the two hooks no hydration path bypasses the filter.
 * Note that the hydrated request keeps its query string in the route
 * (`new WP_REST_Request( 'GET', '/wc/store/v1/products?parent[]=11&type=variation' )`),
 * which is why matches_route() strips one before matching.
 *
 * The filter is deliberately conservative: anything that is not a
 * WP_REST_Response carrying array data (a WP_Error, a raw value, a response
 * to a route this module does not claim) is returned untouched.
 */
abstract class RestResponseFilter implements Module {
	/**
	 * The WordPress REST hook both REST modules attach to.
	 */
	public const HOOK = 'rest_request_after_callbacks';

	/**
	 * WooCommerce's equivalent for responses it hydrates into a page.
	 */
	public const HYDRATION_HOOK = 'woocommerce_hydration_request_after_callbacks';

	/**
	 * An anchored, case-insensitive regex matched against the route (query
	 * string removed, leading slash guaranteed), e.g.
	 * `#^/wc/v3/products(?:/|$)#i`. Anchor it at the start and end the claimed
	 * segment with `(?:/|$)`, so `/products/11` and `/products/collection-data`
	 * match while `/productsmith` does not.
	 */
	abstract protected function route_pattern(): string;

	/**
	 * Key to rewriter map applied at every depth of the response body.
	 * Each rewriter receives the current value and returns its replacement;
	 * the replaced value is not descended into.
	 *
	 * @return array<string, callable>
	 */
	abstract protected function rules(): array;

	/**
	 * Attach the response filter.
	 */
	public function register(): void {
		add_filter( self::HOOK, [ $this, 'filter_response' ], PHP_INT_MAX, 3 );
		add_filter( self::HYDRATION_HOOK, [ $this, 'filter_response' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Remove exactly what register() added.
	 */
	public function unregister(): void {
		remove_filter( self::HOOK, [ $this, 'filter_response' ], PHP_INT_MAX );
		remove_filter( self::HYDRATION_HOOK, [ $this, 'filter_response' ], PHP_INT_MAX );
	}

	/**
	 * Filter callback: blank prices in a guest's response to a claimed route.
	 *
	 * @param mixed $response Response, WP_Error, or anything a callback returned.
	 * @param mixed $handler  The matched route handler. Unused.
	 * @param mixed $request  The request being served.
	 * @return mixed
	 */
	public function filter_response( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		if ( ! Context::is_guest() ) {
			return $response;
		}
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( ! $this->matches_route( (string) $request->get_route() ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$response->set_data( self::rewrite( $data, $this->rules() ) );

		return $response;
	}

	/**
	 * Whether a REST route belongs to this module.
	 *
	 * @param string $route Route as WP_REST_Request reports it, e.g. `/wc/v3/products/7`
	 *                      or, from a hydrated request, `/wc/store/v1/products?include[]=7`.
	 */
	public function matches_route( string $route ): bool {
		return (bool) preg_match( $this->route_pattern(), self::normalise_route( $route ) );
	}

	/**
	 * A route with its query string removed and a leading slash guaranteed.
	 *
	 * @param string $route Route as reported.
	 */
	public static function normalise_route( string $route ): string {
		$route = (string) strtok( $route, '?' );
		return '/' . ltrim( $route, '/' );
	}

	/**
	 * Recursively apply the rules to every array and stdClass in a response.
	 *
	 * Store API responses hold their `prices` as a stdClass -- ProductSchema
	 * casts it so it serialises as a JSON object rather than an array -- so
	 * objects are descended into and rebuilt as objects, preserving the shape
	 * the front end expects. Any other object type is left strictly alone.
	 *
	 * @param mixed                   $node  Current node.
	 * @param array<string, callable> $rules Key to rewriter map.
	 * @return mixed
	 */
	protected static function rewrite( mixed $node, array $rules ): mixed {
		if ( $node instanceof stdClass ) {
			return (object) self::rewrite( get_object_vars( $node ), $rules );
		}
		if ( ! is_array( $node ) ) {
			return $node;
		}

		$out = [];
		foreach ( $node as $key => $value ) {
			$out[ $key ] = isset( $rules[ $key ] ) ? $rules[ $key ]( $value ) : self::rewrite( $value, $rules );
		}

		return $out;
	}
}
