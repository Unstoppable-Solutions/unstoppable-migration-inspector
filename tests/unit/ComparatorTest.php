<?php
/**
 * Tests for snapshot comparison logic and helpers.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey\Functions;
use UnstoppableSolutions\MigrationInspector\Snapshots\Chunk_Codec;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Config_Diff;
use UnstoppableSolutions\MigrationInspector\Snapshots\Dictionary;
use UnstoppableSolutions\MigrationInspector\Snapshots\Source_Registry;
use UnstoppableSolutions\MigrationInspector\Snapshots\User_Source;

/**
 * Comparator tests.
 */
final class ComparatorTest extends Test_Case {

	const SNAPSHOT_TIME = 1763300000;

	/**
	 * A live row.
	 *
	 * @param int      $id      ID.
	 * @param string   $status  Status.
	 * @param int      $length  Content length.
	 * @param int|null $created Creation time.
	 * @return array<string, mixed>
	 */
	private function row( $id, $status = 'publish', $length = 100, $created = 1700000000 ) {
		return array(
			'id'      => $id,
			'fields'  => array(
				'status'   => $status,
				'modified' => 1700000000,
				'length'   => $length,
			),
			'created' => $created,
		);
	}

	/**
	 * Snapshot fields.
	 *
	 * @param string $status Status.
	 * @param int    $length Length.
	 * @return array<string, mixed>
	 */
	private function snap( $status = 'publish', $length = 100 ) {
		return array(
			'status'   => $status,
			'modified' => 1700000000,
			'length'   => $length,
		);
	}

	/**
	 * Items of one kind, as IDs.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param string               $kind   Kind.
	 * @return int[]
	 */
	private function ids( array $result, $kind ) {
		$ids = array();
		foreach ( $result['items'] as $item ) {
			if ( $kind === $item['kind'] ) {
				$ids[] = $item['id'];
			}
		}
		return $ids;
	}

	public function test_every_bucket_with_exact_ids() {
		$snapshot = array(
			1 => $this->snap(),
			2 => $this->snap(),
			3 => $this->snap( 'publish', 500 ),
			4 => $this->snap( 'publish' ),
			5 => $this->snap(),
		);
		$site     = array(
			$this->row( 1 ),                                         // Unchanged.
			$this->row( 3, 'publish', 120 ),                         // Changed: shrank.
			$this->row( 4, 'draft' ),                                // Changed: status.
			$this->row( 6, 'publish', 100, self::SNAPSHOT_TIME + 60 ), // New since snapshot.
			$this->row( 7, 'publish', 100, self::SNAPSHOT_TIME - 60 ), // Not in snapshot.
			$this->row( 8, 'publish', 100, null ),                   // Not in snapshot (no date).
		);

		$result = Comparator::compare_range( $snapshot, $site, self::SNAPSHOT_TIME );

		$this->assertSame( array( 2, 5 ), $this->ids( $result, Comparator::MISSING ) );
		$this->assertSame( array( 3, 4 ), $this->ids( $result, Comparator::CHANGED ) );
		$this->assertSame( array( 6 ), $this->ids( $result, Comparator::ADDED ) );
		$this->assertSame( array( 7, 8 ), $this->ids( $result, Comparator::EXTRA ) );

		$this->assertSame( 5, $result['totals']['in_snapshot'] );
		$this->assertSame( 6, $result['totals']['on_site'] );
		$this->assertSame( 2, $result['totals'][ Comparator::MISSING ] );
		$this->assertSame( 2, $result['totals'][ Comparator::CHANGED ] );
		$this->assertSame( 1, $result['totals'][ Comparator::UNCHANGED ] );
		$this->assertSame( 1, $result['totals'][ Comparator::ADDED ] );
		$this->assertSame( 2, $result['totals'][ Comparator::EXTRA ] );

		// Every record lands in exactly one bucket.
		$snapshot_side = $result['totals'][ Comparator::MISSING ] + $result['totals'][ Comparator::CHANGED ] + $result['totals'][ Comparator::UNCHANGED ];
		$site_side     = $result['totals'][ Comparator::CHANGED ] + $result['totals'][ Comparator::UNCHANGED ] + $result['totals'][ Comparator::ADDED ] + $result['totals'][ Comparator::EXTRA ];
		$this->assertSame( $result['totals']['in_snapshot'], $snapshot_side );
		$this->assertSame( $result['totals']['on_site'], $site_side );
	}

	public function test_changed_detail_lists_fields_and_shrink_hint() {
		$result  = Comparator::compare_range( array( 3 => $this->snap( 'publish', 500 ) ), array( $this->row( 3, 'private', 120 ) ), self::SNAPSHOT_TIME );
		$changed = $result['items'][0];

		$this->assertSame( Comparator::CHANGED, $changed['kind'] );
		$this->assertSame( array( 'publish', 'private' ), $changed['detail']['status'] );
		$this->assertSame( array( 500, 120 ), $changed['detail']['length'] );
		$this->assertTrue( $changed['detail']['shrank'] );
		$this->assertArrayNotHasKey( 'modified', $changed['detail'] );
	}

	public function test_growing_content_has_no_shrink_hint() {
		$changes = Comparator::field_changes( $this->snap( 'publish', 100 ), array( 'length' => 200 ) );

		$this->assertArrayNotHasKey( 'shrank', $changes );
	}

	public function test_created_exactly_at_snapshot_time_is_not_new() {
		$result = Comparator::compare_range( array(), array( $this->row( 9, 'publish', 1, self::SNAPSHOT_TIME ) ), self::SNAPSHOT_TIME );

		$this->assertSame( array( 9 ), $this->ids( $result, Comparator::EXTRA ) );
	}

	public function test_empty_site_means_everything_missing() {
		$result = Comparator::compare_range( array( 1 => $this->snap(), 2 => $this->snap() ), array(), self::SNAPSHOT_TIME );

		$this->assertSame( array( 1, 2 ), $this->ids( $result, Comparator::MISSING ) );
		$this->assertSame( array( 'status' => 'publish' ), $result['items'][0]['detail'] );
	}

	public function test_items_are_sorted_by_id() {
		$result = Comparator::compare_range(
			array( 10 => $this->snap(), 2 => $this->snap() ),
			array( $this->row( 5, 'publish', 1, self::SNAPSHOT_TIME + 1 ) ),
			self::SNAPSHOT_TIME
		);

		$this->assertSame( array( 2, 5, 10 ), array_column( $result['items'], 'id' ) );
	}

	public function test_add_totals() {
		$sum = Comparator::add_totals( Comparator::empty_totals(), array( 'missing' => 2, 'in_snapshot' => 5 ) );
		$sum = Comparator::add_totals( $sum, array( 'missing' => 1 ) );

		$this->assertSame( 3, $sum['missing'] );
		$this->assertSame( 5, $sum['in_snapshot'] );
		$this->assertSame( 0, $sum['new'] );
	}

	public function test_decode_chunk_maps_codes_back_to_names() {
		$dictionary = new Dictionary();
		$kinds      = array(
			'status'   => Chunk_Codec::KIND_UNSIGNED,
			'modified' => Chunk_Codec::KIND_DELTA,
		);
		$chunk      = Chunk_Codec::encode(
			array( 7, 9 ),
			array(
				'status'   => array( $dictionary->code( 'order_status', 'wc-processing' ), $dictionary->code( 'order_status', 'wc-completed' ) ),
				'modified' => array( 100, 50 ),
			),
			$kinds
		);

		$row = array(
			'payload'   => $chunk['payload'],
			'row_count' => $chunk['count'],
			'sha256'    => $chunk['sha256'],
		);

		$decoded = Comparator::decode_chunk( $row, $kinds, array( 'status' => 'order_status' ), new Dictionary( $dictionary->to_array() ) );

		$this->assertSame(
			array(
				7 => array(
					'status'   => 'wc-processing',
					'modified' => 100,
				),
				9 => array(
					'status'   => 'wc-completed',
					'modified' => 50,
				),
			),
			$decoded
		);
	}

	public function test_dictionary_stores_only_vocabulary() {
		$dictionary = new Dictionary();

		$this->assertSame( 0, $dictionary->code( 'role', '' ) );
		$this->assertSame( 1, $dictionary->code( 'role', 'editor' ) );
		$this->assertSame( 1, $dictionary->code( 'role', 'editor' ) );
		$code = $dictionary->code( 'role', 'Jane Doe <jane@example.com>' );

		$this->assertSame( Dictionary::OTHER, $dictionary->value( 'role', $code ) );
		$this->assertNotContains( 'Jane Doe <jane@example.com>', $dictionary->to_array()['role'] );
		$this->assertSame( Dictionary::OTHER, $dictionary->value( 'role', 999 ) );
	}

	public function test_dictionary_caps_entries() {
		$dictionary = new Dictionary();
		for ( $i = 0; $i < Dictionary::MAX_ENTRIES + 50; $i++ ) {
			$dictionary->code( 'post_status', 'status_' . $i );
		}

		$this->assertLessThanOrEqual( Dictionary::MAX_ENTRIES + 1, count( $dictionary->to_array()['post_status'] ) );
	}

	/**
	 * Definitions.
	 *
	 * @return array<string, array{0: array<string, string>, 1: bool}>
	 */
	public function definition_provider() {
		return array(
			'post'             => array( array( 'key' => 'post:page', 'source' => 'post', 'arg' => 'page' ), true ),
			'term'             => array( array( 'key' => 'term:product_cat', 'source' => 'term', 'arg' => 'product_cat' ), true ),
			'user'             => array( array( 'key' => 'user', 'source' => 'user', 'arg' => '' ), true ),
			'order'            => array( array( 'key' => 'wc_order', 'source' => 'order', 'arg' => 'shop_order' ), true ),
			'refund'           => array( array( 'key' => 'wc_refund', 'source' => 'order', 'arg' => 'shop_order_refund' ), true ),
			'mismatched post'  => array( array( 'key' => 'post:page', 'source' => 'post', 'arg' => 'post' ), false ),
			'sql in arg'       => array( array( 'key' => "post:a' OR 1", 'source' => 'post', 'arg' => "a' OR 1" ), false ),
			'too long type'    => array( array( 'key' => 'post:' . str_repeat( 'a', 21 ), 'source' => 'post', 'arg' => str_repeat( 'a', 21 ) ), false ),
			'unknown source'   => array( array( 'key' => 'user', 'source' => 'eval', 'arg' => '' ), false ),
			'order key forged' => array( array( 'key' => 'wc_order', 'source' => 'order', 'arg' => 'shop_order_refund' ), false ),
		);
	}

	/**
	 * Only well-formed definitions are accepted.
	 *
	 * @dataProvider definition_provider
	 *
	 * @param array<string, string> $definition Definition.
	 * @param bool                  $valid      Expected validity.
	 */
	public function test_definition_validation( array $definition, $valid ) {
		$this->assertSame( $valid, Source_Registry::is_valid_definition( $definition ) );
	}

	public function test_primary_role_parses_without_objects() {
		Functions\when( 'is_serialized' )->alias(
			static function ( $value ) {
				return is_string( $value ) && preg_match( '/^[aOsibd]:/', $value );
			}
		);

		$this->assertSame( 'editor', User_Source::primary_role( serialize( array( 'editor' => true ) ) ) );
		$this->assertSame( 'shop_manager', User_Source::primary_role( serialize( array( 'old' => false, 'shop_manager' => true ) ) ) );
		$this->assertSame( '', User_Source::primary_role( 'O:8:"stdClass":1:{s:6:"editor";b:1;}' ) );
		$this->assertSame( '', User_Source::primary_role( 'a:1:{broken' ) );
		$this->assertSame( '', User_Source::primary_role( null ) );
	}

	public function test_config_diff_flags_missing_plugin_theme_and_storage_switch() {
		$before = array(
			'home'                => 'https://old.example.com',
			'siteurl'             => 'https://old.example.com',
			'permalink_structure' => '/%postname%/',
			'table_prefix'        => 'wp_',
			'theme'               => array(
				'stylesheet' => 'storefront',
				'version'    => '4.0',
			),
			'plugins'             => array(
				'woocommerce/woocommerce.php' => '11.3.0',
				'seo/seo.php'                 => '2.0',
			),
			'woocommerce'         => array(
				'active'  => true,
				'storage' => 'hpos',
				'pages'   => array( 'checkout' => 10 ),
			),
		);
		$after  = array(
			'home'                => 'https://new.example.com',
			'siteurl'             => 'https://new.example.com',
			'permalink_structure' => '/%postname%/',
			'table_prefix'        => 'wp_',
			'theme'               => array(
				'stylesheet' => 'twentytwentyfive',
				'version'    => '1.0',
			),
			'plugins'             => array(
				'woocommerce/woocommerce.php' => '11.3.0',
				'unstoppable-migration-inspector/unstoppable-migration-inspector.php' => '0.1.0',
			),
			'woocommerce'         => array(
				'active'  => true,
				'storage' => 'legacy',
				'pages'   => array( 'checkout' => 10 ),
			),
		);

		$diffs = Config_Diff::compare( $before, $after );
		$keyed = array();
		foreach ( $diffs as $diff ) {
			$keyed[ $diff['key'] ] = $diff;
		}

		$this->assertSame( 'info', $keyed['home']['severity'] );
		$this->assertSame( 'critical', $keyed['theme']['severity'] );
		$this->assertSame( 'critical', $keyed['plugin:seo/seo.php']['severity'] );
		$this->assertSame( 'warning', $keyed['woocommerce_storage']['severity'] );
		$this->assertArrayNotHasKey( 'permalink_structure', $keyed );
		$this->assertArrayNotHasKey( 'plugin:unstoppable-migration-inspector/unstoppable-migration-inspector.php', $keyed, 'The inspector itself is not reported as a new plugin.' );
	}

	public function test_prefix_change_severity_depends_on_whether_roles_moved() {
		$before = array( 'table_prefix' => 'wpf0_' );
		$after  = array( 'table_prefix' => 'stg_' );

		$moved   = Config_Diff::compare( $before, $after, true );
		$broken  = Config_Diff::compare( $before, $after, false );
		$unknown = Config_Diff::compare( $before, $after );

		$this->assertSame( 'info', $moved[0]['severity'] );
		$this->assertSame( 'warning', $broken[0]['severity'] );
		$this->assertSame( 'info', $unknown[0]['severity'] );
	}

	public function test_backup_and_staging_tool_tables_are_recognised() {
		foreach ( array( 'wpf0_wpvivid_options', 'wp_wpstg_queue', 'wp_duplicator_packages', 'wp_ai1wm_x', 'wp_updraft_jobs' ) as $table ) {
			$this->assertTrue( \UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler::is_tool_table( $table ), $table );
		}
		foreach ( array( 'wp_posts', 'wp_wc_orders', 'wp_woocommerce_order_items' ) as $table ) {
			$this->assertFalse( \UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler::is_tool_table( $table ), $table );
		}
	}

	public function test_record_tables_are_recognised() {
		foreach ( array( 'wp_posts', 'stg_postmeta', 'wp_term_relationships', 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_woocommerce_order_itemmeta', 'wp_wc_product_meta_lookup', 'wp_wc_customer_lookup', 'wp_comments' ) as $table ) {
			$this->assertTrue( \UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler::is_record_table( $table ), $table );
		}
		foreach ( array( 'wp_options', 'wp_wpvivid_options', 'wp_actionscheduler_actions', 'wp_mypostsplugin_log' ) as $table ) {
			$this->assertFalse( \UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler::is_record_table( $table ), $table );
		}
	}
}
