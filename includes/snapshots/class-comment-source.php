<?php
/**
 * Comments.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks comments (including reviews and order notes) by ID, approval
 * state and comment type. Author names, emails, IPs and text are never read.
 */
class Comment_Source extends Record_Source {

	/**
	 * Field kinds.
	 *
	 * @return array<string, int>
	 */
	public function kinds() {
		return array(
			'approved' => Chunk_Codec::KIND_UNSIGNED,
			'ctype'    => Chunk_Codec::KIND_UNSIGNED,
		);
	}

	/**
	 * Coded fields.
	 *
	 * @return array<string, string>
	 */
	public function coded_fields() {
		return array(
			'approved' => 'comment_approved',
			'ctype'    => 'comment_type',
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_approved, comment_type, comment_date_gmt
				FROM {$wpdb->comments}
				WHERE comment_ID > %d AND comment_ID <= %d
				ORDER BY comment_ID ASC
				LIMIT %d",
				$after_id,
				self::upper( $max_id ),
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row['comment_ID'],
				'fields'  => array(
					'approved' => (string) $row['comment_approved'],
					'ctype'    => '' === (string) $row['comment_type'] ? 'comment' : (string) $row['comment_type'],
				),
				'created' => self::gmt_to_unix( $row['comment_date_gmt'] ),
			);
		}

		return $out;
	}
}
