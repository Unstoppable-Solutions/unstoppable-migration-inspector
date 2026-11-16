<?php
/**
 * Base test case with Brain Monkey and common WordPress stubs.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Sets up Brain Monkey around every test.
 */
abstract class Test_Case extends TestCase {

	/**
	 * Values returned by the stubbed get_option(), keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	protected $options = array();

	/**
	 * Set up Brain Monkey and generic stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( wp_strip_all_tags_stub( (string) $value ) );
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stub for wp_parse_url().
			}
		);

		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

/**
 * Minimal tag stripper used by the sanitize_text_field() stub.
 *
 * @param string $value Input.
 * @return string
 */
function wp_strip_all_tags_stub( $value ) {
	return preg_replace( '/<[^>]*>/', '', $value );
}
