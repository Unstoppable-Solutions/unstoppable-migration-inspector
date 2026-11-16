<?php
/**
 * Tests for imported snapshot validation.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey\Functions;
use UnstoppableSolutions\MigrationInspector\Snapshots\Chunk_Codec;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Validation_Exception;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Validator;

/**
 * Validator tests.
 */
final class SnapshotValidatorTest extends Test_Case {

	/**
	 * Set up stubs used by the validator.
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0 ) {
				return json_encode( $data, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub.
			}
		);
	}

	/**
	 * A valid snapshot document with posts and orders.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_document() {
		$post_kinds  = array(
			'status'   => Chunk_Codec::KIND_UNSIGNED,
			'modified' => Chunk_Codec::KIND_DELTA,
			'length'   => Chunk_Codec::KIND_UNSIGNED,
		);
		$order_kinds = array(
			'status'   => Chunk_Codec::KIND_UNSIGNED,
			'modified' => Chunk_Codec::KIND_DELTA,
		);

		$posts_a = Chunk_Codec::encode( array( 1, 2, 3 ), array( 'status' => array( 1, 1, 2 ), 'modified' => array( 10, 20, 30 ), 'length' => array( 5, 6, 7 ) ), $post_kinds );
		$posts_b = Chunk_Codec::encode( array( 10, 11 ), array( 'status' => array( 1, 1 ), 'modified' => array( 40, 50 ), 'length' => array( 8, 9 ) ), $post_kinds );
		$orders  = Chunk_Codec::encode( array( 100, 101 ), array( 'status' => array( 1, 2 ), 'modified' => array( 60, 70 ) ), $order_kinds );

		$header = array(
			'format_version' => 1,
			'created_at'     => 1763300000,
			'order_mode'     => 'hpos',
			'config'         => array(
				'home'                => 'https://old.example.com',
				'siteurl'             => 'https://old.example.com',
				'wp_version'          => '7.1.4',
				'php_version'         => '8.3.6',
				'db_version'          => '10.11.14-MariaDB',
				'table_prefix'        => 'wp_',
				'is_multisite'        => false,
				'blog_id'             => 1,
				'permalink_structure' => '/%postname%/',
				'theme'               => array(
					'stylesheet' => 'storefront',
					'template'   => 'storefront',
					'version'    => '4.6.1',
				),
				'plugins'             => array( 'woocommerce/woocommerce.php' => '11.3.0' ),
				'upload_baseurl'      => 'https://old.example.com/wp-content/uploads',
				'woocommerce'         => array(
					'active'       => true,
					'version'      => '11.3.0',
					'storage'      => 'hpos',
					'hpos_table'   => true,
					'sync_enabled' => false,
					'pages'        => array( 'checkout' => 7 ),
				),
			),
			'types'          => array(
				'post:post' => array(
					'source' => 'post',
					'arg'    => 'post',
					'count'  => 5,
					'chunks' => 2,
				),
				'wc_order'  => array(
					'source' => 'order',
					'arg'    => 'shop_order',
					'count'  => 2,
					'chunks' => 1,
				),
			),
			'dictionaries'   => array(
				'post_status'  => array( '', 'publish', 'draft' ),
				'order_status' => array( '', 'wc-processing', 'wc-completed' ),
			),
			'tables'         => array(
				'wp_posts' => array(
					'rows'        => 7,
					'approximate' => false,
					'missing'     => false,
				),
			),
			'revisions'      => 0,
		);

		$chunks = array();
		foreach ( array( array( 'post:post', 0, $posts_a ), array( 'post:post', 1, $posts_b ), array( 'wc_order', 0, $orders ) ) as $entry ) {
			$chunks[] = array(
				'type'     => $entry[0],
				'seq'      => $entry[1],
				'first_id' => $entry[2]['first_id'],
				'last_id'  => $entry[2]['last_id'],
				'count'    => $entry[2]['count'],
				'sha256'   => $entry[2]['sha256'],
				'payload'  => $entry[2]['payload'],
			);
		}

		return $this->with_checksum(
			array(
				'format'         => 'umin-snapshot',
				'format_version' => 1,
				'exported_at'    => 1763400000,
				'plugin_version' => '0.1.0',
				'snapshot'       => array(
					'label'    => 'Before move',
					'checksum' => '',
					'header'   => $header,
				),
				'chunks'         => $chunks,
			)
		);
	}

	/**
	 * Recompute the document checksum (as an editor of the file could).
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	private function with_checksum( array $doc ) {
		$rows = array();
		foreach ( $doc['chunks'] as $chunk ) {
			$rows[] = array(
				'record_type' => $chunk['type'],
				'seq'         => $chunk['seq'],
				'first_id'    => $chunk['first_id'],
				'last_id'     => $chunk['last_id'],
				'row_count'   => $chunk['count'],
				'sha256'      => $chunk['sha256'],
			);
		}

		$doc['snapshot']['checksum'] = Snapshot_Repository::checksum( $doc['snapshot']['header'], $rows );

		return $doc;
	}

	/**
	 * Assert the document is refused.
	 *
	 * @param mixed $doc Document.
	 */
	private function assertRefused( $doc ) {
		try {
			Snapshot_Validator::validate( $doc );
			$this->fail( 'The document should have been refused.' );
		} catch ( Snapshot_Validation_Exception $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
	}

	public function test_valid_document_is_accepted() {
		$result = Snapshot_Validator::validate( $this->valid_document() );

		$this->assertSame( 'Before move', $result['label'] );
		$this->assertCount( 3, $result['chunks'] );
		$this->assertSame( 'post:post', $result['chunks'][0]['record_type'] );
		$this->assertSame( 3, $result['chunks'][0]['row_count'] );
	}

	public function test_not_an_array_is_refused() {
		$this->assertRefused( null );
		$this->assertRefused( 'a string' );
	}

	public function test_unknown_top_level_key_is_refused() {
		$doc          = $this->valid_document();
		$doc['extra'] = 'x';
		$this->assertRefused( $doc );
	}

	public function test_wrong_format_or_version_is_refused() {
		$doc           = $this->valid_document();
		$doc['format'] = 'something-else';
		$this->assertRefused( $doc );

		$doc                   = $this->valid_document();
		$doc['format_version'] = 2;
		$this->assertRefused( $doc );
	}

	public function test_checksum_mismatch_is_refused() {
		$doc                                      = $this->valid_document();
		$doc['snapshot']['header']['revisions']   = 5;
		$this->assertRefused( $doc );
	}

	public function test_tampered_payload_is_refused_even_with_recomputed_checksum() {
		$doc     = $this->valid_document();
		$replace = Chunk_Codec::encode( array( 100, 102 ), array( 'status' => array( 1, 2 ), 'modified' => array( 60, 70 ) ), array( 'status' => 0, 'modified' => 1 ) );

		// Swap the payload but keep the old sha256: the chunk check must fail.
		$doc['chunks'][2]['payload'] = $replace['payload'];
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_index_that_does_not_match_payload_is_refused() {
		$doc                          = $this->valid_document();
		$doc['chunks'][2]['last_id']  = 150;
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_overlapping_or_out_of_order_chunks_are_refused() {
		$doc = $this->valid_document();
		$tmp = $doc['chunks'][0];

		$doc['chunks'][0] = $doc['chunks'][1];
		$doc['chunks'][1] = $tmp;
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_totals_that_do_not_match_chunks_are_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['types']['post:post']['count'] = 6;
		$this->assertRefused( $this->with_checksum( $doc ) );

		$doc = $this->valid_document();
		array_pop( $doc['chunks'] );
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_invalid_type_definition_is_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['types']["post:x' OR 1=1"] = array(
			'source' => 'post',
			'arg'    => "x' OR 1=1",
			'count'  => 0,
			'chunks' => 0,
		);
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_free_text_in_dictionary_is_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['dictionaries']['role'] = array( '', 'Jane Doe <jane@example.com>' );
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_unknown_dictionary_is_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['dictionaries']['emails'] = array( '' );
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_unsafe_url_is_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['config']['home'] = 'javascript:alert(1)';
		$this->assertRefused( $this->with_checksum( $doc ) );

		$doc = $this->valid_document();
		$doc['snapshot']['header']['config']['siteurl'] = 'https://example.com/"><script>';
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_unexpected_config_key_is_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['config']['admin_email'] = 'owner@example.com';
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_wrong_value_types_are_refused() {
		$doc = $this->valid_document();
		$doc['snapshot']['header']['config']['is_multisite'] = 'no';
		$this->assertRefused( $this->with_checksum( $doc ) );

		$doc = $this->valid_document();
		$doc['chunks'][0]['count'] = '3';
		$this->assertRefused( $this->with_checksum( $doc ) );
	}

	public function test_label_html_is_stripped() {
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub.
			}
		);

		$doc                      = $this->valid_document();
		$doc['snapshot']['label'] = '<b>Before</b> move';
		$result                   = Snapshot_Validator::validate( $doc );

		$this->assertSame( 'Before move', $result['label'] );
	}
}
