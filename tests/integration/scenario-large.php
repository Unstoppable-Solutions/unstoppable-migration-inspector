<?php
/**
 * Large database scenario (run with memory_limit=128M on MySQL/MariaDB).
 *
 * Inserts 200,000 posts and 20,000 HPOS orders directly with SQL, captures a
 * snapshot, removes a scattered handful of rows, compares, and checks the
 * exact missing IDs, the time taken and the peak memory.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Woo_Storage;

require __DIR__ . '/lib.php';

wp_set_current_user( 1 );
global $wpdb;

$posts_wanted  = 200000;
$orders_wanted = 20000;

echo "== Scenario: large database ==\n";
echo 'INFO  memory_limit=' . ini_get( 'memory_limit' ) . "\n";

$start      = microtime( true );
$now        = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$first_post = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 1;

for ( $done = 0; $done < $posts_wanted; $done += 1000 ) {
	$values = array();
	for ( $i = 0; $i < 1000; $i++ ) {
		$n        = $done + $i;
		$values[] = $wpdb->prepare( '(%d, 1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $first_post + $n, $now, $now, ( 0 === $n % 4001 ? 'Old link http://old-site.test/page-' . $n . ' ' : '' ) . str_repeat( 'x', $n % 300 ), 'Bulk ' . $n, '', 'publish', 'bulk-' . $n, $now, $now, '', 'post' );
	}
	$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_modified, post_modified_gmt, to_ping, post_type) VALUES " . implode( ',', $values ) );
}

$orders_table = Woo_Storage::hpos_table();
$first_order  = $first_post + $posts_wanted;
for ( $done = 0; $done < $orders_wanted; $done += 1000 ) {
	$values = array();
	for ( $i = 0; $i < 1000; $i++ ) {
		$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s)', $first_order + $done + $i, 'wc-completed', 'shop_order', 'USD', $now, $now );
	}
	$wpdb->query( "INSERT INTO {$orders_table} (id, status, type, currency, date_created_gmt, date_updated_gmt) VALUES " . implode( ',', $values ) );
}

echo 'INFO  Inserted ' . $posts_wanted . ' posts and ' . $orders_wanted . ' orders in ' . round( microtime( true ) - $start, 1 ) . " s\n";

// Seeding is done. From here on the real shared-hosting limit applies
// (WP-CLI raises memory_limit to unlimited, so set it explicitly).
ini_set( 'memory_limit', '128M' );
echo 'INFO  memory_limit now ' . ini_get( 'memory_limit' ) . ', in use ' . round( memory_get_usage() / 1048576, 1 ) . " MB before capture\n";
$GLOBALS['umin_t_step_peak'] = 0;
$start = microtime( true );
$snapshot   = umin_t_capture( 'Large site', 8.0 );
$capture_s  = microtime( true ) - $start;
$snap       = umin_t_snapshot( $snapshot );

echo 'INFO  Capture took ' . round( $capture_s, 1 ) . ' s; most extra memory any single step needed: ' . round( $GLOBALS['umin_t_step_peak'] / 1048576, 2 ) . " MB\n";
$capture_step_peak = $GLOBALS['umin_t_step_peak'];
umin_t_check( 'Snapshot holds every post', $snap['header']['types']['post:post']['count'] >= $posts_wanted );
umin_t_same( 'Snapshot holds every order', $orders_wanted, $snap['header']['types']['wc_order']['count'] );

$size = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(LENGTH(payload)) FROM %i WHERE snapshot_id = %d', $wpdb->prefix . 'umin_snapshot_chunks', $snapshot ) );
echo 'INFO  Stored snapshot record data: ' . round( $size / 1048576, 2 ) . " MB\n";

// Scattered losses.
$lost = array();
foreach ( array( 0, 1, 997, 5000, 49999, 50000, 99998, 123456, 150001, 199999 ) as $index ) {
	$lost[] = $first_post + $index;
}
for ( $i = 0; $i < 27; $i++ ) {
	$lost[] = $first_post + 1000 + $i * 7001;
}
$lost = array_values( array_unique( $lost ) );
sort( $lost );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $lost ) . ')' );

$lost_orders = array( $first_order, $first_order + 1, $first_order + 4999, $first_order + 10000, $first_order + 19999 );
for ( $i = 0; $i < 6; $i++ ) {
	$lost_orders[] = $first_order + 300 + $i * 2900;
}
sort( $lost_orders );
$wpdb->query( "DELETE FROM {$orders_table} WHERE id IN (" . implode( ',', $lost_orders ) . ')' );

$GLOBALS['umin_t_step_peak'] = 0;
$start      = microtime( true );
$comparison = umin_t_compare( $snapshot, 8.0 );
echo 'INFO  Comparison took ' . round( microtime( true ) - $start, 1 ) . ' s; most extra memory any single step needed: ' . round( $GLOBALS['umin_t_step_peak'] / 1048576, 2 ) . " MB\n";
$compare_step_peak = $GLOBALS['umin_t_step_peak'];

umin_t_same( 'Large site: exactly the ' . count( $lost ) . ' scattered missing posts', $lost, umin_t_ids( $comparison, 'post:post', Comparator::MISSING ) );
umin_t_same( 'Large site: exactly the ' . count( $lost_orders ) . ' scattered missing orders', $lost_orders, umin_t_ids( $comparison, 'wc_order', Comparator::MISSING ) );
umin_t_check( 'Capture and comparison ran inside a 128 MB memory_limit', '128M' === ini_get( 'memory_limit' ) );
umin_t_check( 'No capture step needed more than 16 MB extra', $capture_step_peak < 16 * 1048576, round( $capture_step_peak / 1048576, 2 ) . ' MB' );
umin_t_check( 'No comparison step needed more than 16 MB extra', $compare_step_peak < 16 * 1048576, round( $compare_step_peak / 1048576, 2 ) . ' MB' );
// --- Scanner on the same large site -------------------------------------
update_option( 'home', 'https://new-big.example.com' );
update_option( 'siteurl', 'https://new-big.example.com' );
update_option( 'umin_settings', array_merge( UnstoppableSolutions\MigrationInspector\Core\Settings::all(), array( 'previous_domains' => array( 'http://old-site.test' ) ) ) );
$planted = array();
for ( $n = 0; $n < $posts_wanted; $n += 4001 ) {
	// Some planted posts may be among the ones deleted above.
	if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $first_post + $n ) ) ) {
		$planted[] = (string) ( $first_post + $n );
	}
}
$GLOBALS['umin_t_step_peak'] = 0;
$start   = microtime( true );
$scan_id = umin_t_scan( 8.0 );
$scan_s  = microtime( true ) - $start;
$scan_peak = $GLOBALS['umin_t_step_peak'];
echo 'INFO  Scan took ' . round( $scan_s, 1 ) . ' s; most extra memory any single step needed: ' . round( $scan_peak / 1048576, 2 ) . " MB\n";
umin_t_subset( 'Large site scan: every planted old-domain post is reported (' . count( $planted ) . ')', $planted, umin_t_finding_ids( $scan_id, 'old_urls_posts' ) );
umin_t_check( 'No scan step needed more than 16 MB extra', $scan_peak < 16 * 1048576, round( $scan_peak / 1048576, 2 ) . ' MB' );

echo 'INFO  Process peak memory ' . round( memory_get_peak_usage( true ) / 1048576, 1 ) . " MB (includes WordPress, WooCommerce and WP-CLI)\n";

umin_t_done();
