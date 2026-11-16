<?php
/**
 * Configuration fingerprint and table inventory.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the allowlisted configuration values a snapshot records, and the
 * site's tables for row counting.
 *
 * Every value here is either public (site URLs, theme, plugin list) or
 * technical vocabulary. Nothing personal, nothing secret.
 */
final class Site_Profile {

	/**
	 * Tables above this estimated size get an approximate count.
	 */
	const EXACT_COUNT_LIMIT = 1000000;

	/**
	 * Allowlisted configuration fingerprint for the current site.
	 *
	 * @return array<string, mixed>
	 */
	public static function config() {
		global $wpdb, $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$theme   = wp_get_theme();
		$uploads = wp_get_upload_dir();
		$storage = Woo_Storage::detect();

		return array(
			'home'                => (string) get_option( 'home' ),
			'siteurl'             => (string) get_option( 'siteurl' ),
			'wp_version'          => (string) $wp_version,
			'php_version'         => PHP_VERSION,
			'db_version'          => (string) $wpdb->db_server_info(),
			'table_prefix'        => (string) $wpdb->prefix,
			'is_multisite'        => is_multisite(),
			'blog_id'             => get_current_blog_id(),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'theme'               => array(
				'stylesheet' => (string) $theme->get_stylesheet(),
				'template'   => (string) $theme->get_template(),
				'version'    => (string) $theme->get( 'Version' ),
			),
			'plugins'             => self::active_plugins(),
			'upload_baseurl'      => isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '',
			'woocommerce'         => array(
				'active'       => $storage['wc_active'],
				'version'      => $storage['wc_version'],
				'storage'      => $storage['mode'],
				'hpos_table'   => $storage['hpos_table'],
				'sync_enabled' => $storage['sync'],
				'pages'        => self::woocommerce_pages(),
			),
		);
	}

	/**
	 * Active plugins (site and network) with versions.
	 *
	 * @return array<string, string> Plugin file => version.
	 */
	private static function active_plugins() {
		$files = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$installed = get_plugins();
		$out       = array();

		foreach ( array_unique( array_map( 'strval', $files ) ) as $file ) {
			if ( ! preg_match( '#^[A-Za-z0-9._/-]{1,200}$#', $file ) ) {
				continue;
			}
			$out[ $file ] = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';
		}

		ksort( $out );

		return $out;
	}

	/**
	 * WooCommerce page assignments (IDs only).
	 *
	 * @return array<string, int>
	 */
	private static function woocommerce_pages() {
		$pages = array();

		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$value = get_option( 'woocommerce_' . $page . '_page_id', null );
			if ( null !== $value ) {
				$pages[ $page ] = absint( $value );
			}
		}

		return $pages;
	}

	/**
	 * Tables belonging to this site, excluding the plugin's own tables.
	 *
	 * On a multisite main site, other sites' tables (prefix_2_…) are left out.
	 *
	 * @return string[]
	 */
	public static function tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema listing.
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$own    = Schema::table_names();
		$out    = array();

		$other_site = is_multisite() && is_main_site()
			? '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/'
			: '';

		foreach ( $tables as $table ) {
			$table = (string) $table;

			if ( in_array( $table, $own, true ) || ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $table ) ) {
				continue;
			}

			if ( '' !== $other_site && preg_match( $other_site, $table ) ) {
				continue;
			}

			$out[] = $table;
		}

		sort( $out );

		return $out;
	}

	/**
	 * Row count for one table.
	 *
	 * @param string $table Table name from tables().
	 * @return array{rows: int, approximate: bool, missing: bool}
	 */
	public static function count_rows( $table ) {
		global $wpdb;

		$estimate = self::estimated_rows( $table );

		if ( null !== $estimate && $estimate > self::EXACT_COUNT_LIMIT ) {
			return array(
				'rows'        => $estimate,
				'approximate' => true,
				'missing'     => false,
			);
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count; table name validated by tables().
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$wpdb->suppress_errors( $suppress );

		return array(
			'rows'        => null === $count ? 0 : (int) $count,
			'approximate' => false,
			'missing'     => null === $count,
		);
	}

	/**
	 * Estimated rows from information_schema, or null when unavailable.
	 *
	 * @param string $table Table name.
	 * @return int|null
	 */
	private static function estimated_rows( $table ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only metadata lookup.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		$wpdb->suppress_errors( $suppress );

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Revision count (revisions are counted, not tracked by ID).
	 *
	 * @return int
	 */
	public static function revision_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision' ) );
	}
}
