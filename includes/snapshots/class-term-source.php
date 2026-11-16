<?php
/**
 * Terms of one taxonomy.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks categories, tags, product categories, menus and other taxonomy
 * terms by term_taxonomy_id and object count. Terms have no creation time,
 * so an unknown term is reported as "not in snapshot", never as "new".
 */
class Term_Source extends Record_Source {

	/**
	 * Taxonomy.
	 *
	 * @var string
	 */
	protected $taxonomy;

	/**
	 * Constructor.
	 *
	 * @param string $key      Type key.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function __construct( $key, $taxonomy ) {
		parent::__construct( $key );
		$this->taxonomy = $taxonomy;
	}

	/**
	 * Field kinds.
	 *
	 * @return array<string, int>
	 */
	public function kinds() {
		return array( 'count' => Chunk_Codec::KIND_UNSIGNED );
	}

	/**
	 * Coded fields.
	 *
	 * @return array<string, string>
	 */
	public function coded_fields() {
		return array();
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
				"SELECT term_taxonomy_id, count
				FROM {$wpdb->term_taxonomy}
				WHERE taxonomy = %s AND term_taxonomy_id > %d AND term_taxonomy_id <= %d
				ORDER BY term_taxonomy_id ASC
				LIMIT %d",
				$this->taxonomy,
				$after_id,
				self::upper( $max_id ),
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row['term_taxonomy_id'],
				'fields'  => array( 'count' => max( 0, (int) $row['count'] ) ),
				'created' => null,
			);
		}

		return $out;
	}
}
