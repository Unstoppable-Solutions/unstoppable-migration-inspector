<?php
/**
 * WooCommerce orders of one order type, from HPOS or legacy storage.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks orders (and refunds, subscriptions…) by ID, status and modified
 * time, reading from whichever storage the site uses.
 *
 * Order IDs are preserved when WooCommerce moves orders between HPOS and
 * legacy storage, so a snapshot taken in one mode can be compared with a
 * site in the other. Totals, items, customer and address data are never read.
 */
class Order_Source extends Record_Source {

	/**
	 * WooCommerce order type, e.g. shop_order.
	 *
	 * @var string
	 */
	protected $order_type;

	/**
	 * Storage mode: Woo_Storage::MODE_HPOS or MODE_LEGACY.
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * Constructor.
	 *
	 * @param string $key        Type key.
	 * @param string $order_type Order type.
	 * @param string $mode       Storage mode.
	 */
	public function __construct( $key, $order_type, $mode ) {
		parent::__construct( $key );
		$this->order_type = $order_type;
		$this->mode       = $mode;
	}

	/**
	 * Storage mode this source reads.
	 *
	 * @return string
	 */
	public function mode() {
		return $this->mode;
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
		);
	}

	/**
	 * Coded fields.
	 *
	 * @return array<string, string>
	 */
	public function coded_fields() {
		return array( 'status' => 'order_status' );
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

		if ( Woo_Storage::MODE_HPOS === $this->mode ) {
			if ( ! Woo_Storage::hpos_table_exists() ) {
				return array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan of the HPOS table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, status, date_updated_gmt AS modified, date_created_gmt AS created
					FROM %i
					WHERE type = %s AND id > %d AND id <= %d
					ORDER BY id ASC
					LIMIT %d',
					Woo_Storage::hpos_table(),
					$this->order_type,
					$after_id,
					self::upper( $max_id ),
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan of legacy order posts.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID AS id, post_status AS status, post_modified_gmt AS modified, post_date_gmt AS created
					FROM {$wpdb->posts}
					WHERE post_type = %s AND ID > %d AND ID <= %d
					ORDER BY ID ASC
					LIMIT %d",
					$this->order_type,
					$after_id,
					self::upper( $max_id ),
					$limit
				),
				ARRAY_A
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row['id'],
				'fields'  => array(
					'status'   => (string) $row['status'],
					'modified' => self::gmt_to_unix( $row['modified'] ),
				),
				'created' => self::gmt_to_unix( $row['created'] ),
			);
		}

		return $out;
	}
}
