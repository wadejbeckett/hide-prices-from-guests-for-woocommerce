<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stubs for unit tests. Under
 * WordPress this file defines nothing: the real classes already exist.
 *
 * Only what the plugin touches is modelled -- get_route(), get_method() and
 * the parameter accessors (including ArrayAccess, which is how WordPress
 * exposes them) on the request, get_data() and set_data() on the response --
 * because a unit test that needed more of the REST stack would be testing
 * WordPress rather than this plugin.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// Guarded on the request class: it implements an interface, so PHP declares
// it only when execution reaches it, whereas the plain classes below are
// hoisted at compile time and would already exist here.
if ( class_exists( 'WP_REST_Request' ) ) {
	return;
}

class WP_REST_Request implements ArrayAccess {

	/** @var array<string, mixed> */
	private array $params = [];

	public function __construct( private string $route = '', private string $method = 'GET', array $params = [] ) {
		$this->params = $params;
	}

	public function get_route(): string {
		return $this->route;
	}

	public function get_method(): string {
		return $this->method;
	}

	public function get_params(): array {
		return $this->params;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}

	public function set_param( string $key, mixed $value ): void {
		$this->params[ $key ] = $value;
	}

	public function has_param( string $key ): bool {
		return array_key_exists( $key, $this->params );
	}

	public function offsetExists( mixed $offset ): bool {
		return $this->has_param( (string) $offset );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->get_param( (string) $offset );
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->set_param( (string) $offset, $value );
	}

	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ (string) $offset ] );
	}
}

class WP_REST_Response {

	public function __construct( private mixed $data = null, private int $status = 200 ) {}

	public function get_data(): mixed {
		return $this->data;
	}

	public function set_data( mixed $data ): void {
		$this->data = $data;
	}

	public function get_status(): int {
		return $this->status;
	}
}

/**
 * Just enough WP_Error for a module that returns one to short-circuit a REST
 * dispatch. Declared here beside the other core classes the tests need.
 */
class WP_Error {

	/**
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param mixed  $data    Error data, typically [ 'status' => 403 ].
	 */
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}
