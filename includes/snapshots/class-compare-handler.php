<?php
/**
 * Snapshot comparison job.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Jobs\Job_Exception;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a complete snapshot with the live site, in small steps.
 *
 * Phases: config (configuration differences), tables (one row count per
 * step), records (one snapshot chunk, or one batch of records newer than
 * the snapshot, per step), finalize.
 *
 * For each record type, snapshot chunks and live rows are walked in
 * ascending ID order. A step covers the ID range (previous chunk's last ID,
 * this chunk's last ID]; before writing results for a range, any results
 * already stored for that range are removed, so a repeated step after an
 * interruption cannot double count. Only the plugin's own tables are
 * written.
 */
final class Compare_Handler extends Job_Handler {

	const TYPE = 'snapshot_compare';

	/**
	 * Most live rows read inside one chunk's range per query.
	 */
	const RANGE_PAGE = 1000;

	/**
	 * Snapshot storage.
	 *
	 * @var Snapshot_Repository
	 */
	private $snapshots;

	/**
	 * Comparison storage.
	 *
	 * @var Comparison_Repository
	 */
	private $comparisons;

	/**
	 * Constructor.
	 *
	 * @param Snapshot_Repository|null   $snapshots   Snapshot storage.
	 * @param Comparison_Repository|null $comparisons Comparison storage.
	 */
	public function __construct( $snapshots = null, $comparisons = null ) {
		$this->snapshots   = $snapshots ? $snapshots : new Snapshot_Repository();
		$this->comparisons = $comparisons ? $comparisons : new Comparison_Repository();
	}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function type() {
		return self::TYPE;
	}

	/**
	 * Initial state.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function initial_state( array $params ) {
		unset( $params );

		return array(
			'phase'      => 'config',
			'table_i'    => 0,
			'type_i'     => 0,
			'seq'        => 0,
			'lo'         => 0,
			'totals'     => array(),
			'order_mode' => '',
		);
	}

	/**
	 * Run one step.
	 *
	 * @param array<string, mixed> $params Parameters: snapshot_id, comparison_id, batch_size.
	 * @param array<string, mixed> $state  State.
	 * @return array{state: array<string, mixed>, done: bool, progress: array<string, mixed>}
	 * @throws Job_Exception When the snapshot or comparison is gone or unusable.
	 */
	public function step( array $params, array $state ) {
		$snapshot      = $this->snapshots->get( (int) $params['snapshot_id'] );
		$comparison_id = (int) $params['comparison_id'];
		$comparison    = $this->comparisons->get( $comparison_id );

		if ( null === $snapshot || null === $comparison ) {
			throw new Job_Exception( esc_html__( 'The snapshot or comparison was deleted.', 'unstoppable-migration-inspector' ) );
		}

		if ( Snapshot_Repository::STATUS_COMPLETE !== $snapshot['status'] ) {
			throw new Job_Exception( esc_html__( 'Only a complete snapshot can be compared.', 'unstoppable-migration-inspector' ) );
		}

		$header  = $snapshot['header'];
		$summary = $comparison['summary'];

		switch ( $state['phase'] ) {
			case 'config':
				$storage             = Woo_Storage::detect();
				$state['order_mode'] = $storage['mode'];
				$summary             = array(
					'snapshot_time'      => (int) $header['created_at'],
					'compared_at'        => time(),
					'order_storage'      => array(
						'snapshot' => isset( $header['order_mode'] ) ? (string) $header['order_mode'] : Woo_Storage::MODE_NONE,
						'site'     => $storage['mode'],
					),
					'config'             => Config_Diff::compare( isset( $header['config'] ) ? (array) $header['config'] : array(), Site_Profile::config(), self::roles_ok() ),
					'identity'           => array_merge(
						Identity_Check::assess( (int) $params['snapshot_id'], $header ),
						array( 'acknowledged' => ! empty( $params['acknowledged'] ) )
					),
					'tables'             => array(),
					'types'              => array(),
					'types_only_on_site' => self::types_only_on_site( $header ),
				);
				$this->comparisons->save_summary( $comparison_id, $summary );
				$state['phase'] = 'tables';
				break;

			case 'tables':
				$tables = isset( $header['tables'] ) ? array_keys( (array) $header['tables'] ) : array();
				sort( $tables );

				if ( $state['table_i'] < count( $tables ) ) {
					$name                       = $tables[ $state['table_i'] ];
					$summary['tables'][ $name ] = self::compare_table( $name, (array) $header['tables'][ $name ], (string) $header['config']['table_prefix'] );
					$this->comparisons->save_summary( $comparison_id, $summary );
					++$state['table_i'];
				}

				if ( $state['table_i'] >= count( $tables ) ) {
					$state['phase'] = 'records';
				}
				break;

			case 'records':
				$state = $this->compare_step( (int) $params['snapshot_id'], $comparison_id, $header, $params, $state );
				break;

			case 'finalize':
				$summary['types'] = $state['totals'];
				$this->comparisons->save_summary( $comparison_id, $summary );
				return array(
					'state'    => $state,
					'done'     => true,
					'progress' => $this->progress( $header, $state ),
				);
		}

		return array(
			'state'    => $state,
			'done'     => false,
			'progress' => $this->progress( $header, $state ),
		);
	}

	/**
	 * One records step: one snapshot chunk's range, or one batch past the
	 * end of the snapshot.
	 *
	 * @param int                  $snapshot_id   Snapshot ID.
	 * @param int                  $comparison_id Comparison ID.
	 * @param array<string, mixed> $header        Snapshot header.
	 * @param array<string, mixed> $params        Parameters.
	 * @param array<string, mixed> $state         State.
	 * @return array<string, mixed> New state.
	 * @throws Job_Exception When a snapshot chunk is missing or damaged.
	 */
	private function compare_step( $snapshot_id, $comparison_id, array $header, array $params, array $state ) {
		$keys = array_keys( (array) $header['types'] );

		if ( $state['type_i'] >= count( $keys ) ) {
			$state['phase'] = 'finalize';
			return $state;
		}

		$key        = $keys[ $state['type_i'] ];
		$type       = $header['types'][ $key ];
		$definition = array(
			'key'    => $key,
			'source' => $type['source'],
			'arg'    => $type['arg'],
		);
		$source     = Source_Registry::make( $definition, (string) $state['order_mode'], isset( $header['content_salt'] ) ? (string) $header['content_salt'] : '' );

		if ( null === $source ) {
			return $this->next_type( $state );
		}

		if ( ! isset( $state['totals'][ $key ] ) ) {
			$state['totals'][ $key ] = Comparator::empty_totals();
		}

		$batch_size = max( 1, min( Chunk_Codec::MAX_RECORDS, (int) $params['batch_size'] ) );
		$lo         = (int) $state['lo'];

		if ( $state['seq'] < (int) $type['chunks'] ) {
			$chunk = $this->snapshots->get_chunk( $snapshot_id, $key, (int) $state['seq'] );

			if ( null === $chunk ) {
				throw new Job_Exception( esc_html__( 'Part of the snapshot is missing. The snapshot cannot be compared; take or import it again.', 'unstoppable-migration-inspector' ) );
			}

			try {
				$records = Comparator::decode_chunk(
					$chunk,
					$source->kinds(),
					$source->coded_fields(),
					new Dictionary( isset( $header['dictionaries'] ) ? (array) $header['dictionaries'] : array() )
				);
			} catch ( \UnexpectedValueException $error ) {
				throw new Job_Exception( esc_html__( 'Part of the snapshot is damaged (its checksum does not match). The snapshot cannot be compared; take or import it again.', 'unstoppable-migration-inspector' ) );
			}

			$hi   = (int) $chunk['last_id'];
			$site = $this->fetch_range( $source, $lo, $hi );

			$result = Comparator::compare_range( $records, $site, (int) $header['created_at'] );

			$this->comparisons->delete_range( $comparison_id, $key, $lo, $hi );
			$this->comparisons->insert_items( $comparison_id, $key, $result['items'] );

			$state['totals'][ $key ] = Comparator::add_totals( $state['totals'][ $key ], $result['totals'] );
			$state['lo']             = $hi;
			++$state['seq'];

			return $state;
		}

		// Past the snapshot's last ID: everything here is new or not in the snapshot.
		$site = $source->fetch_after( $lo, $batch_size );

		if ( empty( $site ) ) {
			return $this->next_type( $state );
		}

		$hi     = (int) $site[ count( $site ) - 1 ]['id'];
		$result = Comparator::compare_range( array(), $site, (int) $header['created_at'] );

		$this->comparisons->delete_range( $comparison_id, $key, $lo, $hi );
		$this->comparisons->insert_items( $comparison_id, $key, $result['items'] );

		$state['totals'][ $key ] = Comparator::add_totals( $state['totals'][ $key ], $result['totals'] );
		$state['lo']             = $hi;

		return $state;
	}

	/**
	 * All live rows in (lo, hi], read in pages.
	 *
	 * @param Record_Source $source Source.
	 * @param int           $lo     Exclusive lower bound.
	 * @param int           $hi     Inclusive upper bound.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_range( Record_Source $source, $lo, $hi ) {
		$rows   = array();
		$cursor = $lo;

		do {
			$page      = $source->fetch_after( $cursor, self::RANGE_PAGE, $hi );
			$page_size = count( $page );
			foreach ( $page as $row ) {
				$rows[] = $row;
			}
			$cursor = 0 === $page_size ? $hi : (int) $page[ $page_size - 1 ]['id'];
		} while ( self::RANGE_PAGE === $page_size && $cursor < $hi );

		return $rows;
	}

	/**
	 * Move to the next record type.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function next_type( array $state ) {
		++$state['type_i'];
		$state['seq'] = 0;
		$state['lo']  = 0;

		return $state;
	}

	/**
	 * Whether this site's table prefix has its user roles option and at
	 * least one user with a role, i.e. a prefix change was completed.
	 *
	 * @return bool
	 */
	private static function roles_ok() {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only presence checks.
		$option = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $prefix . 'user_roles' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only presence checks.
		$caps = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", $prefix . 'capabilities' ) );

		return null !== $option && null !== $caps;
	}

	/**
	 * Whether a table holds rows that belong to tracked records (posts and
	 * their fields, comments, terms links, users, WooCommerce orders and
	 * lookups), so it shrinks when those records are deleted or missing.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function is_record_table( $table ) {
		return (bool) preg_match( '/(^|_)(posts|postmeta|comments|commentmeta|term_relationships|termmeta|users|usermeta|wc_orders|wc_orders_meta|wc_order_[a-z_]+|woocommerce_order_items|woocommerce_order_itemmeta|wc_product_meta_lookup|wc_customer_lookup|wc_product_attributes_lookup|wc_reserved_stock)$/', (string) $table );
	}

	/**
	 * Whether a table (name without prefix) belongs to a backup, migration
	 * or staging tool, whose contents normally differ between copies.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function is_tool_table( $table ) {
		return (bool) preg_match( '/(wpvivid|wpstg|wp_staging|duplicator|ai1wm|updraft|backwpup|wpmdb|blogvault|bv_|migrateguru|xcloner|backuply|jetbackup)/i', (string) $table );
	}

	/**
	 * Compare one table's row count with the snapshot.
	 *
	 * The snapshot's table prefix is swapped for this site's, so a migration
	 * that changed the prefix still compares the right tables.
	 *
	 * @param string               $snapshot_name   Table name in the snapshot.
	 * @param array<string, mixed> $before          Snapshot count data.
	 * @param string               $snapshot_prefix Snapshot table prefix.
	 * @return array<string, mixed>
	 */
	private static function compare_table( $snapshot_name, array $before, $snapshot_prefix ) {
		global $wpdb;

		$site_name = $snapshot_name;
		if ( '' !== $snapshot_prefix && 0 === strpos( $snapshot_name, $snapshot_prefix ) ) {
			$site_name = $wpdb->prefix . substr( $snapshot_name, strlen( $snapshot_prefix ) );
		}

		$result = array(
			'site_table' => $site_name,
			'before'     => isset( $before['rows'] ) ? (int) $before['rows'] : 0,
			'after'      => 0,
			'approx'     => ! empty( $before['approximate'] ),
			'status'     => 'ok',
		);

		if ( ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $site_name ) || ! in_array( $site_name, Site_Profile::tables(), true ) ) {
			$result['status'] = 'missing';
			return $result;
		}

		$count            = Site_Profile::count_rows( $site_name );
		$result['after']  = $count['rows'];
		$result['approx'] = $result['approx'] || $count['approximate'];

		if ( $result['after'] < $result['before'] ) {
			$drop             = $result['before'] - $result['after'];
			$result['status'] = ( $drop >= 1 && $drop > $result['before'] * 0.01 ) ? 'decreased' : 'ok';
		} elseif ( $result['after'] > $result['before'] ) {
			$result['status'] = 'increased';
		}

		return $result;
	}

	/**
	 * Record types present on this site but not in the snapshot.
	 *
	 * @param array<string, mixed> $header Snapshot header.
	 * @return string[]
	 */
	private static function types_only_on_site( array $header ) {
		$known = array_keys( isset( $header['types'] ) ? (array) $header['types'] : array() );
		$out   = array();

		foreach ( Source_Registry::discover() as $definition ) {
			if ( ! in_array( $definition['key'], $known, true ) ) {
				$out[] = $definition['key'];
			}
		}

		return $out;
	}

	/**
	 * Progress for the screen.
	 *
	 * @param array<string, mixed> $header Header.
	 * @param array<string, mixed> $state  State.
	 * @return array<string, mixed>
	 */
	private function progress( array $header, array $state ) {
		$types   = array_keys( isset( $header['types'] ) ? (array) $header['types'] : array() );
		$current = isset( $types[ $state['type_i'] ] ) ? $types[ $state['type_i'] ] : '';

		return array(
			'phase'       => $state['phase'],
			'types_done'  => min( (int) $state['type_i'], count( $types ) ),
			'types_total' => count( $types ),
			'current'     => '' === $current ? '' : Source_Registry::label( $current ),
		);
	}

	/**
	 * Mark the comparison complete.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param array<string, mixed> $state  State.
	 * @return void
	 */
	public function on_complete( array $params, array $state ) {
		unset( $state );
		$this->comparisons->set_status( (int) $params['comparison_id'], 'complete' );
	}

	/**
	 * Mark the comparison cancelled or failed.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param array<string, mixed> $state  State.
	 * @param string               $status Final status.
	 * @return void
	 */
	public function on_stop( array $params, array $state, $status ) {
		unset( $state );
		$this->comparisons->set_status( (int) $params['comparison_id'], $status );
	}
}
