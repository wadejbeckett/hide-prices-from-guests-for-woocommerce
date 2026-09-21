<?php
/**
 * Minimal PHPUnit\Framework\TestCase stand-in for hosts without PHPUnit.
 * Under real PHPUnit this file defines nothing.
 *
 * @package PriceCloak
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace PHPUnit\Framework;

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! class_exists( TestCase::class ) ) {

class AssertionFailedError extends \Exception {}
class SkippedTestError extends \Exception {}

abstract class TestCase {
	protected function setUp(): void {}
	protected function tearDown(): void {}

	public function runTest( string $method ): void {
		$this->setUp();
		try {
			$this->$method();
		} finally {
			$this->tearDown();
		}
	}

	public static function fail( string $message = '' ): never {
		throw new AssertionFailedError( $message );
	}

	public static function markTestSkipped( string $message = '' ): never {
		throw new SkippedTestError( $message );
	}

	public static function assertTrue( mixed $condition, string $message = '' ): void {
		if ( true !== $condition ) {
			self::fail( $message ?: 'Failed asserting that value is true.' );
		}
	}

	public static function assertFalse( mixed $condition, string $message = '' ): void {
		if ( false !== $condition ) {
			self::fail( $message ?: 'Failed asserting that value is false.' );
		}
	}

	public static function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			self::fail( $message ?: sprintf( "Failed asserting that %s is identical to %s.", var_export( $actual, true ), var_export( $expected, true ) ) );
		}
	}

	public static function assertNotSame( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( $expected === $actual ) {
			self::fail( $message ?: 'Failed asserting that two values are not identical.' );
		}
	}

	public static function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( $expected != $actual ) { // phpcs:ignore Universal.Operators.StrictComparisons
			self::fail( $message ?: sprintf( "Failed asserting that %s equals %s.", var_export( $actual, true ), var_export( $expected, true ) ) );
		}
	}

	public static function assertNull( mixed $actual, string $message = '' ): void {
		self::assertSame( null, $actual, $message );
	}

	public static function assertCount( int $expected, \Countable|array $haystack, string $message = '' ): void {
		self::assertSame( $expected, count( $haystack ), $message ?: sprintf( 'Failed asserting that count is %d.', $expected ) );
	}

	public static function assertEmpty( mixed $actual, string $message = '' ): void {
		if ( ! empty( $actual ) ) {
			self::fail( $message ?: 'Failed asserting that value is empty.' );
		}
	}

	public static function assertNotEmpty( mixed $actual, string $message = '' ): void {
		if ( empty( $actual ) ) {
			self::fail( $message ?: 'Failed asserting that value is not empty.' );
		}
	}

	public static function assertContains( mixed $needle, iterable $haystack, string $message = '' ): void {
		foreach ( $haystack as $item ) {
			if ( $item === $needle ) {
				return;
			}
		}
		self::fail( $message ?: 'Failed asserting that iterable contains value.' );
	}

	public static function assertNotContains( mixed $needle, iterable $haystack, string $message = '' ): void {
		foreach ( $haystack as $item ) {
			if ( $item === $needle ) {
				self::fail( $message ?: 'Failed asserting that iterable does not contain value.' );
			}
		}
	}

	public static function assertLessThan( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( ! ( $actual < $expected ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that %s is less than %s.', var_export( $actual, true ), var_export( $expected, true ) ) );
		}
	}

	public static function assertGreaterThan( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( ! ( $actual > $expected ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that %s is greater than %s.', var_export( $actual, true ), var_export( $expected, true ) ) );
		}
	}

	public static function assertFileExists( string $filename, string $message = '' ): void {
		if ( ! is_file( $filename ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that file "%s" exists.', $filename ) );
		}
	}

	public static function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {
		if ( ! str_starts_with( $string, $prefix ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that "%s" starts with "%s".', $string, $prefix ) );
		}
	}

	public static function assertStringEndsWith( string $suffix, string $string, string $message = '' ): void {
		if ( ! str_ends_with( $string, $suffix ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that "%s" ends with "%s".', $string, $suffix ) );
		}
	}

	public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that "%s" contains "%s".', $haystack, $needle ) );
		}
	}

	public static function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {
		if ( str_contains( $haystack, $needle ) ) {
			self::fail( $message ?: sprintf( 'Failed asserting that "%s" does not contain "%s".', $haystack, $needle ) );
		}
	}

	public static function assertArrayHasKey( string|int $key, array|\ArrayAccess $array, string $message = '' ): void {
		$has = $array instanceof \ArrayAccess ? $array->offsetExists( $key ) : array_key_exists( $key, $array );
		if ( ! $has ) {
			self::fail( $message ?: sprintf( 'Failed asserting that array has key %s.', var_export( $key, true ) ) );
		}
	}

	public static function assertArrayNotHasKey( string|int $key, array|\ArrayAccess $array, string $message = '' ): void {
		$has = $array instanceof \ArrayAccess ? $array->offsetExists( $key ) : array_key_exists( $key, $array );
		if ( $has ) {
			self::fail( $message ?: sprintf( 'Failed asserting that array does not have key %s.', var_export( $key, true ) ) );
		}
	}

	public static function assertInstanceOf( string $class, mixed $actual, string $message = '' ): void {
		if ( ! $actual instanceof $class ) {
			self::fail( $message ?: sprintf( 'Failed asserting that value is an instance of %s.', $class ) );
		}
	}
}
}
