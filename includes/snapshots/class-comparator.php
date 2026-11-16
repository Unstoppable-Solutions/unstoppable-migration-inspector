<?php
/**
 * Classifies records as missing, changed, unchanged, new or not in snapshot.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Pure comparison logic, independent of the database.
 */
final class Comparator {

	const MISSING   = 'missing';
	const CHANGED   = 'changed';
	const UNCHANGED = 'unchanged';
	const ADDED     = 'new';
	const EXTRA     = 'extra';

	/**
	 * All buckets in display order.
	 *
	 * @return string[]
	 */
	public static function buckets() {
		return array( self::MISSING, self::CHANGED, self::ADDED, self::EXTRA, self::UNCHANGED );
	}

	/**
	 * Empty totals for one record type.
	 *
	 * @return array<string, int>
	 */
	public static function empty_totals() {
		return array(
			'in_snapshot'   => 0,
			'on_site'       => 0,
			self::MISSING   => 0,
			self::CHANGED   => 0,
			self::UNCHANGED => 0,
			self::ADDED     => 0,
			self::EXTRA     => 0,
		);
	}

	/**
	 * Compare one ID range.
	 *
	 * Every snapshot record and every site record in the range ends up in
	 * exactly one bucket.
	 *
	 * @param array<int, array<string, int|string>>                                            $snapshot   Snapshot records in the range: ID => field values (codes already
	 *                                                                                                     turned back into names).
	 * @param array<int, array{id: int, fields: array<string, int|string>, created: int|null}> $site       Site rows in the same range.
	 * @param int                                                                              $snapshot_time When the snapshot was taken (unix).
	 * @return array{items: array<int, array{id: int, kind: string, detail: array<string, mixed>}>, totals: array<string, int>}
	 */
	public static function compare_range( array $snapshot, array $site, $snapshot_time ) {
		$totals = self::empty_totals();
		$items  = array();
		$seen   = array();

		$totals['in_snapshot'] = count( $snapshot );
		$totals['on_site']     = count( $site );

		foreach ( $site as $row ) {
			$id          = (int) $row['id'];
			$seen[ $id ] = true;

			if ( array_key_exists( $id, $snapshot ) ) {
				$changes = self::field_changes( $snapshot[ $id ], $row['fields'] );

				if ( empty( $changes ) ) {
					++$totals[ self::UNCHANGED ];
				} else {
					++$totals[ self::CHANGED ];
					$items[] = array(
						'id'     => $id,
						'kind'   => self::CHANGED,
						'detail' => $changes,
					);
				}
				continue;
			}

			$created = isset( $row['created'] ) ? $row['created'] : null;
			$kind    = ( null !== $created && $created > (int) $snapshot_time ) ? self::ADDED : self::EXTRA;

			++$totals[ $kind ];
			$items[] = array(
				'id'     => $id,
				'kind'   => $kind,
				'detail' => null === $created || 0 === $created ? array() : array( 'created' => $created ),
			);
		}

		foreach ( $snapshot as $id => $fields ) {
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			++$totals[ self::MISSING ];
			$items[] = array(
				'id'     => (int) $id,
				'kind'   => self::MISSING,
				'detail' => self::missing_detail( $fields ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);

		return array(
			'items'  => $items,
			'totals' => $totals,
		);
	}

	/**
	 * Fields that differ between snapshot and site.
	 *
	 * @param array<string, int|string> $before Snapshot values.
	 * @param array<string, int|string> $after  Site values.
	 * @return array<string, mixed> Field => [before, after]; plus "shrank"
	 *                              when content got smaller. A changed
	 *                              "digest" means the content changed even
	 *                              if its size did not.
	 */
	public static function field_changes( array $before, array $after ) {
		$changes = array();

		foreach ( $before as $name => $value ) {
			if ( ! array_key_exists( $name, $after ) ) {
				continue;
			}

			$now = $after[ $name ];

			// Coded values are compared as strings, numbers as integers.
			$same = is_int( $value ) && is_int( $now ) ? $value === $now : (string) $value === (string) $now;

			if ( ! $same ) {
				$changes[ $name ] = array( $value, $now );
			}
		}

		if ( isset( $changes['length'] ) && (int) $changes['length'][1] < (int) $changes['length'][0] ) {
			$changes['shrank'] = true;
		}

		return $changes;
	}

	/**
	 * Detail kept for a missing record: its status-like values only.
	 *
	 * @param array<string, int|string> $fields Snapshot values.
	 * @return array<string, mixed>
	 */
	private static function missing_detail( array $fields ) {
		$keep = array();

		foreach ( array( 'status', 'role', 'ctype', 'approved' ) as $name ) {
			if ( isset( $fields[ $name ] ) && '' !== (string) $fields[ $name ] ) {
				$keep[ $name ] = (string) $fields[ $name ];
			}
		}

		return $keep;
	}

	/**
	 * Add one range's totals to running totals.
	 *
	 * @param array<string, int> $running Running totals.
	 * @param array<string, int> $range   Range totals.
	 * @return array<string, int>
	 */
	public static function add_totals( array $running, array $range ) {
		foreach ( self::empty_totals() as $key => $zero ) {
			$running[ $key ] = ( isset( $running[ $key ] ) ? (int) $running[ $key ] : $zero ) + ( isset( $range[ $key ] ) ? (int) $range[ $key ] : 0 );
		}

		return $running;
	}

	/**
	 * Decode a stored chunk into ID => named field values.
	 *
	 * @param array<string, mixed>  $chunk      Chunk row.
	 * @param array<string, int>    $kinds      Expected kinds.
	 * @param array<string, string> $coded     Coded fields => dictionary names.
	 * @param Dictionary            $dictionary Snapshot dictionary.
	 * @return array<int, array<string, int|string>>
	 */
	public static function decode_chunk( array $chunk, array $kinds, array $coded, Dictionary $dictionary ) {
		$decoded = Chunk_Codec::decode( (string) $chunk['payload'], (int) $chunk['row_count'], (string) $chunk['sha256'], $kinds );
		$out     = array();

		foreach ( $decoded['ids'] as $index => $id ) {
			$fields = array();
			foreach ( $kinds as $name => $kind ) {
				$value           = $decoded['fields'][ $name ][ $index ];
				$fields[ $name ] = isset( $coded[ $name ] ) ? $dictionary->value( $coded[ $name ], $value ) : $value;
			}
			$out[ $id ] = $fields;
		}

		return $out;
	}
}
