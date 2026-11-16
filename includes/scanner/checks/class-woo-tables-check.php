<?php
/**
 * WooCommerce tables, lookup data and store visibility.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Core WooCommerce tables, the product lookup and analytics tables that
 * migrations often skip, and "coming soon" mode.
 */
final class Woo_Tables_Check extends Check {

	/**
	 * Tables every WooCommerce store has.
	 */
	const REQUIRED = array( 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'woocommerce_sessions', 'woocommerce_tax_rates', 'woocommerce_shipping_zones', 'wc_product_meta_lookup' );

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'woo_tables';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'woocommerce';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Store tables and settings', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'table_missing'   => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'WooCommerce tables are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Tables WooCommerce needs are not in the database. Order items, tax rates, shipping zones or customer sessions are not available. The migration probably copied only the standard WordPress tables.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the missing tables from the old site or a backup before taking orders.', 'unstoppable-migration-inspector' ),
			),
			'lookup_stale'    => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Product lookup data is incomplete', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce keeps a lookup table of product prices, stock and ratings for fast filtering and sorting. It has far fewer rows than there are products, so price filters, sorting and some blocks can miss products.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Go to WooCommerce > Status > Tools and run "Regenerate the product lookup tables".', 'unstoppable-migration-inspector' ),
			),
			'analytics_stale' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Store analytics are missing past orders', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce Analytics has data for far fewer orders than the store has. Reports will show lower sales than the real figures.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Go to WooCommerce > Settings > Advanced > Analytics and import historical data.', 'unstoppable-migration-inspector' ),
			),
			'coming_soon'     => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'The store is in "coming soon" mode', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Visitors who are not logged in see a "coming soon" page instead of the store. That is often switched on while setting up a copy and forgotten when it goes live.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'If the store should be open, go to WooCommerce > Settings > Site visibility and choose Live.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Applicability.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string
	 */
	public function applicable( Scan_Context $context ) {
		unset( $context );

		return class_exists( 'WooCommerce' ) ? true : __( 'WooCommerce is not active.', 'unstoppable-migration-inspector' );
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		global $wpdb;

		$findings = array();

		foreach ( self::REQUIRED as $name ) {
			if ( ! Db_Info::table_exists( $wpdb->prefix . $name ) ) {
				$findings[] = ( new Finding( 'table_missing', $wpdb->prefix . $name ) )->on( 'table', $wpdb->prefix . $name );
			}
		}

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		if ( Db_Info::table_exists( $lookup ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
			$products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish'" );
			$rows     = Db_Info::count( $lookup );

			if ( $products >= 10 && $rows < 0.9 * $products ) {
				$findings[] = ( new Finding( 'lookup_stale', $rows . ' / ' . $products ) )->on( 'table', $lookup )->with(
					array(
						'rows'     => $rows,
						'products' => $products,
					)
				);
			}
		}

		$stats = $wpdb->prefix . 'wc_order_stats';

		if ( Db_Info::table_exists( $stats ) ) {
			$orders = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) && Db_Info::table_exists( $wpdb->prefix . 'wc_orders' )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = %s AND status NOT IN ('auto-draft','trash','checkout-draft')", $wpdb->prefix . 'wc_orders', 'shop_order' ) )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
				: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status NOT IN ('auto-draft','trash','checkout-draft')" );
			$rows = Db_Info::count( $stats );

			if ( $orders >= 10 && $rows < 0.9 * $orders ) {
				$findings[] = ( new Finding( 'analytics_stale', $rows . ' / ' . $orders ) )->on( 'table', $stats )->with(
					array(
						'rows'   => $rows,
						'orders' => $orders,
					)
				);
			}
		}

		$staging = Scan_Context::is_temporary_host( Location::host( (string) $context->get( 'location' ) ) );

		if ( 'yes' === get_option( 'woocommerce_coming_soon' ) && ! $staging ) {
			$findings[] = ( new Finding( 'coming_soon' ) )->on( 'option', 'woocommerce_coming_soon' );
		}

		return $findings;
	}
}
