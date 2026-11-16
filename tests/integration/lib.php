<?php
/**
 * Helpers for integration scenarios run with `wp eval-file`.
 *
 * These run inside a real WordPress install with the plugin active. They
 * create test content, drive snapshot and comparison jobs exactly as the
 * REST API does (through Job_Runner with a time budget), and report
 * PASS/FAIL lines that tests/integration/run.sh collects.
 *
 * Never run these against a site you care about: scenarios create and
 * delete content.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped (excluded by .distignore and phpcs.xml.dist).

use UnstoppableSolutions\MigrationInspector\Core\Schema;
use UnstoppableSolutions\MigrationInspector\Jobs\Deadline;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparison_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Service;

$GLOBALS['umin_t'] = array( 'pass' => 0, 'fail' => 0 );

function umin_t_check( $name, $condition, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['umin_t']['pass']++;
		echo 'PASS  ' . $name . "\n";
	} else {
		$GLOBALS['umin_t']['fail']++;
		echo 'FAIL  ' . $name . "\n";
		if ( '' !== $detail ) {
			echo '      ' . $detail . "\n";
		}
	}
}

function umin_t_same( $name, $expected, $actual ) {
	if ( is_array( $expected ) && is_array( $actual ) && array_values( $expected ) === $expected ) {
		sort( $expected );
		sort( $actual );
	}
	umin_t_check( $name, $expected === $actual, 'expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) );
}

function umin_t_subset( $name, array $needles, array $haystack ) {
	$missing = array_values( array_diff( $needles, $haystack ) );
	umin_t_check( $name, empty( $missing ), 'not found: ' . wp_json_encode( $missing ) );
}

function umin_t_done() {
	echo 'RESULT ' . $GLOBALS['umin_t']['pass'] . ' passed, ' . $GLOBALS['umin_t']['fail'] . " failed\n";
}

/**
 * Drive a job to a final status, a time budget per call like the REST route.
 */
function umin_t_run_job( $job_id, $budget = 0.5, $max_calls = 100000 ) {
	$runner = Snapshot_Service::runner();
	$calls  = 0;

	if ( ! isset( $GLOBALS['umin_t_step_peak'] ) ) {
		$GLOBALS['umin_t_step_peak'] = 0;
	}

	do {
		// Each call is what one REST request does. Track the extra memory a
		// single call needs above what was already in use.
		$before = memory_get_usage();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$result = $runner->run( $job_id, new Deadline( $budget ) );
		$GLOBALS['umin_t_step_peak'] = max( $GLOBALS['umin_t_step_peak'], memory_get_peak_usage() - $before );
		$calls++;
	} while ( Job_Store::STATUS_RUNNING === $result['status'] && $calls < $max_calls );

	return array( 'status' => $result['status'], 'calls' => $calls, 'message' => $result['message'] );
}

function umin_t_capture( $label, $budget = 0.5 ) {
	$started = Snapshot_Service::start_capture( $label );
	if ( is_wp_error( $started ) ) {
		umin_t_check( 'start capture', false, $started->get_error_message() );
		return 0;
	}
	$run = umin_t_run_job( $started['job_id'], $budget );
	umin_t_check( "Capture '{$label}' completes", 'completed' === $run['status'], $run['status'] . ' ' . $run['message'] );
	return $started['snapshot_id'];
}

function umin_t_compare( $snapshot_id, $budget = 0.5 ) {
	$started = Snapshot_Service::start_compare( $snapshot_id );
	if ( is_wp_error( $started ) ) {
		umin_t_check( 'start compare', false, $started->get_error_message() );
		return 0;
	}
	$run = umin_t_run_job( $started['job_id'], $budget );
	umin_t_check( "Comparison of snapshot {$snapshot_id} completes", 'completed' === $run['status'], $run['status'] . ' ' . $run['message'] );
	return $started['comparison_id'];
}

/**
 * Every ID in one bucket of one type.
 */
function umin_t_ids( $comparison_id, $type, $kind ) {
	$repo = new Comparison_Repository();
	$ids  = array();
	$page = 1;
	do {
		$result = $repo->items( $comparison_id, $type, $kind, $page, 500 );
		foreach ( $result['items'] as $item ) {
			$ids[] = $item['id'];
		}
		$page++;
	} while ( count( $result['items'] ) === 500 );
	sort( $ids );
	return $ids;
}

function umin_t_detail( $comparison_id, $type, $kind, $id ) {
	$repo = new Comparison_Repository();
	$page = 1;
	do {
		$result = $repo->items( $comparison_id, $type, $kind, $page, 500 );
		foreach ( $result['items'] as $item ) {
			if ( $item['id'] === $id ) {
				return $item['detail'];
			}
		}
		$page++;
	} while ( count( $result['items'] ) === 500 );
	return null;
}

function umin_t_summary( $comparison_id ) {
	$repo = new Comparison_Repository();
	$row  = $repo->get( $comparison_id );
	return $row ? $row['summary'] : array();
}

function umin_t_snapshot( $snapshot_id ) {
	$repo = new Snapshot_Repository();
	return $repo->get( $snapshot_id );
}

/**
 * (type, seq, first, last, count, sha256) for every chunk, in order.
 */
function umin_t_chunk_signature( $snapshot_id ) {
	$repo = new Snapshot_Repository();
	$rows = $repo->chunk_index( $snapshot_id, 0, 100000 );
	$out  = array();
	foreach ( $rows as $row ) {
		$out[] = $row['record_type'] . '|' . $row['seq'] . '|' . $row['first_id'] . '|' . $row['last_id'] . '|' . $row['row_count'] . '|' . $row['sha256'];
	}
	sort( $out );
	return $out;
}

/**
 * Hash of every row of every non-plugin table, to prove read-only behaviour.
 * Excludes transients and WooCommerce's background-job tables, which
 * WordPress and WooCommerce may touch on their own during any request.
 */
function umin_t_db_fingerprint() {
	global $wpdb;

	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	$own    = Schema::table_names();
	$out    = array();

	foreach ( $tables as $table ) {
		if ( in_array( $table, $own, true ) || false !== strpos( $table, 'actionscheduler' ) || false !== strpos( $table, 'wc_rate_limits' ) || false !== strpos( $table, 'woocommerce_sessions' ) ) {
			continue;
		}

		if ( $table === $wpdb->options ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ('cron') ORDER BY option_name", $table, '\_transient%', '\_site\_transient%', 'action_scheduler%' ), ARRAY_N );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_N );
		}

		$out[ $table ] = md5( serialize( $rows ) );
	}

	ksort( $out );
	return $out;
}

function umin_t_count_posts( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $type ) );
}

/**
 * Seed core content. Every human-readable value is a marker string the
 * privacy test later searches for in exports.
 */
function umin_t_seed_core() {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$ids = array( 'posts' => array(), 'pages' => array(), 'attachments' => array(), 'users' => array(), 'comments' => array(), 'categories' => array(), 'tags' => array() );

	for ( $i = 1; $i <= 8; $i++ ) {
		$term                = wp_insert_term( 'Secret Category ' . $i, 'category' );
		$ids['categories'][] = (int) $term['term_taxonomy_id'];
		$tag                 = wp_insert_term( 'Secret Tag ' . $i, 'post_tag' );
		$ids['tags'][]       = (int) $tag['term_taxonomy_id'];
	}

	for ( $i = 1; $i <= 30; $i++ ) {
		$ids['posts'][] = wp_insert_post(
			array(
				'post_title'   => 'Secret Title Alpha ' . $i,
				'post_content' => str_repeat( 'Confidential body text ' . $i . '. ', 40 ),
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);
	}

	for ( $i = 1; $i <= 10; $i++ ) {
		$ids['pages'][] = wp_insert_post(
			array(
				'post_title'   => 'Secret Page Beta ' . $i,
				'post_content' => 'Private page content ' . $i,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	}

	for ( $i = 1; $i <= 15; $i++ ) {
		$ids['attachments'][] = wp_insert_attachment(
			array(
				'post_title'     => 'Secret Photo Gamma ' . $i,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			'2026/11/secret-photo-' . $i . '.jpg'
		);
	}

	$roles = array( 'subscriber', 'author', 'editor', 'contributor' );
	for ( $i = 1; $i <= 12; $i++ ) {
		$ids['users'][] = wp_insert_user(
			array(
				'user_login'   => 'tester_login_' . $i,
				'user_email'   => 'quentin' . $i . '@private-example.test',
				'user_pass'    => wp_generate_password(),
				'display_name' => 'Quentin Displayname ' . $i,
				'first_name'   => 'Quentin',
				'last_name'    => 'Surname' . $i,
				'role'         => $roles[ $i % 4 ],
			)
		);
	}

	$host_page = $ids['pages'][0];
	for ( $i = 1; $i <= 20; $i++ ) {
		$ids['comments'][] = wp_insert_comment(
			array(
				'comment_post_ID'      => $host_page,
				'comment_author'       => 'Commenter Personname ' . $i,
				'comment_author_email' => 'commenter' . $i . '@private-example.test',
				'comment_author_IP'    => '203.0.113.' . $i,
				'comment_content'      => 'Private comment text ' . $i,
				'comment_approved'     => 1,
			)
		);
	}

	return $ids;
}

/**
 * Seed WooCommerce products, orders and refunds with marker customer data.
 */
function umin_t_seed_woo( $orders = 40 ) {
	$out = array( 'products' => array(), 'orders' => array(), 'refunds' => array() );

	for ( $i = 1; $i <= 10; $i++ ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Secret Product Delta ' . $i );
		$product->set_regular_price( (string) ( 10 + $i ) );
		$product->set_status( 'publish' );
		$out['products'][] = $product->save();
	}

	for ( $i = 1; $i <= $orders; $i++ ) {
		$order = wc_create_order( array( 'status' => 'processing' ) );
		$order->set_billing_first_name( 'Zelda' );
		$order->set_billing_last_name( 'Testcustomer' . $i );
		$order->set_billing_email( 'zelda' . $i . '@private-example.test' );
		$order->set_billing_phone( '+1-555-0100' );
		$order->set_billing_address_1( '742 Secret Street' );
		$order->add_product( wc_get_product( $out['products'][ $i % 10 ] ), 1 );
		$order->calculate_totals();
		$order->save();
		$out['orders'][] = $order->get_id();
	}

	foreach ( array_slice( $out['orders'], 0, 3 ) as $order_id ) {
		$refund           = wc_create_refund( array( 'order_id' => $order_id, 'amount' => 1, 'reason' => 'Private refund reason' ) );
		$out['refunds'][] = $refund->get_id();
	}

	return $out;
}
