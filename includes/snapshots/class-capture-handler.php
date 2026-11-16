<?php
/**
 * Snapshot capture job.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Jobs\Job_Exception;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Captures a snapshot in small steps.
 *
 * Phases: discover (configuration and record types), tables (one row count
 * per step), records (one batch of one type per step), finalize (totals and
 * checksum). Every step only reads site tables and writes the plugin's own
 * snapshot tables. Repeating a step after an interruption produces the same
 * chunk again (stored with REPLACE), so an interrupted capture resumes to
 * the same result as an uninterrupted one, provided site data did not change
 * in between.
 */
final class Capture_Handler extends Job_Handler {

	const TYPE = 'snapshot_capture';

	/**
	 * Snapshot storage.
	 *
	 * @var Snapshot_Repository
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param Snapshot_Repository|null $snapshots Snapshot storage.
	 */
	public function __construct( $snapshots = null ) {
		$this->snapshots = $snapshots ? $snapshots : new Snapshot_Repository();
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
			'phase'   => 'discover',
			'table_i' => 0,
			'type_i'  => 0,
			'last_id' => 0,
			'seq'     => 0,
			'records' => 0,
		);
	}

	/**
	 * Run one step.
	 *
	 * @param array<string, mixed> $params Parameters: snapshot_id, batch_size.
	 * @param array<string, mixed> $state  State.
	 * @return array{state: array<string, mixed>, done: bool, progress: array<string, mixed>}
	 * @throws Job_Exception When the snapshot is gone.
	 */
	public function step( array $params, array $state ) {
		$snapshot_id = (int) $params['snapshot_id'];
		$snapshot    = $this->snapshots->get( $snapshot_id );

		if ( null === $snapshot ) {
			throw new Job_Exception( esc_html__( 'The snapshot being captured was deleted.', 'unstoppable-migration-inspector' ) );
		}

		$header = $snapshot['header'];

		switch ( $state['phase'] ) {
			case 'discover':
				$header['config']       = Site_Profile::config();
				$header['identity']     = Site_Identity::for_header();
				$header['content_salt'] = bin2hex( random_bytes( 8 ) );
				$header['types']        = array();
				$header['dictionaries'] = array();
				$header['table_list']   = Site_Profile::tables();
				$header['tables']       = array();

				foreach ( Source_Registry::discover() as $definition ) {
					$header['types'][ $definition['key'] ] = array(
						'source' => $definition['source'],
						'arg'    => $definition['arg'],
						'count'  => 0,
						'chunks' => 0,
					);
				}

				$header['order_mode'] = $header['config']['woocommerce']['storage'];
				$this->snapshots->save_header( $snapshot_id, $header );
				$state['phase'] = 'tables';
				break;

			case 'tables':
				$list = isset( $header['table_list'] ) ? (array) $header['table_list'] : array();

				if ( $state['table_i'] < count( $list ) ) {
					$table                      = $list[ $state['table_i'] ];
					$header['tables'][ $table ] = Site_Profile::count_rows( $table );
					$this->snapshots->save_header( $snapshot_id, $header );
					++$state['table_i'];
				}

				if ( $state['table_i'] >= count( $list ) ) {
					$state['phase'] = 'records';
				}
				break;

			case 'records':
				$state = $this->capture_batch( $snapshot_id, $header, $params, $state );
				break;

			case 'finalize':
				$this->finalize( $snapshot_id, $header );
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
	 * Capture one batch of the current record type.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param array<string, mixed> $header      Header (updated and saved).
	 * @param array<string, mixed> $params      Parameters.
	 * @param array<string, mixed> $state       State.
	 * @return array<string, mixed> New state.
	 */
	private function capture_batch( $snapshot_id, array $header, array $params, array $state ) {
		$keys = array_keys( $header['types'] );

		if ( $state['type_i'] >= count( $keys ) ) {
			$state['phase'] = 'finalize';
			return $state;
		}

		$key        = $keys[ $state['type_i'] ];
		$definition = array(
			'key'    => $key,
			'source' => $header['types'][ $key ]['source'],
			'arg'    => $header['types'][ $key ]['arg'],
		);
		$source     = Source_Registry::make( $definition, (string) $header['order_mode'], isset( $header['content_salt'] ) ? (string) $header['content_salt'] : '' );
		$batch_size = max( 1, min( Chunk_Codec::MAX_RECORDS, (int) $params['batch_size'] ) );
		$rows       = null === $source ? array() : $source->fetch_after( (int) $state['last_id'], $batch_size );

		if ( empty( $rows ) ) {
			// Type finished. Remove any chunk left from an earlier attempt
			// beyond the last one written in this attempt.
			$this->snapshots->delete_chunks_from( $snapshot_id, $key, (int) $state['seq'] );
			++$state['type_i'];
			$state['last_id'] = 0;
			$state['seq']     = 0;
			return $state;
		}

		$dictionary = new Dictionary( isset( $header['dictionaries'] ) ? (array) $header['dictionaries'] : array() );
		$encoded    = self::encode_rows( $source, $rows, $dictionary );

		$this->snapshots->put_chunk( $snapshot_id, $key, (int) $state['seq'], $encoded );

		$header['dictionaries'] = $dictionary->to_array();
		$this->snapshots->save_header( $snapshot_id, $header );

		$state['last_id']  = $encoded['last_id'];
		$state['records'] += $encoded['count'];
		++$state['seq'];

		return $state;
	}

	/**
	 * Encode fetched rows into a chunk.
	 *
	 * @param Record_Source                    $source     Source.
	 * @param array<int, array<string, mixed>> $rows       Rows from fetch_after().
	 * @param Dictionary                       $dictionary Dictionary (updated).
	 * @return array<string, mixed> Encoded chunk.
	 */
	public static function encode_rows( Record_Source $source, array $rows, Dictionary $dictionary ) {
		$kinds  = $source->kinds();
		$coded  = $source->coded_fields();
		$ids    = array();
		$fields = array_fill_keys( array_keys( $kinds ), array() );

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];

			foreach ( $kinds as $name => $kind ) {
				$value = $row['fields'][ $name ];

				if ( isset( $coded[ $name ] ) ) {
					$value = $dictionary->code( $coded[ $name ], (string) $value );
				}

				$fields[ $name ][] = (int) $value;
			}
		}

		return Chunk_Codec::encode( $ids, $fields, $kinds );
	}

	/**
	 * Final totals, revision count and checksum.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param array<string, mixed> $header      Header.
	 * @return void
	 */
	private function finalize( $snapshot_id, array $header ) {
		$totals = $this->snapshots->type_totals( $snapshot_id );

		foreach ( $header['types'] as $key => $type ) {
			$header['types'][ $key ]['count']  = isset( $totals[ $key ] ) ? $totals[ $key ]['records'] : 0;
			$header['types'][ $key ]['chunks'] = isset( $totals[ $key ] ) ? $totals[ $key ]['chunks'] : 0;
		}

		$header['revisions'] = Site_Profile::revision_count();
		unset( $header['table_list'] );

		$chunks = array();
		$after  = 0;
		do {
			$page      = $this->snapshots->chunk_index( $snapshot_id, $after, 1000 );
			$page_size = count( $page );
			foreach ( $page as $row ) {
				$chunks[] = $row;
				$after    = (int) $row['id'];
			}
		} while ( 1000 === $page_size );

		$checksum = Snapshot_Repository::checksum( $header, $chunks );

		$this->snapshots->save_header( $snapshot_id, $header );
		$this->snapshots->set_status( $snapshot_id, Snapshot_Repository::STATUS_COMPLETE, $checksum );
	}

	/**
	 * Progress for the screen.
	 *
	 * @param array<string, mixed> $header Header.
	 * @param array<string, mixed> $state  State.
	 * @return array<string, mixed>
	 */
	private function progress( array $header, array $state ) {
		$types   = isset( $header['types'] ) ? array_keys( (array) $header['types'] ) : array();
		$current = isset( $types[ $state['type_i'] ] ) ? $types[ $state['type_i'] ] : '';

		return array(
			'phase'        => $state['phase'],
			'types_done'   => min( (int) $state['type_i'], count( $types ) ),
			'types_total'  => count( $types ),
			'current'      => '' === $current ? '' : Source_Registry::label( $current ),
			'records'      => (int) $state['records'],
			'tables_done'  => (int) $state['table_i'],
			'tables_total' => isset( $header['table_list'] ) ? count( (array) $header['table_list'] ) : count( isset( $header['tables'] ) ? (array) $header['tables'] : array() ),
		);
	}

	/**
	 * Cancelled or failed: a partial snapshot is never usable, so it is
	 * marked failed (kept so the admin can see what happened and delete it).
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param array<string, mixed> $state  State.
	 * @param string               $status Final status.
	 * @return void
	 */
	public function on_stop( array $params, array $state, $status ) {
		unset( $state, $status );
		$this->snapshots->set_status( (int) $params['snapshot_id'], Snapshot_Repository::STATUS_FAILED );
	}
}
