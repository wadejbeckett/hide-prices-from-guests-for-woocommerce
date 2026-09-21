<?php
/**
 * Test double: records register/unregister calls and adds one filter.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak\Tests\Support;

use PriceCloak\Module;

final class FakeModule implements Module {
	public int $registered   = 0;
	public int $unregistered = 0;

	public function __construct( private string $id ) {}

	public function id(): string {
		return $this->id;
	}

	public function register(): void {
		++$this->registered;
		add_filter( 'pricecloak_fake_' . $this->id, [ $this, 'passthrough' ] );
	}

	public function unregister(): void {
		++$this->unregistered;
		remove_filter( 'pricecloak_fake_' . $this->id, [ $this, 'passthrough' ] );
	}

	public function passthrough( mixed $value ): mixed {
		return $value;
	}
}
