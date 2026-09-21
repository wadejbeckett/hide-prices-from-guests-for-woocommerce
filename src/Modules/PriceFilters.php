<?php
/**
 * Module 7: price filters. A guest who is shown no price can still recover
 * one by asking the catalogue price-based questions: "which products cost
 * between X and Y?" (`?min_price=&max_price=`, binary-searchable to the cent
 * in twenty requests), "sort by price" (`?orderby=price`, which orders the
 * catalogue by an amount the guest is not shown), and the Price Filter
 * widget and blocks, which print the catalogue's minimum and maximum
 * outright. The Store API products routes take the same questions as
 * request parameters. For guests every one of those channels is closed.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Modules;

use PriceCloak\Context;
use PriceCloak\Module;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Closes the price inference channels for logged-out visitors.
 *
 * Where each channel is closed, and why there.
 *
 * The range parameters reach a product query two ways in WooCommerce 11.
 * `WC_Query::price_filter_post_clauses()` reads `$_GET['min_price']` and
 * `$_GET['max_price']` directly and appends one `AND NOT (...)` clause to the
 * main query's WHERE; the Product Collection and Products (Beta) blocks read
 * the same names as public query vars with `get_query_var()` and build a
 * `_price` meta query. The `request` filter strips the two query vars before
 * the main query is built, which covers the blocks; the `posts_clauses`
 * filter at priority 11 -- one after WooCommerce's own -- removes exactly
 * the clause `price_filter_post_clauses()` appends, which covers the classic
 * loop. WooCommerce's `woocommerce_enable_post_clause_filtering` filter would
 * be simpler but is shared with attribute (layered-nav) filtering, so
 * returning false there would break other clauses; this leaves them alone.
 *
 * Price sorting: `WC_Query::get_catalog_ordering_args()` attaches a
 * `posts_clauses` callback for `orderby=price` *before* it runs the
 * `woocommerce_get_catalog_ordering_args` filter, so resetting the args there
 * is not enough on its own -- the callback is detached as well, and the args
 * are rebuilt for the catalogue's default ordering (falling back to
 * `menu_order` when that default is itself price). The price options are
 * also dropped from the sort dropdown, so a guest is not offered a sort that
 * does nothing.
 *
 * The legacy Price Filter widget is suppressed through `widget_display_callback`
 * (returning false skips the widget), and the price filter blocks -- the
 * legacy `woocommerce/price-filter` with its `filter-wrapper`, and the
 * `product-filter-price` / `product-filter-price-slider` pair -- render as
 * empty strings, with the range state the newer block registers emptied too,
 * since that state is printed into the page whether or not the block is.
 *
 * The Store API. `Products::get_collection_params()` declares `min_price`
 * and `max_price` (minor units) and `orderby` with `price` in its enum, and
 * `products/collection-data` takes the same range to count within;
 * `ProductQuery::add_price_filter_clauses()` appends its own
 * `AND wc_product_meta_lookup.max_price >= %f` / `min_price <= %f` clauses,
 * which are not the classic clause above, and none of the page filters see a
 * REST request. So the two range parameters are removed from a guest's
 * request at `rest_request_before_callbacks` for any products route under
 * `wc/store` or `wc/store/v1` (`WP_REST_Request::offsetUnset()` drops them
 * from every parameter source, and the module runs after WordPress has
 * sanitised the request, so the route callback simply finds none), and at
 * `woocommerce_hydration_dispatch_request`, the one pre-callback hook the
 * Hydration service runs for the requests it dispatches itself (the route
 * there still carries its query string, which the match strips). Price
 * ordering on the Store API needs nothing extra: `ProductQuery` calls
 * `WC_Query::get_catalog_ordering_args( $orderby, $order )`, which runs the
 * `woocommerce_get_catalog_ordering_args` filter hooked above, so
 * `orderby=price` on the Store API is reset exactly as on the shop page.
 *
 * WordPress core's own REST API. The product post type is `show_in_rest`,
 * so `GET /wp-json/wp/v2/product` answers a guest, and the Product
 * Collection block's controller (`ProductCollection\Controller`, WooCommerce
 * 11.1) hooks `rest_product_query` at priority 10 for the block editor: for
 * any request carrying `isProductCollectionBlock`, with no capability check,
 * it feeds the request's `priceRange` and `orderby` into
 * `QueryBuilder::get_final_query_args()`. `priceRange` becomes the
 * `wc_product_meta_lookup.min_price`/`max_price` clauses that
 * `add_price_range_filter_posts_clauses()` appends -- not the classic clause
 * stripped above -- and `orderby=price` (which the controller adds to the
 * route's `orderby` enum through `rest_product_collection_params`) attaches
 * `add_price_sorting_posts_clauses()`, which orders by `min_price` or
 * `max_price`. Both `posts_clauses` callbacks key on the query vars they
 * were given (`priceRange` present, `orderby === 'price'`), so the fix is
 * one filter on `rest_product_query` after the controller's: a guest's
 * query args lose `priceRange`, and a price ordering becomes the route's
 * own default (`date`, `desc`), which makes both callbacks no-ops. Of the
 * custom orderings the controller accepts (`popularity`, `rating`,
 * `post__in`, `price`, `sales`, `menu_order`, `random`) only `price` is
 * derived from an amount; the others pass through. The classic
 * `min_price`/`max_price` names are read there with `get_query_var()`,
 * which is empty on a REST request, so they carry nothing on this route.
 *
 * Ownership. This module owns the price-shaped *question* on every query
 * surface: the main query, the blocks' query vars, the Store API's request
 * parameters, the widget and blocks that pose it. The Store API module owns
 * the *answer* -- what the response carries -- so switching that module off
 * hands back the amounts but not the bisection.
 */
final class PriceFilters implements Module {
	public const ID = 'price_filters';

	/**
	 * The range query parameters, as WooCommerce names them.
	 *
	 * @var string[]
	 */
	public const PRICE_PARAMS = [ 'min_price', 'max_price' ];

	/**
	 * Blocks whose only purpose is to show or set a price range.
	 *
	 * @var string[]
	 */
	public const BLOCKS = [
		'woocommerce/price-filter',
		'woocommerce/product-filter-price',
		'woocommerce/product-filter-price-slider',
	];

	/**
	 * The legacy filter blocks' wrapper, whose `filterType` attribute says
	 * which filter it holds.
	 */
	public const WRAPPER_BLOCK = 'woocommerce/filter-wrapper';

	/**
	 * The `filterType` value of a price wrapper.
	 */
	public const WRAPPER_PRICE_TYPE = 'price-filter';

	/**
	 * The Interactivity API namespace the newer price filter block registers
	 * its range in.
	 */
	public const FILTERS_STATE_NAMESPACE = 'woocommerce/product-filters';

	/**
	 * The legacy widget's class.
	 */
	public const WIDGET_CLASS = 'WC_Widget_Price_Filter';

	/**
	 * The WordPress core REST filter for the product post type's collection
	 * query, and the two query args the Product Collection controller derives
	 * from an amount there.
	 */
	public const CORE_REST_QUERY_FILTER = 'rest_product_query';
	public const CORE_REST_RANGE_ARG    = 'priceRange';
	public const CORE_REST_PRICE_ORDER  = 'price';

	/**
	 * The `/wp/v2/product` route's own defaults for `orderby` and `order`
	 * (`WP_REST_Posts_Controller::get_collection_params()`).
	 *
	 * @var array{orderby: string, order: string}
	 */
	public const CORE_REST_DEFAULT_ORDERING = [
		'orderby' => 'date',
		'order'   => 'desc',
	];

	/**
	 * The Store API products routes, under both namespaces, with or without
	 * a sub-route (`/{id}`, `/{slug}`, `/collection-data`).
	 */
	public const STORE_API_PRODUCTS_PATTERN = '#^/wc/store(?:/v\d+)?/products(?:/|$)#i';

	/**
	 * The clause `WC_Query::price_filter_post_clauses()` appends, with its two
	 * `%f`-formatted bounds.
	 */
	private const PRICE_CLAUSE = '# AND NOT \([-\d.]+<wc_product_meta_lookup\.min_price OR [-\d.]+>wc_product_meta_lookup\.max_price \) #';

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Attach every hook.
	 */
	public function register(): void {
		add_filter( 'request', [ $this, 'drop_price_query_vars' ], PHP_INT_MAX );
		add_filter( 'posts_clauses', [ $this, 'strip_price_clause' ], 11, 2 );
		add_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'reset_price_ordering' ], PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_catalog_orderby', [ $this, 'remove_price_sort_options' ], PHP_INT_MAX );
		add_filter( 'widget_display_callback', [ $this, 'hide_price_filter_widget' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ $this, 'remove_price_filter_blocks' ], PHP_INT_MAX, 2 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'drop_store_api_price_params' ], PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_hydration_dispatch_request', [ $this, 'drop_hydration_price_params' ], PHP_INT_MAX, 2 );
		add_filter( self::CORE_REST_QUERY_FILTER, [ $this, 'drop_core_rest_price_args' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Remove exactly what register() added.
	 */
	public function unregister(): void {
		remove_filter( 'request', [ $this, 'drop_price_query_vars' ], PHP_INT_MAX );
		remove_filter( 'posts_clauses', [ $this, 'strip_price_clause' ], 11 );
		remove_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'reset_price_ordering' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_catalog_orderby', [ $this, 'remove_price_sort_options' ], PHP_INT_MAX );
		remove_filter( 'widget_display_callback', [ $this, 'hide_price_filter_widget' ], PHP_INT_MAX );
		remove_filter( 'render_block', [ $this, 'remove_price_filter_blocks' ], PHP_INT_MAX );
		remove_filter( 'rest_request_before_callbacks', [ $this, 'drop_store_api_price_params' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_hydration_dispatch_request', [ $this, 'drop_hydration_price_params' ], PHP_INT_MAX );
		remove_filter( self::CORE_REST_QUERY_FILTER, [ $this, 'drop_core_rest_price_args' ], PHP_INT_MAX );
	}

	// -- range parameters --------------------------------------------------

	/**
	 * Filter callback for `request`: a guest's query vars carry no price range.
	 *
	 * @param mixed $query_vars The main query's variables.
	 * @return mixed
	 */
	public function drop_price_query_vars( mixed $query_vars ): mixed {
		if ( ! Context::is_guest() || ! is_array( $query_vars ) ) {
			return $query_vars;
		}
		foreach ( self::PRICE_PARAMS as $param ) {
			unset( $query_vars[ $param ] );
		}
		return $query_vars;
	}

	/**
	 * Filter callback for `posts_clauses`, one priority after WooCommerce:
	 * the price-range clause is removed from the WHERE, and nothing else is.
	 *
	 * @param mixed $clauses  Query clauses (`where`, `join`, `orderby`, ...).
	 * @param mixed $wp_query The query. Unused: the clause is unique to
	 *                        WooCommerce's price filter wherever it appears.
	 * @return mixed
	 */
	public function strip_price_clause( mixed $clauses, mixed $wp_query = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $clauses ) || ! isset( $clauses['where'] ) || ! is_string( $clauses['where'] ) ) {
			return $clauses;
		}
		$clauses['where'] = (string) preg_replace( self::PRICE_CLAUSE, ' ', $clauses['where'] );
		return $clauses;
	}

	/**
	 * Filter callback for `rest_request_before_callbacks`: a guest's Store API
	 * products request carries no price range. The response so far is
	 * returned untouched; the request object is modified in place.
	 *
	 * @param mixed $response Response so far, or a WP_Error.
	 * @param mixed $handler  Matched route handler. Unused.
	 * @param mixed $request  The request being dispatched.
	 * @return mixed
	 */
	public function drop_store_api_price_params( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		$this->strip_price_params( $request );
		return $response;
	}

	/**
	 * Filter callback for `woocommerce_hydration_dispatch_request`, the
	 * hydration service's stand-in for the filter above. Returning the
	 * incoming result (null) lets WooCommerce dispatch to the controller as
	 * it would have; the request has lost its range by then.
	 *
	 * @param mixed $result  Hydration result so far (null unless another filter answered).
	 * @param mixed $request The request about to be dispatched.
	 * @return mixed
	 */
	public function drop_hydration_price_params( mixed $result, mixed $request = null ): mixed {
		$this->strip_price_params( $request );
		return $result;
	}

	/**
	 * Remove `min_price` and `max_price` from a guest's request to a Store
	 * API products route. Anything else -- a member's request, another
	 * route, something that is not a request -- is left alone.
	 *
	 * @param mixed $request Whatever the filter passed.
	 */
	private function strip_price_params( mixed $request ): void {
		if ( ! Context::is_guest() || ! $request instanceof WP_REST_Request ) {
			return;
		}
		if ( ! preg_match( self::STORE_API_PRODUCTS_PATTERN, RestResponseFilter::normalise_route( (string) $request->get_route() ) ) ) {
			return;
		}
		foreach ( self::PRICE_PARAMS as $param ) {
			unset( $request[ $param ] );
		}
	}

	/**
	 * Filter callback for `rest_product_query` (`/wp/v2/product`), after the
	 * Product Collection controller has built the block's query args from
	 * the request: a guest's args carry no `priceRange`, and a price ordering
	 * becomes the route's default ordering. Everything else -- a member's
	 * args, another ordering, anything that is not an array -- is left alone.
	 *
	 * @param mixed $args    WP_Query args for the collection request.
	 * @param mixed $request The REST request. Unused: the controller has
	 *                       already copied what matters into the args.
	 * @return mixed
	 */
	public function drop_core_rest_price_args( mixed $args, mixed $request = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $args ) ) {
			return $args;
		}
		unset( $args[ self::CORE_REST_RANGE_ARG ] );
		if ( self::CORE_REST_PRICE_ORDER === ( $args['orderby'] ?? '' ) ) {
			$args = array_merge( $args, self::CORE_REST_DEFAULT_ORDERING );
		}
		return $args;
	}

	// -- sorting -----------------------------------------------------------

	/**
	 * Filter callback for `woocommerce_get_catalog_ordering_args`: a guest's
	 * `orderby=price` becomes the catalogue's default ordering.
	 *
	 * @param mixed $args    Ordering args (`orderby`, `order`, `meta_key`).
	 * @param mixed $orderby The requested orderby key, lower-cased.
	 * @param mixed $order   The requested direction.
	 * @return mixed
	 */
	public function reset_price_ordering( mixed $args, mixed $orderby = '', mixed $order = '' ): mixed {
		if ( ! Context::is_guest() || ! is_array( $args ) || 'price' !== $orderby ) {
			return $args;
		}

		$query = function_exists( 'WC' ) ? ( WC()->query ?? null ) : null;
		if ( is_object( $query ) ) {
			// Attached by get_catalog_ordering_args() just before this filter ran.
			remove_filter( 'posts_clauses', [ $query, 'order_by_price_asc_post_clauses' ] );
			remove_filter( 'posts_clauses', [ $query, 'order_by_price_desc_post_clauses' ] );
		}

		[ $default_orderby, $default_order ] = $this->default_ordering();

		if ( is_object( $query ) && method_exists( $query, 'get_catalog_ordering_args' ) ) {
			// Re-enters this filter with the default key, which passes straight through.
			return $query->get_catalog_ordering_args( $default_orderby, $default_order );
		}

		return [
			'orderby'  => 'menu_order title',
			'order'    => 'ASC',
			'meta_key' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		];
	}

	/**
	 * The catalogue's default ordering as WooCommerce reads it, split into key
	 * and direction, with `price` itself mapped to `menu_order` so a shop whose
	 * default sort is by price still gets a price-free order for guests.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function default_ordering(): array {
		// WooCommerce's own filter, applied the way WC_Query applies it, so a site that filters the default is read the same way.
		$default = (string) apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$parts   = explode( '-', $default );
		$orderby = strtolower( $parts[0] );
		$order   = $parts[1] ?? '';

		if ( '' === $orderby || 'price' === $orderby ) {
			return [ 'menu_order', '' ];
		}
		return [ $orderby, $order ];
	}

	/**
	 * Filter callback for `woocommerce_catalog_orderby`: no price options in
	 * the sort dropdown for a guest.
	 *
	 * @param mixed $options Dropdown options keyed by orderby value.
	 * @return mixed
	 */
	public function remove_price_sort_options( mixed $options ): mixed {
		if ( ! Context::is_guest() || ! is_array( $options ) ) {
			return $options;
		}
		unset( $options['price'], $options['price-desc'] );
		return $options;
	}

	// -- widget and blocks -------------------------------------------------

	/**
	 * Filter callback for `widget_display_callback`: returning false skips the
	 * widget entirely.
	 *
	 * @param mixed $instance The widget instance settings.
	 * @param mixed $widget   The widget object.
	 * @return mixed
	 */
	public function hide_price_filter_widget( mixed $instance, mixed $widget = null ): mixed {
		if ( ! Context::is_guest() || ! is_a( $widget, self::WIDGET_CLASS ) ) {
			return $instance;
		}
		return false;
	}

	/**
	 * Filter callback for `render_block`: price filter blocks render nothing
	 * for a guest, and the range state the newer block has already registered
	 * is emptied.
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block, with its `blockName` and `attrs`.
	 * @return mixed
	 */
	public function remove_price_filter_blocks( mixed $content, mixed $block = null ): mixed {
		if ( ! Context::is_guest() || ! is_array( $block ) ) {
			return $content;
		}
		$name = $block['blockName'] ?? '';

		if ( 'woocommerce/product-filter-price' === $name && function_exists( 'wp_interactivity_state' ) ) {
			wp_interactivity_state(
				self::FILTERS_STATE_NAMESPACE,
				[
					'minPrice'          => 0,
					'maxPrice'          => 0,
					'formattedMinPrice' => '',
					'formattedMaxPrice' => '',
					'rangeStyle'        => '',
				]
			);
		}

		if ( in_array( $name, self::BLOCKS, true ) ) {
			return '';
		}
		if ( self::WRAPPER_BLOCK === $name && self::WRAPPER_PRICE_TYPE === ( $block['attrs']['filterType'] ?? '' ) ) {
			return '';
		}
		return $content;
	}
}
