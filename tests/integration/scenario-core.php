<?php
/**
 * Core snapshot scenario: capture, mutate, compare, verify exact IDs.
 *
 * Usage: wp eval-file tests/integration/scenario-core.php <export-file>
 * Expects WooCommerce active. Works in HPOS or legacy mode; the mode is
 * whatever the site is set to when the scenario starts.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Snapshots\Capture_Handler;
use UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Service;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Transfer;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Validation_Exception;
use UnstoppableSolutions\MigrationInspector\Snapshots\Woo_Storage;

require __DIR__ . '/lib.php';

$export_file = isset( $args[0] ) ? $args[0] : sys_get_temp_dir() . '/umin-export.json';

wp_set_current_user( 1 );

$storage = Woo_Storage::detect();
echo "== Scenario: core (order storage: {$storage['mode']}) ==\n";

$core = umin_t_seed_core();
$woo  = umin_t_seed_woo( 40 );

// ---------------------------------------------------------------------
// Capture, proving it only reads site tables.
// ---------------------------------------------------------------------
$before_capture = umin_t_db_fingerprint();
$snapshot_id    = umin_t_capture( 'Before move' );
$after_capture  = umin_t_db_fingerprint();
umin_t_same( 'Capture changes no site table', $before_capture, $after_capture );

$snapshot = umin_t_snapshot( $snapshot_id );
$types    = $snapshot['header']['types'];

foreach ( array( 'post:post', 'post:page', 'post:attachment', 'post:product', 'user', 'comment', 'term:category', 'term:post_tag', 'wc_order', 'wc_refund' ) as $key ) {
	umin_t_check( "Snapshot tracks {$key}", isset( $types[ $key ] ) );
}
umin_t_same( 'Snapshot post count matches database', umin_t_count_posts( 'post' ), $types['post:post']['count'] );
umin_t_same( 'Snapshot attachment count matches database', umin_t_count_posts( 'attachment' ), $types['post:attachment']['count'] );
umin_t_same( 'Snapshot order count matches seeded orders', 40, $types['wc_order']['count'] );
umin_t_same( 'Snapshot refund count matches seeded refunds', 3, $types['wc_refund']['count'] );
umin_t_same( 'Snapshot records which order storage it read', $storage['mode'], $snapshot['header']['order_mode'] );
umin_t_check( 'Order posts are not double-counted as ordinary posts', ! isset( $types['post:shop_order'] ) && ! isset( $types['post:shop_order_placehold'] ) );

// ---------------------------------------------------------------------
// Export, and check it for personal data.
// ---------------------------------------------------------------------
$handle   = fopen( $export_file, 'wb' );
$transfer = new Snapshot_Transfer();
$transfer->export( $snapshot_id, function ( $piece ) use ( $handle ) {
	fwrite( $handle, $piece );
} );
fclose( $handle );
$export = file_get_contents( $export_file );

umin_t_check( 'Export is valid JSON', is_array( json_decode( $export, true ) ) );
$markers = array( get_option( 'admin_email' ), 'private-example.test', 'tester_login', 'Quentin', 'Displayname', 'Secret Title', 'Secret Page', 'Secret Photo', 'Secret Product', 'Secret Category', 'Secret Tag', 'Confidential body', 'Zelda', 'Testcustomer', '742 Secret', '555-0100', 'Commenter Personname', '203.0.113', 'Private refund', 'secret-photo' );
foreach ( $markers as $marker ) {
	umin_t_check( "Export contains no personal or content data: '{$marker}'", false === stripos( $export, $marker ) );
}
umin_t_check( 'Export contains no email addresses at all', ! preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $export ) );

// ---------------------------------------------------------------------
// Interrupted capture gives the same chunks as an uninterrupted one.
// ---------------------------------------------------------------------
$runner   = Snapshot_Service::runner();
$store    = new Job_Store();
$started  = Snapshot_Service::start_capture( 'Interrupted' );
$job_id   = $started['job_id'];

// A few single steps, then a "crash" while holding the lease.
for ( $i = 0; $i < 25; $i++ ) {
	$runner->run( $job_id, new UnstoppableSolutions\MigrationInspector\Jobs\Deadline( 0 ) );
}
$token = $store->claim( $job_id );
umin_t_check( 'Lease can be claimed by a request', '' !== $token );
$busy = $runner->run( $job_id, new UnstoppableSolutions\MigrationInspector\Jobs\Deadline( 0 ) );
umin_t_same( 'A second request is refused while the lease is held', 'busy', $busy['status'] );
umin_t_same( 'A held lease shows as running, not paused', 'running', $store->display_status( $store->get( $job_id ) ) );

// The crashed request also repeats its last step: run it directly twice.
$job     = $store->get( $job_id );
$handler = new Capture_Handler();
$handler->step( $job['params'], $job['state'] );
$handler->step( $job['params'], $job['state'] );

// Lease expires.
global $wpdb;
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_expires = %d WHERE id = %d', $wpdb->prefix . 'umin_jobs', time() - 1, $job_id ) );
umin_t_same( 'An expired lease shows as paused', 'paused', $store->display_status( $store->get( $job_id ) ) );

$resumed = umin_t_run_job( $job_id );
umin_t_same( 'Interrupted capture resumes to completion', 'completed', $resumed['status'] );
umin_t_check( 'The crashed request\'s stale lease can no longer save state', ! $store->save_state( $job_id, $token, array(), array() ) );

$clean_id = umin_t_capture( 'Uninterrupted' );
umin_t_same( 'Interrupted and uninterrupted captures are identical chunk by chunk', umin_t_chunk_signature( $clean_id ), umin_t_chunk_signature( $started['snapshot_id'] ) );

// Cancel.
$cancel = Snapshot_Service::start_capture( 'Cancelled' );
$runner->run( $cancel['job_id'], new UnstoppableSolutions\MigrationInspector\Jobs\Deadline( 0 ) );
umin_t_check( 'A running capture can be cancelled', $runner->cancel( $cancel['job_id'] ) );
umin_t_same( 'A cancelled job does not run again', 'cancelled', $runner->run( $cancel['job_id'], new UnstoppableSolutions\MigrationInspector\Jobs\Deadline( 1 ) )['status'] );
umin_t_same( 'A cancelled capture is marked failed, never usable', 'failed', umin_t_snapshot( $cancel['snapshot_id'] )['status'] );
$refused = Snapshot_Service::start_compare( $cancel['snapshot_id'] );
umin_t_check( 'An incomplete snapshot cannot be compared', is_wp_error( $refused ) );

// ---------------------------------------------------------------------
// Mutate the site after the snapshot.
// ---------------------------------------------------------------------
sleep( 2 ); // Records created from now on are clearly newer than the snapshot.
require_once ABSPATH . 'wp-admin/includes/user.php';

$deleted_posts       = array( $core['posts'][2], $core['posts'][9], $core['posts'][17] );
$deleted_pages       = array( $core['pages'][4], $core['pages'][7] );
$deleted_attachments = array( $core['attachments'][0], $core['attachments'][11] );
$deleted_users       = array( $core['users'][3], $core['users'][8] );
$deleted_comments    = array( $core['comments'][1], $core['comments'][5], $core['comments'][19] );
$deleted_category    = $core['categories'][6];
$deleted_orders      = array( $woo['orders'][5], $woo['orders'][12], $woo['orders'][20], $woo['orders'][33] );
$deleted_refunds     = array( $woo['refunds'][1] );

// WooCommerce deletes an order's notes (stored as comments) with the
// order, so those comments are expected to be missing too.
global $wpdb;
$order_notes = array_map(
	'intval',
	$wpdb->get_col(
		"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN (" . implode( ',', array_map( 'intval', array_merge( $deleted_orders, $deleted_refunds ) ) ) . ')'
	)
);
$expected_missing_comments = array_merge( $deleted_comments, $order_notes );

foreach ( array_merge( $deleted_posts, $deleted_pages, $deleted_attachments ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $deleted_users as $id ) {
	wp_delete_user( $id );
}
foreach ( $deleted_comments as $id ) {
	wp_delete_comment( $id, true );
}
$term = get_term_by( 'term_taxonomy_id', $deleted_category );
wp_delete_term( $term->term_id, 'category' );
foreach ( array_merge( $deleted_orders, $deleted_refunds ) as $id ) {
	wc_get_order( $id )->delete( true );
}

// Changes.
$status_post    = $core['posts'][0];
$truncated_post = $core['posts'][1];
wp_update_post( array( 'ID' => $status_post, 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $truncated_post, 'post_content' => 'Cut off' ) );
$role_user = $core['users'][0];
( new WP_User( $role_user ) )->set_role( 'editor' === ( new WP_User( $role_user ) )->roles[0] ? 'author' : 'editor' );
wp_set_comment_status( $core['comments'][2], 'hold' );
$status_order = $woo['orders'][0];
wc_get_order( $status_order )->update_status( 'completed' );

// Additions.
$new_posts = array(
	wp_insert_post( array( 'post_title' => 'Added later 1', 'post_status' => 'publish' ) ),
	wp_insert_post( array( 'post_title' => 'Added later 2', 'post_status' => 'publish' ) ),
);
$backdated_post = wp_insert_post( array( 'post_title' => 'Backdated', 'post_status' => 'publish', 'post_date_gmt' => '2020-01-01 00:00:00', 'post_date' => '2020-01-01 00:00:00' ) );
$new_order      = wc_create_order( array( 'status' => 'pending' ) );
$new_order->save();
$backdated_order = wc_create_order( array( 'status' => 'pending' ) );
$backdated_order->set_date_created( '2020-01-01T00:00:00+00:00' );
$backdated_order->save();

// ---------------------------------------------------------------------
// Compare, proving it only reads site tables, and check exact IDs.
// ---------------------------------------------------------------------
$before_compare = umin_t_db_fingerprint();
$comparison_id  = umin_t_compare( $snapshot_id );
umin_t_same( 'Comparison changes no site table', $before_compare, umin_t_db_fingerprint() );

$missing = function ( $type ) use ( $comparison_id ) {
	return umin_t_ids( $comparison_id, $type, Comparator::MISSING );
};

umin_t_same( 'Missing posts: exactly the deleted IDs', $deleted_posts, $missing( 'post:post' ) );
umin_t_same( 'Missing pages: exactly the deleted IDs', $deleted_pages, $missing( 'post:page' ) );
umin_t_same( 'Missing attachments: exactly the deleted IDs', $deleted_attachments, $missing( 'post:attachment' ) );
umin_t_same( 'Missing users: exactly the deleted IDs', $deleted_users, $missing( 'user' ) );
umin_t_same( 'Missing comments: exactly the deleted ones plus the deleted orders\' notes', $expected_missing_comments, $missing( 'comment' ) );
umin_t_check( 'Deleted orders had notes to account for', count( $order_notes ) > 0 );
umin_t_same( 'Missing categories: exactly the deleted term', array( $deleted_category ), $missing( 'term:category' ) );
umin_t_same( "Missing WooCommerce orders ({$storage['mode']}): exactly the deleted IDs", $deleted_orders, $missing( 'wc_order' ) );
umin_t_same( "Missing WooCommerce refunds ({$storage['mode']}): exactly the deleted ID", $deleted_refunds, $missing( 'wc_refund' ) );
umin_t_same( 'No products reported missing', array(), $missing( 'post:product' ) );

$changed_posts = umin_t_ids( $comparison_id, 'post:post', Comparator::CHANGED );
umin_t_subset( 'Changed posts include the status change and the truncation', array( $status_post, $truncated_post ), $changed_posts );
umin_t_check( 'Deleted posts are never reported as changed', empty( array_intersect( $deleted_posts, $changed_posts ) ) );
$status_detail = umin_t_detail( $comparison_id, 'post:post', Comparator::CHANGED, $status_post );
umin_t_same( 'Status change detail: publish to draft', array( 'publish', 'draft' ), $status_detail['status'] );
$trunc_detail = umin_t_detail( $comparison_id, 'post:post', Comparator::CHANGED, $truncated_post );
umin_t_check( 'Truncated content is flagged as shrunk', ! empty( $trunc_detail['shrank'] ) && $trunc_detail['length'][1] < $trunc_detail['length'][0] );
umin_t_subset( 'Changed users include the role change', array( $role_user ), umin_t_ids( $comparison_id, 'user', Comparator::CHANGED ) );
umin_t_subset( 'Changed comments include the unapproved comment', array( $core['comments'][2] ), umin_t_ids( $comparison_id, 'comment', Comparator::CHANGED ) );
umin_t_subset( 'Changed orders include the status change', array( $status_order ), umin_t_ids( $comparison_id, 'wc_order', Comparator::CHANGED ) );
$order_detail = umin_t_detail( $comparison_id, 'wc_order', Comparator::CHANGED, $status_order );
umin_t_same( 'Order status change detail', array( 'wc-processing', 'wc-completed' ), $order_detail['status'] );

umin_t_same( 'New posts: exactly the ones created after the snapshot', $new_posts, umin_t_ids( $comparison_id, 'post:post', Comparator::ADDED ) );
umin_t_same( 'Backdated post is "not in snapshot", not "new"', array( $backdated_post ), umin_t_ids( $comparison_id, 'post:post', Comparator::EXTRA ) );
umin_t_same( 'New orders: exactly the one created after the snapshot', array( $new_order->get_id() ), umin_t_ids( $comparison_id, 'wc_order', Comparator::ADDED ) );
umin_t_same( 'Backdated order is "not in snapshot"', array( $backdated_order->get_id() ), umin_t_ids( $comparison_id, 'wc_order', Comparator::EXTRA ) );

// Totals are consistent.
$summary = umin_t_summary( $comparison_id );
foreach ( $summary['types'] as $key => $t ) {
	umin_t_same( "Totals add up for {$key} (snapshot side)", $t['in_snapshot'], $t['missing'] + $t['changed'] + $t['unchanged'] );
	umin_t_same( "Totals add up for {$key} (site side)", $t['on_site'], $t['changed'] + $t['unchanged'] + $t['new'] + $t['extra'] );
	umin_t_same( "In-snapshot total matches snapshot for {$key}", $types[ $key ]['count'], $t['in_snapshot'] );
}
umin_t_same( 'Live post count matches comparison', umin_t_count_posts( 'post' ), $summary['types']['post:post']['on_site'] );
umin_t_same( 'Order storage recorded for both sides', array( 'snapshot' => $storage['mode'], 'site' => $storage['mode'] ), $summary['order_storage'] );

// ---------------------------------------------------------------------
// Interrupted comparison gives the same totals as an uninterrupted one.
// ---------------------------------------------------------------------
$cmp     = Snapshot_Service::start_compare( $snapshot_id );
for ( $i = 0; $i < 20; $i++ ) {
	$runner->run( $cmp['job_id'], new UnstoppableSolutions\MigrationInspector\Jobs\Deadline( 0 ) );
}
$job     = $store->get( $cmp['job_id'] );
$handler = new Compare_Handler();
$handler->step( $job['params'], $job['state'] ); // Repeated step after a "crash".
$handler->step( $job['params'], $job['state'] );
umin_t_same( 'Interrupted comparison resumes to completion', 'completed', umin_t_run_job( $cmp['job_id'] )['status'] );
umin_t_same( 'Interrupted and uninterrupted comparisons agree', $summary['types'], umin_t_summary( $cmp['comparison_id'] )['types'] );
foreach ( array( 'post:post', 'wc_order', 'comment' ) as $key ) {
	umin_t_same( "Interrupted comparison lists the same missing {$key}", $missing( $key ), umin_t_ids( $cmp['comparison_id'], $key, Comparator::MISSING ) );
}

// ---------------------------------------------------------------------
// Import: round trip, and rejection of bad files.
// ---------------------------------------------------------------------
$imported     = $transfer->import( $export );
$imported_cmp = umin_t_compare( $imported );
$original     = $summary['types'];
$roundtrip    = umin_t_summary( $imported_cmp )['types'];
ksort( $original );
ksort( $roundtrip );
umin_t_same( 'Imported snapshot compares identically to the original', $original, $roundtrip );

$reject = function ( $name, $json ) use ( $transfer ) {
	try {
		$transfer->import( $json );
		umin_t_check( $name, false, 'was accepted' );
	} catch ( Snapshot_Validation_Exception $e ) {
		umin_t_check( $name, true );
	}
};
$doc = json_decode( $export, true );

// Corrupt bytes inside the compressed data (not trailing padding, which
// zlib ignores and which leaves the decoded content unchanged).
foreach ( $doc['chunks'] as $index => $chunk ) {
	$raw     = base64_decode( $chunk['payload'] );
	$mid     = (int) floor( strlen( $raw ) / 2 );
	$raw[ $mid ] = chr( ord( $raw[ $mid ] ) ^ 0x55 );
	$tampered = $doc;
	$tampered['chunks'][ $index ]['payload'] = base64_encode( $raw );
	$reject( "Import refuses a file with a damaged record list ({$chunk['type']})", wp_json_encode( $tampered ) );
}

$tampered = $doc;
$tampered['snapshot']['header']['types']['wc_order']['count'] += 1;
$reject( 'Import refuses a file whose totals were edited', wp_json_encode( $tampered ) );

$tampered = $doc;
$tampered['snapshot']['header']['config']['admin_email'] = 'x@example.com';
$reject( 'Import refuses a file with unexpected fields', wp_json_encode( $tampered ) );

$reject( 'Import refuses malformed JSON', substr( $export, 0, 200 ) );
$reject( 'Import refuses a non-snapshot JSON file', wp_json_encode( array( 'hello' => 'world' ) ) );

add_filter( 'umin_max_snapshot_import_bytes', function () {
	return 2048;
} );
$reject( 'Import refuses a file over the size limit', $export );

umin_t_done();
