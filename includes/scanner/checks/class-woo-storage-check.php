<?php
/**
 * WooCommerce order storage (HPOS).
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Migration tools that copy only the posts tables, or only some tables,
 * leave orders in one store while WooCommerce reads the other.
 */
final class Woo_Storage_Check extends Check {

	/**
	 * HPOS tables.
	 */
	const HPOS_TABLES = array( 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta' );

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'woo_order_storage';
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
		return __( 'Order storage (HPOS)', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'hpos_tables_missing' => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'High-Performance Order Storage is on, but its tables are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce is set to read orders from its own order tables (HPOS), but some of those tables are not in the database. The migration probably copied only the standard WordPress tables. Orders cannot be shown or saved correctly.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Do not take new orders yet. Copy the missing wc_order* tables from the old site or a backup.', 'unstoppable-migration-inspector' ),
			),
			'orders_elsewhere'    => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'Orders are in the other order store', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce reads orders from one store, but that store is empty while the other store holds orders. Customers and staff will see no order history. This happens when only some tables were migrated, or the HPOS setting changed during the move.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Do not switch storage modes or take new orders yet. Compare with the old site\'s WooCommerce > Settings > Advanced > Features, and copy the missing order tables, or let WooCommerce sync the stores from that screen after a backup.', 'unstoppable-migration-inspector' ),
			),
			'sync_mismatch'       => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'The two order stores hold different numbers of orders', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Compatibility mode keeps both order stores in step, but they hold different numbers of orders. Some orders may be missing from one store, or a sync is still pending.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Check WooCommerce > Settings > Advanced > Features for a pending sync. If none is pending, compare order counts with the old site before changing anything.', 'unstoppable-migration-inspector' ),
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

		unset( $context );
		$findings = array();
		$hpos_on  = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
		$sync     = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$missing  = array();

		foreach ( self::HPOS_TABLES as $name ) {
			if ( ! Db_Info::table_exists( $wpdb->prefix . $name ) ) {
				$missing[] = $wpdb->prefix . $name;
			}
		}

		if ( $hpos_on && ! empty( $missing ) ) {
			foreach ( $missing as $table ) {
				$findings[] = ( new Finding( 'hpos_tables_missing', $table ) )->on( 'table', $table );
			}
			return $findings;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
		$legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
		$hpos = empty( $missing ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s', $wpdb->prefix . 'wc_orders', 'shop_order' ) ) : 0;

		$details = array(
			'hpos_enabled' => $hpos_on,
			'sync_enabled' => $sync,
			'hpos_orders'  => $hpos,
			'posts_orders' => $legacy,
		);

		if ( $hpos_on && 0 === $hpos && $legacy > 0 ) {
			$findings[] = ( new Finding( 'orders_elsewhere', __( 'WooCommerce reads the HPOS tables; orders are only in the posts table.', 'unstoppable-migration-inspector' ) ) )->on( 'setting', 'woocommerce_custom_orders_table_enabled' )->with( $details );
		} elseif ( ! $hpos_on && 0 === $legacy && $hpos > 0 ) {
			$findings[] = ( new Finding( 'orders_elsewhere', __( 'WooCommerce reads the posts table; orders are only in the HPOS tables.', 'unstoppable-migration-inspector' ) ) )->on( 'setting', 'woocommerce_custom_orders_table_enabled' )->with( $details );
		} elseif ( $sync && empty( $missing ) && $hpos !== $legacy ) {
			$findings[] = ( new Finding( 'sync_mismatch', $hpos . ' / ' . $legacy ) )->on( 'setting', 'woocommerce_custom_orders_table_data_sync_enabled' )->with( $details );
		}

		return $findings;
	}
}
