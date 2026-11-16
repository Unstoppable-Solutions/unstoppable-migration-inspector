<?php
/**
 * Migration scenario across two sites.
 *
 * "source" (site A): seed content, take a snapshot, export it to a file.
 * run.sh then copies site A (files and database) to site B.
 * "destination" (site B): simulate what an incomplete migration looks like
 * (rows that never arrived, a new domain, a deactivated plugin, the
 * snapshot table itself not copied), import the file, compare, and check
 * that exactly the lost IDs are reported.
 *
 * Usage: wp eval-file scenario-migrate.php <source|destination> <export file> <expectations file>
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

use UnstoppableSolutions\MigrationInspector\Core\Schema;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Service;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Transfer;
use UnstoppableSolutions\MigrationInspector\Snapshots\Woo_Storage;

require __DIR__ . '/lib.php';

wp_set_current_user( 1 );
global $wpdb;

list( $phase, $export_file, $expect_file ) = array_pad( $args, 3, '' );

if ( 'source' === $phase ) {
	echo "== Scenario: migration, source site ==\n";
	$core        = umin_t_seed_core();
	$woo         = umin_t_seed_woo( 30 );
	$snapshot_id = umin_t_capture( 'Old host, before move' );

	$handle = fopen( $export_file, 'wb' );
	( new Snapshot_Transfer() )->export( $snapshot_id, function ( $piece ) use ( $handle ) {
		fwrite( $handle, $piece );
	} );
	fclose( $handle );
	umin_t_check( 'Snapshot exported to a file', filesize( $export_file ) > 0 );

	// The same snapshot without content fingerprints.
	$handle = fopen( $export_file . '.nodigest', 'wb' );
	( new Snapshot_Transfer() )->export( $snapshot_id, function ( $piece ) use ( $handle ) {
		fwrite( $handle, $piece );
	}, false );
	fclose( $handle );
	$reduced = json_decode( file_get_contents( $export_file . '.nodigest' ), true );
	umin_t_same( 'Export without fingerprints has no content salt', '', $reduced['snapshot']['header']['content_salt'] );
	umin_t_check( 'Export without fingerprints has a different checksum', $reduced['snapshot']['checksum'] !== umin_t_snapshot( $snapshot_id )['checksum'] );

	file_put_contents( $expect_file, wp_json_encode( array( 'core' => $core, 'woo' => $woo ) ) );
	umin_t_done();
	return;
}

echo "== Scenario: migration, destination site ==\n";
$seed = json_decode( file_get_contents( $expect_file ), true );

// What an incomplete import leaves behind. Rows are removed with plain SQL,
// the way a dump that stopped part-way simply never contains them.
$lost_attachments = array( $seed['core']['attachments'][2], $seed['core']['attachments'][3], $seed['core']['attachments'][14] );
$lost_orders      = array_slice( $seed['woo']['orders'], -6 ); // The newest six orders never arrived.
$lost_users       = array( $seed['core']['users'][11] );

$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_map( 'intval', $lost_attachments ) ) . ')' );
$wpdb->query( 'DELETE FROM ' . Woo_Storage::hpos_table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $lost_orders ) ) . ')' );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_map( 'intval', $lost_orders ) ) . ')' );
$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID IN (" . implode( ',', array_map( 'intval', $lost_users ) ) . ')' );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN (" . implode( ',', array_map( 'intval', $lost_users ) ) . ')' );

// New domain, and the migration tool skipped the inspector's own tables.
update_option( 'home', 'https://new-site.test' );
update_option( 'siteurl', 'https://new-site.test' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'umin_snapshot_chunks' ) ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'umin_snapshots' ) ) );

// An import that rewrote one post's content to the same length without
// touching its modified date (a character set slip, for example).
$edited_post = (int) $seed['core']['posts'][0];
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, 'Confidential', 'Konfidential') WHERE ID = %d", $edited_post ) );

$imported = ( new Snapshot_Transfer() )->import( file_get_contents( $export_file ) );
umin_t_check( 'Snapshot file imported on the new site', $imported > 0 );

$reduced_id = ( new Snapshot_Transfer() )->import( file_get_contents( $export_file . '.nodigest' ) );
umin_t_check( 'Export without fingerprints passes import validation', $reduced_id > 0 );
umin_t_same( 'Without fingerprints, every other recorded field is identical', umin_t_records_without_digest( $imported ), umin_t_records_without_digest( $reduced_id ) );

$comparison_id = umin_t_compare( $imported );
$summary       = umin_t_summary( $comparison_id );

umin_t_same( 'Missing attachments on the new site: exactly the lost IDs', $lost_attachments, umin_t_ids( $comparison_id, 'post:attachment', Comparator::MISSING ) );
umin_t_same( 'Missing orders on the new site: exactly the six that never arrived', $lost_orders, umin_t_ids( $comparison_id, 'wc_order', Comparator::MISSING ) );
umin_t_same( 'Missing users on the new site: exactly the lost ID', $lost_users, umin_t_ids( $comparison_id, 'user', Comparator::MISSING ) );
umin_t_same( 'No posts reported missing', array(), umin_t_ids( $comparison_id, 'post:post', Comparator::MISSING ) );
umin_t_check( 'Same-length content edit is reported as changed (with fingerprints)', in_array( $edited_post, umin_t_ids( $comparison_id, 'post:post', Comparator::CHANGED ), true ) );

$reduced_cmp = umin_t_compare( $reduced_id, 0.5, true );
umin_t_same( 'Without fingerprints: the same missing attachments', $lost_attachments, umin_t_ids( $reduced_cmp, 'post:attachment', Comparator::MISSING ) );
umin_t_same( 'Without fingerprints: the same missing orders', $lost_orders, umin_t_ids( $reduced_cmp, 'wc_order', Comparator::MISSING ) );
umin_t_check( 'Without fingerprints: the same-length edit is not detectable (documented limit)', ! in_array( $edited_post, umin_t_ids( $reduced_cmp, 'post:post', Comparator::CHANGED ), true ) );

$keys = array_map(
	function ( $diff ) {
		return $diff['key'];
	},
	$summary['config']
);
umin_t_check( 'Domain change is reported', in_array( 'home', $keys, true ) && in_array( 'siteurl', $keys, true ) );

$posts_table = $summary['tables'][ $wpdb->prefix . 'posts' ];
umin_t_same( 'Posts table shows fewer rows than before', 'decreased', $posts_table['status'] );
umin_t_same( 'A copied database is recognised as the same site (site key carried over)', 'same_site', $summary['identity']['verdict'] );

// A destination whose database was rebuilt has no matching site key, but
// keeps IDs, prefix and name: it should be "likely the same", no prompt.
$copied_key = get_option( 'umin_site_key' );
delete_option( 'umin_site_key' );
update_option( 'umin_site_key', bin2hex( random_bytes( 16 ) ), false );
$rebuilt = UnstoppableSolutions\MigrationInspector\Snapshots\Identity_Check::assess( $imported, umin_t_snapshot( $imported )['header'] );
umin_t_same( 'Rebuilt database with the same IDs is "likely the same site"', 'likely_same', $rebuilt['verdict'] );
umin_t_check( 'Rebuilt database needs no acknowledgment', false === $rebuilt['needs_ack'] );
umin_t_check( 'Rebuilt database still explains the key mismatch', ! empty( $rebuilt['reasons'] ) );

// A copy that shares the site key but has since lost or replaced most of its
// records (an old staging copy): the key must not hide that.
update_option( 'umin_site_key', $copied_key, false );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ('post','page','attachment','product')" );
$wpdb->query( 'DELETE FROM ' . Woo_Storage::hpos_table() );
$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID > 1" );
$diverged = UnstoppableSolutions\MigrationInspector\Snapshots\Identity_Check::assess( $imported, umin_t_snapshot( $imported )['header'] );
umin_t_same( 'Same site key but most records gone: not treated as the same site', 'unverified', $diverged['verdict'] );
umin_t_check( 'Same site key but most records gone: needs acknowledgment', true === $diverged['needs_ack'] );
$refused = Snapshot_Service::start_compare( $imported );
umin_t_check( 'Same site key but most records gone: comparison refused without acknowledgment', is_wp_error( $refused ) && 'umin_identity_unconfirmed' === $refused->get_error_code() );

umin_t_done();
