<?php
/**
 * Bounded, read-only reads of large tables.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Two-step reading that keeps memory flat on any table size:
 *
 * 1. prefilter(): within an ID window, find rows whose text columns contain
 *    any of a few LIKE patterns. Returns IDs and sizes only, never content.
 * 2. fetch(): read the content of those rows in groups of at most
 *    GROUP_BYTES. Rows bigger than MAX_ROW_BYTES are not read at all and are
 *    reported as skipped.
 *
 * The ID window bounds the work of one query even when nothing matches.
 */
final class Row_Reader {

	/**
	 * Most bytes of content read in one query.
	 */
	const GROUP_BYTES = 2097152;

	/**
	 * Rows larger than this are skipped and reported.
	 */
	const MAX_ROW_BYTES = 8388608;

	/**
	 * Largest ID in a table.
	 *
	 * @param string $table     Table name.
	 * @param string $id_column ID column.
	 * @return int
	 */
	public static function max_id( $table, $id_column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only; identifiers are fixed by the calling check.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $id_column, $table ) );
	}

	/**
	 * Rows in (after, after + window] whose columns match any pattern.
	 *
	 * @param string   $table     Table name.
	 * @param string   $id_column ID column.
	 * @param string[] $columns   Text columns to match.
	 * @param string[] $patterns  LIKE patterns (already escaped with esc_like and wrapped in %).
	 * @param int      $after     Exclusive lower bound.
	 * @param int      $window    Width of the ID window.
	 * @param int      $limit     Most rows to return.
	 * @param string[] $extra     Extra small columns to return (meta_key, post_type…).
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 *         next is the cursor for the following call.
	 */
	public static function prefilter( $table, $id_column, array $columns, array $patterns, $after, $window, $limit, array $extra = array() ) {
		global $wpdb;

		$upper  = $after + max( 1, (int) $window );
		$args   = array( $id_column );
		$select = '%i AS id';

		foreach ( $extra as $column ) {
			$select .= ', %i';
			$args[]  = $column;
		}

		$lengths = array();
		foreach ( $columns as $column ) {
			$lengths[] = 'COALESCE(LENGTH(%i), 0)';
			$args[]    = $column;
		}
		$select .= ', (' . implode( ' + ', $lengths ) . ') AS len';

		$args[] = $table;
		$args[] = $id_column;
		$args[] = (int) $after;
		$args[] = $id_column;
		$args[] = (int) $upper;

		$likes = array();
		foreach ( $columns as $column ) {
			foreach ( $patterns as $pattern ) {
				$likes[] = '%i LIKE %s';
				$args[]  = $column;
				$args[]  = $pattern;
			}
		}

		$args[] = $id_column;
		$args[] = (int) $limit;

		$sql = 'SELECT ' . $select . ' FROM %i WHERE %i > %d AND %i <= %d AND (' . implode( ' OR ', $likes ) . ') ORDER BY %i ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only; $sql is built only from fixed %i/%s/%d placeholders (one per column or pattern) and every value goes through prepare().
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$next = count( $rows ) >= $limit ? (int) $rows[ count( $rows ) - 1 ]['id'] : $upper;

		return array(
			'rows' => $rows,
			'next' => $next,
		);
	}

	/**
	 * Read the columns of the given rows, in size-limited groups.
	 *
	 * @param string                           $table     Table name.
	 * @param string                           $id_column ID column.
	 * @param string[]                         $columns   Columns to read.
	 * @param array<int, array<string, mixed>> $rows      Rows from prefilter() (need id and len).
	 * @param callable                         $each      Called with (id, array column => value).
	 * @return int[] IDs skipped because they are too large.
	 */
	public static function fetch( $table, $id_column, array $columns, array $rows, callable $each ) {
		$skipped = array();
		$group   = array();
		$bytes   = 0;

		foreach ( $rows as $row ) {
			$length = (int) $row['len'];

			if ( $length > self::MAX_ROW_BYTES ) {
				$skipped[] = (int) $row['id'];
				continue;
			}

			if ( ! empty( $group ) && $bytes + $length > self::GROUP_BYTES ) {
				self::read_group( $table, $id_column, $columns, $group, $each );
				$group = array();
				$bytes = 0;
			}

			$group[] = (int) $row['id'];
			$bytes  += $length;
		}

		if ( ! empty( $group ) ) {
			self::read_group( $table, $id_column, $columns, $group, $each );
		}

		return $skipped;
	}

	/**
	 * Read one group of rows.
	 *
	 * @param string   $table     Table.
	 * @param string   $id_column ID column.
	 * @param string[] $columns   Columns.
	 * @param int[]    $ids       IDs.
	 * @param callable $each      Callback.
	 * @return void
	 */
	private static function read_group( $table, $id_column, array $columns, array $ids, callable $each ) {
		global $wpdb;

		$args   = array( $id_column );
		$select = '%i AS id';

		foreach ( $columns as $column ) {
			$select .= ', %i';
			$args[]  = $column;
		}

		$args[] = $table;
		$args[] = $id_column;
		$args   = array_merge( $args, array_map( 'intval', $ids ) );

		$sql = 'SELECT ' . $select . ' FROM %i WHERE %i IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only; $sql is built only from fixed %i/%d placeholders and every value goes through prepare().
		$result = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		foreach ( $result as $row ) {
			$id = (int) $row['id'];
			unset( $row['id'] );
			call_user_func( $each, $id, $row );
		}
	}

	/**
	 * A LIKE pattern matching a literal anywhere in a value.
	 *
	 * @param string $literal Text to find.
	 * @return string
	 */
	public static function contains( $literal ) {
		global $wpdb;

		return '%' . $wpdb->esc_like( $literal ) . '%';
	}
}
