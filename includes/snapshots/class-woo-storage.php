<?php
/**
 * WooCommerce order storage detection.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Works out where this site's WooCommerce orders actually live.
 *
 * Detection reads the database directly instead of relying only on
 * WooCommerce's API, so it also works when WooCommerce is deactivated after
 * a migration (a common failure we need to be able to inspect).
 */
final class Woo_Storage {

	const MODE_HPOS   = 'hpos';
	const MODE_LEGACY = 'legacy';
	const MODE_NONE   = 'none';

	/**
	 * Post types that are WooCommerce order storage and must never be
	 * tracked as ordinary posts.
	 *
	 * @var string[]
	 */
	const ORDER_POST_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' );

	/**
	 * Detect storage for the current site.
	 *
	 * @return array{mode: string, hpos_table: bool, sync: bool, wc_active: bool, wc_version: string}
	 */
	public static function detect() {
		$hpos_table = self::hpos_table_exists();
		$enabled    = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
		$sync       = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$wc_active  = class_exists( 'WooCommerce' );

		if ( $enabled && $hpos_table ) {
			$mode = self::MODE_HPOS;
		} elseif ( self::legacy_orders_exist() || ( $wc_active && ! $enabled ) ) {
			$mode = self::MODE_LEGACY;
		} elseif ( $hpos_table ) {
			$mode = self::MODE_HPOS;
		} else {
			$mode = self::MODE_NONE;
		}

		return array(
			'mode'       => $mode,
			'hpos_table' => $hpos_table,
			'sync'       => $sync,
			'wc_active'  => $wc_active,
			'wc_version' => self::version(),
		);
	}

	/**
	 * Order types present in the given storage.
	 *
	 * @param string $mode Storage mode.
	 * @return string[]
	 */
	public static function order_types( $mode ) {
		global $wpdb;

		if ( self::MODE_HPOS === $mode && self::hpos_table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only discovery.
			$types = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT type FROM %i', self::hpos_table() ) );
		} elseif ( self::MODE_LEGACY === $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only discovery.
			$types = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_type FROM {$wpdb->posts} WHERE post_type IN (%s, %s, %s)",
					'shop_order',
					'shop_order_refund',
					'shop_subscription'
				)
			);
		} else {
			$types = array();
		}

		$types = array_values(
			array_filter(
				array_map( 'strval', (array) $types ),
				static function ( $type ) {
					return 'shop_order_placehold' !== $type && (bool) preg_match( '/^[a-z0-9_]{1,20}$/', $type );
				}
			)
		);

		sort( $types );

		return $types;
	}

	/**
	 * Snapshot type key for an order type.
	 *
	 * @param string $order_type WooCommerce order type.
	 * @return string
	 */
	public static function type_key( $order_type ) {
		if ( 'shop_order' === $order_type ) {
			return 'wc_order';
		}
		if ( 'shop_order_refund' === $order_type ) {
			return 'wc_refund';
		}

		return 'wc_' . preg_replace( '/^shop_/', '', $order_type );
	}

	/**
	 * Full HPOS orders table name.
	 *
	 * @return string
	 */
	public static function hpos_table() {
		global $wpdb;

		return $wpdb->prefix . 'wc_orders';
	}

	/**
	 * Whether the HPOS orders table exists.
	 *
	 * @return bool
	 */
	public static function hpos_table_exists() {
		global $wpdb;

		$table = self::hpos_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Whether any orders are stored as posts.
	 *
	 * @return bool
	 */
	private static function legacy_orders_exist() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only check.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) LIMIT 1",
				'shop_order',
				'shop_order_refund'
			)
		);

		return null !== $id;
	}

	/**
	 * WooCommerce version, from the plugin if loaded, else from its option.
	 *
	 * @return string
	 */
	private static function version() {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		$stored = get_option( 'woocommerce_version' );

		return is_string( $stored ) ? $stored : '';
	}
}
