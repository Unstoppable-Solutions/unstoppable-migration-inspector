<?php
/**
 * Small read-only database helpers for checks.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Table listing and engine detection, shared by several checks.
 */
final class Db_Info {

	/**
	 * Every table in the database (per request cache).
	 *
	 * @var string[]|null
	 */
	private static $all = null;

	/**
	 * All table names in the current database.
	 *
	 * @return string[]
	 */
	public static function all_tables() {
		global $wpdb;

		if ( null === self::$all ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema listing.
			self::$all = array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) );
		}

		return self::$all;
	}

	/**
	 * Forget the cached listing (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$all = null;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		return in_array( (string) $table, self::all_tables(), true );
	}

	/**
	 * Row count of a table (exact; only used on bounded tables).
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public static function count( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Whether the site runs on the SQLite Database Integration drop-in.
	 *
	 * @return bool
	 */
	public static function is_sqlite() {
		global $wpdb;

		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( is_object( $wpdb ) && false !== stripos( get_class( $wpdb ), 'sqlite' ) );
	}

	/**
	 * Raw option value straight from the options table, bypassing constants
	 * and filters (WP_HOME, WP_SITEURL…).
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public static function raw_option( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only; the stored value is needed, not the filtered one.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : (string) $value;
	}
}
