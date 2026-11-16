<?php
/**
 * Base class for a tracked record type.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one record type in ascending ID order, a batch at a time.
 *
 * A source only ever selects the allowlisted fields its subclass names:
 * IDs, status-like codes, timestamps and sizes. It never selects titles,
 * content, emails, names or meta values.
 */
abstract class Record_Source {

	/**
	 * Type key, for example "post:page", "user" or "wc_order".
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * Constructor.
	 *
	 * @param string $key Type key.
	 */
	public function __construct( $key ) {
		$this->key = $key;
	}

	/**
	 * Type key.
	 *
	 * @return string
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * Field encoding kinds, field name => Chunk_Codec::KIND_*.
	 *
	 * @return array<string, int>
	 */
	abstract public function kinds();

	/**
	 * Fields stored as dictionary codes, field name => dictionary name.
	 *
	 * @return array<string, string>
	 */
	abstract public function coded_fields();

	/**
	 * Fetch the next batch.
	 *
	 * @param int $after_id Exclusive lower ID bound.
	 * @param int $limit    Maximum rows.
	 * @param int $max_id   Inclusive upper ID bound, 0 for none.
	 * @return array<int, array{id: int, fields: array<string, int|string>, created: int|null}>
	 *         Ascending by ID. Coded fields are raw strings; others are ints.
	 */
	abstract public function fetch_after( $after_id, $limit, $max_id = 0 );

	/**
	 * Convert a GMT MySQL datetime to a unix timestamp.
	 *
	 * Zero dates and unparseable values become 0.
	 *
	 * @param string|null $value Datetime string.
	 * @return int
	 */
	protected static function gmt_to_unix( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return 0;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time || $time < 0 ? 0 : $time;
	}

	/**
	 * Upper bound clause value: PHP_INT_MAX when there is no bound.
	 *
	 * @param int $max_id Inclusive upper bound or 0.
	 * @return int
	 */
	protected static function upper( $max_id ) {
		return $max_id > 0 ? (int) $max_id : PHP_INT_MAX;
	}
}
