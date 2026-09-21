<?php
/**
 * A module covers exactly one price-bearing surface. Modules share no state.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every surface module implements.
 */
interface Module {
	/**
	 * Stable identifier used for the per-module option and the probe report.
	 */
	public function id(): string;

	/**
	 * Attach hooks. Called only when the master switch and this module are on.
	 */
	public function register(): void;

	/**
	 * Detach every hook attached by register().
	 */
	public function unregister(): void;
}
