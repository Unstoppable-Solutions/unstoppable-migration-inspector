<?php
/**
 * Site identity markers recorded in snapshots.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * A random site key plus a hash of the site name.
 *
 * The site key is created once, when the plugin is activated, and stored as
 * an option. A migration that copies the database copies the key too, so a
 * snapshot whose key matches the destination's key was taken on the same
 * site (or on the site this database came from). Neither value is personal:
 * the key is random, and the site name is public.
 */
final class Site_Identity {

	/**
	 * Option holding the site key.
	 */
	const OPTION = 'umin_site_key';

	/**
	 * Create the site key if it does not exist yet.
	 *
	 * @return string The key.
	 */
	public static function ensure_key() {
		$key = get_option( self::OPTION, '' );

		if ( is_string( $key ) && self::is_valid_key( $key ) ) {
			return $key;
		}

		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::OPTION, $key, false );

		return $key;
	}

	/**
	 * The site key, or an empty string when none exists.
	 *
	 * @return string
	 */
	public static function key() {
		$key = get_option( self::OPTION, '' );

		return is_string( $key ) && self::is_valid_key( $key ) ? $key : '';
	}

	/**
	 * Whether a value is a well-formed site key.
	 *
	 * @param mixed $key Value.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && (bool) preg_match( '/^[0-9a-f]{32}$/', $key );
	}

	/**
	 * Hash of the site name, normalised for case and spacing.
	 *
	 * @param string|null $name Site name, or null for the current site.
	 * @return string SHA-256 hex, or an empty string for an empty name.
	 */
	public static function name_hash( $name = null ) {
		$name = null === $name ? (string) get_option( 'blogname' ) : (string) $name;
		$name = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );

		return '' === $name ? '' : hash( 'sha256', $name );
	}

	/**
	 * Identity block stored in a snapshot header.
	 *
	 * @return array{site_key: string, name_hash: string}
	 */
	public static function for_header() {
		return array(
			'site_key'  => self::key(),
			'name_hash' => self::name_hash(),
		);
	}
}
