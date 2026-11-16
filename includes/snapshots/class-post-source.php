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
 * Reads the ID, status, modified time, creation time, the byte length of
 * the content and a 16-bit salted content digest. The content itself is
 * never selected into PHP: the digest is computed by the database.
 *
 * Why 16 bits: enough to notice that content changed without a change in
 * length (a 1 in 65,536 chance of missing a change) while revealing as
 * little as possible. The salt is random per snapshot, so digests cannot be
 * matched across snapshots. It is NOT secret from anyone holding an export:
 * the salt travels in the file so the destination can compute the same
 * digest. With the file, a guess at a post's content can be tested, and for
 * short, predictable content (a PIN, a short code) together with the stored
 * length that can be enough to recover it. Exports can therefore be made
 * without digests (see Snapshot_Transfer::export()).
 */
class Post_Source extends Record_Source {

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Snapshot salt for the content digest; empty disables the digest.
	 *
	 * @var string
	 */
	protected $salt;

	/**
	 * Constructor.
	 *
	 * @param string $key       Type key.
	 * @param string $post_type Post type.
	 * @param string $salt      Content digest salt (16 hex characters) or ''.
	 */
	public function __construct( $key, $post_type, $salt = '' ) {
		parent::__construct( $key );
		$this->post_type = $post_type;
		$this->salt      = (bool) preg_match( '/^[0-9a-f]{16}$/', (string) $salt ) ? (string) $salt : '';
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
			'digest'   => Chunk_Codec::KIND_UNSIGNED,
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

		// USE INDEX (PRIMARY): MariaDB otherwise picks type_status_author and sorts
		// most of the post type on every batch (measured 10x slower on 200k posts).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan; caching would defeat the snapshot.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status, post_modified_gmt, post_date_gmt, LENGTH(post_content) AS content_length,
					LEFT(MD5(CONCAT(%s, post_content)), 4) AS content_digest
				FROM {$wpdb->posts} USE INDEX (PRIMARY)
				WHERE post_type = %s AND post_status <> %s AND ID > %d AND ID <= %d
				ORDER BY ID ASC
				LIMIT %d",
				'' === $this->salt ? 'nodigest' : $this->salt,
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
					'digest'   => '' === $this->salt ? 0 : self::digest_value( $row['content_digest'] ),
				),
				'created' => self::gmt_to_unix( $row['post_date_gmt'] ),
			);
		}

		return $out;
	}

	/**
	 * Turn the 4 hex characters from the database into an integer.
	 *
	 * @param mixed $hex Hex string.
	 * @return int 0 to 65535.
	 */
	private static function digest_value( $hex ) {
		return is_string( $hex ) && preg_match( '/^[0-9a-fA-F]{4}$/', $hex ) ? (int) hexdec( $hex ) : 0;
	}
}
