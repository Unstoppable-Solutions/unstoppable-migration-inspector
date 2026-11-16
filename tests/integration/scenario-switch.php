<?php
/**
 * Storage switch scenario.
 *
 * Phase "before" (HPOS with compatibility sync on): seed orders, take a
 * snapshot. Between phases run.sh switches WooCommerce to legacy storage.
 * Phase "after" (legacy): delete two orders, compare, and check that exactly
 * those order IDs are missing even though the snapshot read HPOS and the
 * site now stores orders as posts.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Woo_Storage;

require __DIR__ . '/lib.php';

wp_set_current_user( 1 );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'before' === $phase ) {
	echo "== Scenario: storage switch, before ==\n";
	umin_t_same( 'Site starts on HPOS', Woo_Storage::MODE_HPOS, Woo_Storage::detect()['mode'] );

	$woo         = umin_t_seed_woo( 25 );
	$snapshot_id = umin_t_capture( 'Before storage switch' );
	$snapshot    = umin_t_snapshot( $snapshot_id );

	umin_t_same( 'Snapshot read orders from HPOS', Woo_Storage::MODE_HPOS, $snapshot['header']['order_mode'] );
	umin_t_same( 'Snapshot has all 25 orders', 25, $snapshot['header']['types']['wc_order']['count'] );

	update_option( 'umin_test_switch', array( 'snapshot' => $snapshot_id, 'orders' => $woo['orders'] ), false );
	umin_t_done();
	return;
}

echo "== Scenario: storage switch, after ==\n";
$state = get_option( 'umin_test_switch' );
umin_t_same( 'Site now uses legacy storage', Woo_Storage::MODE_LEGACY, Woo_Storage::detect()['mode'] );

sleep( 1 );
$deleted = array( $state['orders'][3], $state['orders'][17] );
foreach ( $deleted as $id ) {
	wc_get_order( $id )->delete( true );
}

$comparison_id = umin_t_compare( $state['snapshot'] );
$summary       = umin_t_summary( $comparison_id );

umin_t_same( 'Missing orders after the switch: exactly the deleted IDs', $deleted, umin_t_ids( $comparison_id, 'wc_order', Comparator::MISSING ) );
umin_t_same( 'Comparison records the storage change', array( 'snapshot' => 'hpos', 'site' => 'legacy' ), $summary['order_storage'] );
umin_t_same( 'Every other order matched by ID across storage modes', 23, $summary['types']['wc_order']['unchanged'] + $summary['types']['wc_order']['changed'] );

$storage_diff = array_filter(
	$summary['config'],
	function ( $diff ) {
		return 'woocommerce_storage' === $diff['key'];
	}
);
umin_t_check( 'Configuration differences explain the storage switch', 1 === count( $storage_diff ) );

delete_option( 'umin_test_switch' );
umin_t_done();
