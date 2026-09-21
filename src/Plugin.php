<?php
/**
 * Boot: read the master switch once; when off, nothing but the settings
 * section is registered. When on, register each enabled module.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PriceCloak;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle.
 */
final class Plugin {
	public const VERSION = '1.0.0';

	/**
	 * Modules whose register() has run.
	 *
	 * @var Module[]
	 */
	private array $registered = [];

	/**
	 * Constructor.
	 *
	 * @param Module[] $modules Every module the build knows about, enabled or not.
	 */
	public function __construct( private array $modules = [] ) {}

	/**
	 * Register the settings section always, then enabled modules only when the
	 * master switch is on.
	 */
	public function boot(): void {
		( new Settings( $this->known_module_ids() ) )->register();

		if ( ! Options::enabled() ) {
			return;
		}
		foreach ( $this->modules as $module ) {
			if ( Options::module_enabled( $module->id() ) ) {
				$module->register();
				$this->registered[] = $module;
			}
		}
	}

	/**
	 * Unregister every registered module.
	 */
	public function shutdown(): void {
		foreach ( $this->registered as $module ) {
			$module->unregister();
		}
		$this->registered = [];
	}

	/**
	 * Ids of modules currently registered.
	 *
	 * @return string[]
	 */
	public function registered_module_ids(): array {
		return array_map( static fn( Module $m ) => $m->id(), $this->registered );
	}

	/**
	 * Ids of every module in the build, without constructing a Plugin.
	 *
	 * @return string[]
	 */
	public static function module_ids(): array {
		return array_map( static fn( Module $m ) => $m->id(), Modules::all() );
	}

	/**
	 * Ids of every known module.
	 *
	 * @return string[]
	 */
	public function known_module_ids(): array {
		return array_map( static fn( Module $m ) => $m->id(), $this->modules );
	}
}
