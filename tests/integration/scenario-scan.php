<?php
/**
 * Integration scenario: the read-only migration scanner.
 *
 * clean:   a freshly installed store. The scan must finish with no critical
 *          or warning findings (notices allowed) and change nothing.
 * damaged: the same kind of store "migrated" from http://old-site.test to
 *          https://new-shop.example.com with problems planted on purpose. The scan
 *          must report each planted problem on exactly the planted objects,
 *          not report the decoys, change nothing, and give the same result
 *          when interrupted and resumed.
 *
 * Usage: wp eval-file scenario-scan.php <clean|damaged>
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

require __DIR__ . '/lib.php';

use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;
use UnstoppableSolutions\MigrationInspector\Jobs\Deadline;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Registry;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Service;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

global $wpdb;

$phase = isset( $args[0] ) ? $args[0] : 'clean';

if ( 'clean' === $phase ) {
	echo "== Scenario: scan of a clean store ==\n";

	update_option( 'woocommerce_coming_soon', 'no' );
	$before  = umin_t_db_fingerprint();
	$scan_id = umin_t_scan();
	$after   = umin_t_db_fingerprint();
	$summary = umin_t_scan_summary( $scan_id );

	umin_t_check( 'Scanning changed no site table', $before === $after, wp_json_encode( array_keys( array_diff_assoc( $before, $after ) ) ) );

	$bad = array();
	foreach ( $summary['checks'] as $key => $check ) {
		if ( ! empty( $check['counts'][ Severity::CRITICAL ] ) || ! empty( $check['counts'][ Severity::WARNING ] ) || 'error' === $check['status'] ) {
			$bad[] = $key;
		}
	}
	umin_t_same( 'Clean store: no critical, warning or failed checks', array(), $bad );
	umin_t_check( 'Clean store: overall status is not critical or warning', in_array( $summary['overall'], array( Health_Summary::STATUS_PASSED, Health_Summary::STATUS_NOT_TESTED ), true ), (string) $summary['overall'] );
	umin_t_same( 'Clean store: WooCommerce checks ran', 'passed', $summary['checks']['woo_pages']['status'] );
	umin_t_check( 'Clean store: dashboard summary uses the scan', null !== Health_Summary::summary()['last_scan'] );

	umin_t_done();
	return;
}

echo "== Scenario: scan of a damaged migration ==\n";

require_once ABSPATH . 'wp-admin/includes/image.php';

$uploads = wp_upload_dir( '2020/01' );
wp_mkdir_p( $uploads['path'] );
$dir = trailingslashit( $uploads['path'] );

// --- Content created on the old site -------------------------------------

$post = function ( $content, $type = 'post' ) {
	return (int) wp_insert_post(
		array(
			'post_title'   => 'Scan fixture',
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => $type,
		)
	);
};

$old_link      = $post( '<p>See <a href="http://old-site.test/about/">about us</a> and <img src="http://old-site.test/wp-content/uploads/2020/01/a.jpg"></p>' );
$staging_link  = $post( '<p>Draft link: <a href="https://staging.new-shop.example.com/pricing/">pricing</a></p>' );
$decoy_lookalk = $post( '<p>Partners: <a href="https://old-site.test.au/">AU</a>, <a href="https://bold-site.com/">Bold</a>, <a href="https://old-site.testing.org/">x</a></p>' );
$insecure_self = $post( '<p><img src="http://new-shop.example.com/wp-content/uploads/2020/01/present.jpg"></p>' );
$mixed         = $post( '<p><img src="http://cdn.example.org/pic.jpg"> <a href="http://plain-link.example.org/">a plain link is fine</a></p>' );
$https_only    = $post( '<p><img src="https://cdn.example.org/pic.jpg"></p>' );
$broken_media  = $post( '<p><img src="https://new-shop.example.com/wp-content/uploads/2020/01/present.jpg"><img src="https://new-shop.example.com/wp-content/uploads/2020/01/gone.jpg"><a href="/wp-content/uploads/2020/01/also-gone.pdf">pdf</a></p>' );
$meta_holder   = $post( '<p>Builder page</p>', 'page' );

// JSON-escaped old URL in page-builder style meta, and a decoy meta.
update_post_meta( $meta_holder, '_builder_data', wp_slash( wp_json_encode( array( 'image' => array( 'url' => 'http://old-site.test/wp-content/uploads/2020/01/hero.jpg' ) ) ) ) );
update_post_meta( $meta_holder, '_unrelated', 'nothing to see' );
$meta_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_builder_data'", $meta_holder ) );

// Serialized widget option with the old address.
update_option( 'widget_umin_test', array( 2 => array( 'url' => 'http://old-site.test/contact/' ) ) );
$option_id = (int) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'widget_umin_test'" );

// --- Media -----------------------------------------------------------------

file_put_contents( $dir . 'present.jpg', 'x' );
file_put_contents( $dir . 'present-150x150.jpg', 'x' );
file_put_contents( $dir . 'Photo.JPG', 'x' );
file_put_contents( $dir . 'nosize.jpg', 'x' );

$attach = function ( $file, array $meta = array() ) {
	$id = (int) wp_insert_post(
		array(
			'post_title'     => 'Scan media',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
			'guid'           => 'http://old-site.test/wp-content/uploads/' . ltrim( $file, '/' ),
		)
	);
	if ( '' !== $file ) {
		update_post_meta( $id, '_wp_attached_file', $file );
	}
	if ( ! empty( $meta ) ) {
		update_post_meta( $id, '_wp_attachment_metadata', $meta );
	}
	return $id;
};

$media_ok       = $attach( '2020/01/present.jpg', array( 'file' => '2020/01/present.jpg', 'sizes' => array( 'thumbnail' => array( 'file' => 'present-150x150.jpg' ) ) ) );
$media_missing  = $attach( '2020/01/never-copied.jpg' );
$media_case     = $attach( '2020/01/photo.jpg' );
$media_absolute = $attach( '/home/olduser/public_html/wp-content/uploads/2020/01/abs.jpg' );
$media_sizes    = $attach( '2020/01/nosize.jpg', array( 'file' => '2020/01/nosize.jpg', 'sizes' => array( 'medium' => array( 'file' => 'nosize-300x300.jpg' ) ) ) );

// --- The move -------------------------------------------------------------

update_option( 'home', 'https://new-shop.example.com' );
update_option( 'siteurl', 'https://new-shop.example.com' );
update_option( 'upload_url_path', 'http://old-site.test/wp-content/uploads' );
update_option( 'blog_public', '0' );
update_option( 'permalink_structure', '/%postname%/' );
delete_option( 'rewrite_rules' );
update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins' ), array( 'ghost-plugin/ghost.php' ) ) );
update_option( 'woocommerce_coming_soon', 'yes' );

$cron = _get_cron_array();
$cron[ time() - 7200 ]['umin_test_overdue_event'][ md5( 'x' ) ] = array( 'schedule' => false, 'args' => array() );
_set_cron_array( $cron );

// WP_CACHE is set to true in wp-config.php by run.sh; no advanced-cache.php exists.

// A leftover staging copy's tables in the same database.
foreach ( array( 'options', 'posts', 'postmeta' ) as $name ) {
	$wpdb->query( "CREATE TABLE wpstg0_{$name} (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id))" );
}
UnstoppableSolutions\MigrationInspector\Scanner\Db_Info::reset();

// WooCommerce: checkout page gone; orders only in the posts table while HPOS is on.
$checkout = (int) get_option( 'woocommerce_checkout_page_id' );
wp_delete_post( $checkout, true );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_orders' );
for ( $i = 0; $i < 3; $i++ ) {
	$wpdb->insert( $wpdb->posts, array( 'post_type' => 'shop_order', 'post_status' => 'wc-processing', 'post_title' => 'Order', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', 1 ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', 1 ) ) );
}
// Products created without WooCommerce's API: no lookup rows.
for ( $i = 0; $i < 12; $i++ ) {
	$wpdb->insert( $wpdb->posts, array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Product ' . $i, 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', 1 ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', 1 ) ) );
}

wp_cache_flush();

// --- Scan -------------------------------------------------------------------

$before  = umin_t_db_fingerprint();
$scan_id = umin_t_scan( 0.5, array( 'request_host' => 'new-shop.example.com', 'request_https' => true ) );
$after   = umin_t_db_fingerprint();
$summary = umin_t_scan_summary( $scan_id );

umin_t_check( 'Scanning changed no site table', $before === $after, wp_json_encode( array_keys( array_diff_assoc( $before, $after ) ) ) );

$locations = wp_list_pluck( $summary['old_locations'], 'source', 'location' );
umin_t_same( 'The old domain is detected from post GUIDs', 'guid', isset( $locations['old-site.test'] ) ? $locations['old-site.test'] : '' );
umin_t_same( 'The staging address is detected from content', 'temporary', isset( $locations['staging.new-shop.example.com'] ) ? $locations['staging.new-shop.example.com'] : '' );

// WordPress's own Sample Page links to the dashboard at the install address.
$sample = get_page_by_path( 'sample-page' );
umin_t_same( 'Old addresses in content: exactly the planted posts and the Sample Page (decoys not reported)', array( (string) $old_link, (string) $staging_link, (string) $sample->ID ), umin_t_finding_ids( $scan_id, 'old_urls_posts' ) );
umin_t_same( 'Old address in JSON-escaped page-builder meta is found', array( (string) $meta_id ), umin_t_finding_ids( $scan_id, 'old_urls_postmeta' ) );
umin_t_subset( 'Old address in a serialized widget option is found', array( (string) $option_id ), umin_t_finding_ids( $scan_id, 'old_urls_options' ) );
umin_t_same( 'http:// links to this HTTPS site: exactly the planted post', array( (string) $insecure_self ), umin_t_finding_ids( $scan_id, 'insecure_urls_posts' ) );
umin_t_same( 'Insecure resources from other hosts (incl. the old domain); plain links and https ignored', array( (string) $old_link, (string) $mixed ), umin_t_finding_ids( $scan_id, 'mixed_content' ) );

umin_t_same( 'Missing media file', array( (string) $media_missing ), umin_t_finding_ids( $scan_id, 'attachment_files', 'missing_file' ) );
umin_t_same( 'Media file with different case', array( (string) $media_case ), umin_t_finding_ids( $scan_id, 'attachment_files', 'case_mismatch' ) );
umin_t_same( 'Media pointing to the old server path', array( (string) $media_absolute ), umin_t_finding_ids( $scan_id, 'attachment_files', 'absolute_path' ) );
umin_t_same( 'Image with a missing generated size', array( (string) $media_sizes ), umin_t_finding_ids( $scan_id, 'attachment_files', 'missing_sizes' ) );
umin_t_check( 'Complete media item not reported', ! in_array( (string) $media_ok, umin_t_finding_ids( $scan_id, 'attachment_files' ), true ) );
umin_t_same( 'Content linking to missing uploads: the planted post and the old-link post', array( (string) $old_link, (string) $broken_media ), umin_t_finding_ids( $scan_id, 'content_media' ) );

$detail = $wpdb->get_var( $wpdb->prepare( 'SELECT context FROM %i WHERE scan_id = %d AND check_key = %s AND object_id = %s', $wpdb->prefix . 'umin_findings', $scan_id, 'content_media', (string) $broken_media ) );
$detail = json_decode( (string) $detail, true );
umin_t_same( 'Missing linked files listed, present file not listed', array( '2020/01/also-gone.pdf', '2020/01/gone.jpg' ), isset( $detail['files'] ) ? $detail['files'] : array() );

umin_t_same( 'Custom upload URL on the old domain', array( 'upload_url_path' ), umin_t_finding_ids( $scan_id, 'uploads_folder', 'custom_path_stale' ) );
umin_t_same( 'Search engines discouraged on the live address', array( 'blog_public' ), umin_t_finding_ids( $scan_id, 'search_visibility', 'discouraged' ) );
umin_t_same( 'Permalink rules missing', array( 'rewrite_rules' ), umin_t_finding_ids( $scan_id, 'permalinks', 'rules_missing' ) );
umin_t_same( 'Active plugin with missing files', array( 'ghost-plugin/ghost.php' ), umin_t_finding_ids( $scan_id, 'theme_plugins', 'plugin_missing' ) );
umin_t_same( 'Overdue scheduled task', array( 'cron' ), umin_t_finding_ids( $scan_id, 'scheduled_tasks', 'cron_overdue' ) );
umin_t_same( 'Page cache switched on without its drop-in', array( 'WP_CACHE' ), umin_t_finding_ids( $scan_id, 'config_constants', 'cache_dropin' ) );
umin_t_same( 'Leftover staging tables noticed', array( 'wpstg0_options' ), umin_t_finding_ids( $scan_id, 'table_prefix', 'other_installs' ) );
umin_t_same( 'Checkout page missing is critical', array( 'woocommerce_checkout_page_id' ), umin_t_finding_ids( $scan_id, 'woo_pages', 'checkout_page' ) );
umin_t_check( 'Orders only in the posts table while HPOS reads its own tables', 1 === count( umin_t_finding_ids( $scan_id, 'woo_order_storage', 'orders_elsewhere' ) ) );
umin_t_check( 'Product lookup data incomplete', 1 === count( umin_t_finding_ids( $scan_id, 'woo_tables', 'lookup_stale' ) ) );
umin_t_check( 'Store in coming-soon mode', 1 === count( umin_t_finding_ids( $scan_id, 'woo_tables', 'coming_soon' ) ) );
umin_t_same( 'Site addresses consistent (no false alarm)', array(), umin_t_finding_ids( $scan_id, 'site_urls' ) );

umin_t_same( 'Overall status is critical', Health_Summary::STATUS_CRITICAL, $summary['overall'] );
umin_t_same( 'WooCommerce category is critical', Health_Summary::STATUS_CRITICAL, $summary['categories']['woocommerce']['status'] );
umin_t_same( 'Media category is a warning', Health_Summary::STATUS_WARNING, $summary['categories']['media']['status'] );
umin_t_same( 'No check failed with an error', array(), array_keys( array_filter( $summary['checks'], function ( $c ) { return 'error' === $c['status']; } ) ) );

// Every finding's kind is defined with an explanation, severity and action.
$checks    = UnstoppableSolutions\MigrationInspector\Scanner\Check_Registry::all();
$undefined = array();
foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT check_key, kind FROM %i WHERE scan_id = %d', $wpdb->prefix . 'umin_findings', $scan_id ), ARRAY_A ) as $row ) {
	$kind = isset( $checks[ $row['check_key'] ] ) ? $checks[ $row['check_key'] ]->kind( $row['kind'] ) : null;
	if ( null === $kind || '' === $kind['explanation'] || '' === $kind['action'] || ! Severity::is_valid( $kind['severity'] ) ) {
		$undefined[] = $row['check_key'] . '|' . $row['kind'];
	}
}
umin_t_same( 'Every finding has an explanation, severity and next action', array(), $undefined );

// --- Prefix change: users' roles under the old prefix -----------------------

$prefix = $wpdb->get_blog_prefix();
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s", 'oldpfx_capabilities', $prefix . 'capabilities' ) );
$prefix_findings = ( new UnstoppableSolutions\MigrationInspector\Scanner\Checks\Prefix_Check() )->run( new UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context( array( 'multisite' => false ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s", $prefix . 'capabilities', 'oldpfx_capabilities' ) );
$kinds = wp_list_pluck( $prefix_findings, 'kind' );
umin_t_check( 'Roles stored under an old table prefix are critical', in_array( 'capabilities_prefix', $kinds, true ) );

// --- Resumability: interrupted scan gives the same findings -----------------

$store   = new Job_Store();
$started = Scan_Service::start( array( 'request_host' => 'new-shop.example.com', 'request_https' => true ) );
$job_id  = $started['job_id'];
$runner  = Job_Registry::runner();

// A few single steps, then a "crash" while holding the lease.
for ( $i = 0; $i < 6; $i++ ) {
	$runner->run( $job_id, new Deadline( 0.0 ) );
}
$token = $store->claim( $job_id );
umin_t_check( 'Scan lease can be claimed mid-scan', '' !== $token );
$busy = $runner->run( $job_id, new Deadline( 0.0 ) );
umin_t_same( 'A second request is refused while a scan step holds the lease', 'busy', $busy['status'] );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_expires = %d WHERE id = %d', $wpdb->prefix . 'umin_jobs', time() - 1, $job_id ) );

// Repeating the same step must not duplicate findings: replay one step's
// state manually by running the handler twice from the saved state.
$job     = $store->get( $job_id );
$handler = new UnstoppableSolutions\MigrationInspector\Scanner\Scan_Handler();
$handler->step( $job['params'], $job['state'] );
$count_a = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $wpdb->prefix . 'umin_findings', $started['scan_id'] ) );
$handler->step( $job['params'], $job['state'] );
$count_b = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $wpdb->prefix . 'umin_findings', $started['scan_id'] ) );
umin_t_same( 'Repeating a scan step does not duplicate findings', $count_a, $count_b );

$run = umin_t_run_job( $job_id, 0.05 );
umin_t_same( 'Interrupted scan resumes and completes', 'completed', $run['status'] );
umin_t_same( 'Interrupted-and-resumed scan finds exactly the same as an uninterrupted one', umin_t_finding_signature( $scan_id ), umin_t_finding_signature( $started['scan_id'] ) );

// --- Resume via start(): an unfinished scan is resumed, not duplicated -------

$first  = Scan_Service::start();
$second = Scan_Service::start();
umin_t_same( 'Starting a scan while one is unfinished resumes it', $first['scan_id'], $second['scan_id'] );
umin_t_check( 'Resumed flag is set', true === $second['resumed'] );
umin_t_run_job( $first['job_id'] );

// Cleanup of the fake staging tables.
foreach ( array( 'options', 'posts', 'postmeta' ) as $name ) {
	$wpdb->query( "DROP TABLE IF EXISTS wpstg0_{$name}" );
}

umin_t_done();
