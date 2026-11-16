<?php
/**
 * Posts of one post type.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks posts, pages, attachments, products and any other post type.
 *
 * Reads the ID, status, modified time, creation time and the byte length of
 * the content. The content itself is never selected.
 */
class Post_Source extends Record_Source {

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Constructor.
	 *
	 * @param string $key       Type key.
	 * @param string $post_type Post type.
	 */
	public function __construct( $key, $post_type ) {
		parent::__construct( $key );
		$this->post_type = $post_type;
	}

	/**
	 * Field kinds.
	 *
	 * @return array<string, int>
	 */
	public function kinds() {
		return array(
			'status'   => Chunk_Codec::KIND_UNSIGNED,
			'modified' => Chunk_Codec::KIND_DELTA,
			'length'   => Chunk_Codec::KIND_UNSIGNED,
		);
	}

	/**
	 * Coded fields.
	 *
	 * @return array<string, string>
	 */
	public function coded_fields() {
		return array( 'status' => 'post_status' );
	}

	/**
	 * Fetch the next batch.
	 *
	 * @param int $after_id Exclusive lower bound.
	 * @param int $limit    Maximum rows.
	 * @param int $max_id   Inclusive upper bound or 0.
	 * @return array<int, array{id: int, fields: array<string, int|string>, created: int|null}>
	 */
	public function fetch_after( $after_id, $limit, $max_id = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan; caching would defeat the snapshot.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status, post_modified_gmt, post_date_gmt, LENGTH(post_content) AS content_length
				FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status <> %s AND ID > %d AND ID <= %d
				ORDER BY ID ASC
				LIMIT %d",
				$this->post_type,
				'auto-draft',
				$after_id,
				self::upper( $max_id ),
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row['ID'],
				'fields'  => array(
					'status'   => (string) $row['post_status'],
					'modified' => self::gmt_to_unix( $row['post_modified_gmt'] ),
					'length'   => max( 0, (int) $row['content_length'] ),
				),
				'created' => self::gmt_to_unix( $row['post_date_gmt'] ),
			);
		}

		return $out;
	}
}
