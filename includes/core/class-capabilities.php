<?php
/**
 * Capability model.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may view results and who may change the database.
 *
 * Being an administrator is necessary but not sufficient for database
 * changes: on multisite only super admins qualify, and a site owner can turn
 * off all writes with the UMIN_DISABLE_WRITES constant. Every write request
 * must also pass the per-request safeguards added with the replacement tool.
 */
final class Capabilities {

	/**
	 * Capability required to view the plugin and run read-only scans.
	 */
	const VIEW = 'manage_options';

	/**
	 * Meta capability required to change site data (replacements, undo).
	 */
	const MODIFY = 'umin_modify_database';

	/**
	 * Register the meta capability map.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 2 );
	}

	/**
	 * Map the plugin's meta capability to primitive capabilities.
	 *
	 * @param string[] $caps Primitive capabilities required.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap ) {
		if ( self::MODIFY !== $cap ) {
			return $caps;
		}

		if ( self::writes_disabled() ) {
			return array( 'do_not_allow' );
		}

		$required = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the primitive capability required to change site data.
		 *
		 * Return 'do_not_allow' to switch database changes off for everyone.
		 *
		 * @param string $required Primitive capability.
		 */
		$required = apply_filters( 'umin_modify_database_capability', $required );

		return array( is_string( $required ) && '' !== $required ? $required : 'do_not_allow' );
	}

	/**
	 * Whether the site owner has switched off all database changes.
	 *
	 * @return bool
	 */
	public static function writes_disabled() {
		return defined( 'UMIN_DISABLE_WRITES' ) && UMIN_DISABLE_WRITES;
	}

	/**
	 * Whether the current user may view the plugin and run read-only scans.
	 *
	 * @return bool
	 */
	public static function current_user_can_view() {
		return current_user_can( self::VIEW );
	}

	/**
	 * Whether the current user may change site data.
	 *
	 * @return bool
	 */
	public static function current_user_can_modify() {
		return current_user_can( self::MODIFY );
	}
}
