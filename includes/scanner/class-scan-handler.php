<?php
/**
 * The scan job.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Exception;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Phases:
 * 1. discover: read the site into a Scan_Context and decide which checks
 *    apply.
 * 2. checks:   one quick check, or one batch of a batched check, per step.
 * 3. finalize: summarise findings per category into the scan record.
 *
 * Every step only reads site tables and writes the plugin's own tables. A
 * check that throws is recorded as "could not complete" and the scan moves
 * on; one broken check never hides the others' results.
 */
final class Scan_Handler extends Job_Handler {

	const TYPE = 'scan';

	/**
	 * Scans.
	 *
	 * @var Scan_Repository
	 */
	private $scans;

	/**
	 * Findings.
	 *
	 * @var Finding_Repository
	 */
	private $findings;

	/**
	 * Create the handler.
	 *
	 * @param Scan_Repository|null    $scans    Scans.
	 * @param Finding_Repository|null $findings Findings.
	 */
	public function __construct( $scans = null, $findings = null ) {
		$this->scans    = $scans instanceof Scan_Repository ? $scans : new Scan_Repository();
		$this->findings = $findings instanceof Finding_Repository ? $findings : new Finding_Repository();
	}

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function type() {
		return self::TYPE;
	}

	/**
	 * Initial state.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return array<string, mixed>
	 */
	public function initial_state( array $params ) {
		unset( $params );

		return array(
			'phase'   => 'discover',
			'context' => array(),
			'checks'  => array(),
			'skipped' => array(),
			'i'       => 0,
			'cursor'  => null,
			'dropped' => array(),
			'scanned' => array(),
			'errors'  => array(),
			'started' => time(),
		);
	}

	/**
	 * One step.
	 *
	 * @param array<string, mixed> $params Params.
	 * @param array<string, mixed> $state  State.
	 * @return array{state: array<string, mixed>, progress: array<string, mixed>, done: bool}
	 * @throws Job_Exception When the scan record was deleted.
	 */
	public function step( array $params, array $state ) {
		$scan_id = (int) $params['scan_id'];

		if ( null === $this->scans->get( $scan_id ) ) {
			throw new Job_Exception( esc_html__( 'The scan being run was deleted.', 'unstoppable-migration-inspector' ) );
		}

		switch ( $state['phase'] ) {
			case 'discover':
				$state = $this->discover( $params, $state );
				break;

			case 'checks':
				$state = $this->run_check( $scan_id, $state );
				break;

			case 'finalize':
				$this->scans->finish( $scan_id, Scan_Repository::STATUS_COMPLETE, $this->summarize( $scan_id, $state ) );

				return array(
					'state'    => $state,
					'progress' => array( 'phase' => 'finalize' ),
					'done'     => true,
				);
		}

		return array(
			'state'    => $state,
			'progress' => $this->progress( $state ),
			'done'     => false,
		);
	}

	/**
	 * Discover phase.
	 *
	 * @param array<string, mixed> $params Params.
	 * @param array<string, mixed> $state  State.
	 * @return array<string, mixed>
	 */
	private function discover( array $params, array $state ) {
		Db_Info::reset();

		$data = Scan_Context::build( isset( $params['batch_size'] ) ? (int) $params['batch_size'] : 500 );

		if ( isset( $params['request_host'] ) ) {
			$data['request_host'] = (string) $params['request_host'];
		}
		if ( array_key_exists( 'request_https', $params ) ) {
			$data['request_https'] = null === $params['request_https'] ? null : (bool) $params['request_https'];
		}

		$context          = new Scan_Context( $data );
		$state['context'] = $data;
		$state['checks']  = array();
		$state['skipped'] = array();

		foreach ( Check_Registry::all() as $key => $check ) {
			$applicable = $check->applicable( $context );

			if ( true === $applicable ) {
				$state['checks'][] = $key;
			} else {
				$state['skipped'][ $key ] = (string) $applicable;
			}
		}

		$state['phase'] = empty( $state['checks'] ) ? 'finalize' : 'checks';
		$state['i']     = 0;

		return $state;
	}

	/**
	 * Run the current quick check, or one batch of the current batched check.
	 *
	 * @param int                  $scan_id Scan ID.
	 * @param array<string, mixed> $state   State.
	 * @return array<string, mixed>
	 */
	private function run_check( $scan_id, array $state ) {
		$key   = $state['checks'][ $state['i'] ];
		$check = Check_Registry::get( $key );

		if ( null === $check ) {
			return $this->advance( $state );
		}

		$context = new Scan_Context( (array) $state['context'] );

		try {
			if ( ! $check->is_batched() ) {
				$this->record_dropped( $state, $key, $this->findings->store( $scan_id, $check, 0, $check->run( $context ) ) );
				return $this->advance( $state );
			}

			$cursor = is_array( $state['cursor'] ) ? $state['cursor'] : $check->start( $context );
			$result = $check->batch( $context, $cursor );

			$this->record_dropped( $state, $key, $this->findings->store( $scan_id, $check, (int) $cursor['after'], $result['findings'] ) );
			$state['scanned'][ $key ] = ( isset( $state['scanned'][ $key ] ) ? (int) $state['scanned'][ $key ] : 0 ) + (int) $result['scanned'];

			if ( null === $result['cursor'] ) {
				return $this->advance( $state );
			}

			$state['cursor'] = $result['cursor'];
		} catch ( \Throwable $error ) {
			$state['errors'][ $key ] = $error instanceof Job_Exception
				? $error->getMessage()
				: __( 'This check stopped because of an unexpected error. Details were written to the PHP error log if debugging is enabled.', 'unstoppable-migration-inspector' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only when debugging is on; contains no credentials.
				error_log( 'Unstoppable Migration Inspector check ' . $key . ': ' . $error->getMessage() );
			}

			return $this->advance( $state );
		}

		return $state;
	}

	/**
	 * Move to the next check.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function advance( array $state ) {
		++$state['i'];
		$state['cursor'] = null;

		if ( $state['i'] >= count( $state['checks'] ) ) {
			$state['phase'] = 'finalize';
		}

		return $state;
	}

	/**
	 * Add counts of findings not stored because of the per-check limit.
	 *
	 * @param array<string, mixed> $state   State (by reference).
	 * @param string               $key     Check key.
	 * @param array<string, int>   $dropped Kind => count.
	 * @return void
	 */
	private function record_dropped( array &$state, $key, array $dropped ) {
		foreach ( $dropped as $kind => $count ) {
			$state['dropped'][ $key ][ $kind ] = ( isset( $state['dropped'][ $key ][ $kind ] ) ? (int) $state['dropped'][ $key ][ $kind ] : 0 ) + (int) $count;
		}
	}

	/**
	 * Progress for the screen.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function progress( array $state ) {
		if ( 'checks' !== $state['phase'] ) {
			return array( 'phase' => $state['phase'] );
		}

		$total = count( $state['checks'] );
		$check = Check_Registry::get( $state['checks'][ min( $state['i'], $total - 1 ) ] );
		$part  = 0.0;

		if ( is_array( $state['cursor'] ) && ! empty( $state['cursor']['max'] ) ) {
			$part = min( 1.0, (int) $state['cursor']['after'] / max( 1, (int) $state['cursor']['max'] ) );
		}

		return array(
			'phase'        => 'checks',
			'current'      => null === $check ? '' : $check->label(),
			'checks_done'  => (int) $state['i'],
			'checks_total' => $total,
			'percent'      => (int) floor( 100 * ( $state['i'] + $part ) / max( 1, $total ) ),
		);
	}

	/**
	 * Summary stored with a completed scan.
	 *
	 * @param int                  $scan_id Scan ID.
	 * @param array<string, mixed> $state   State.
	 * @return array<string, mixed>
	 */
	public function summarize( $scan_id, array $state ) {
		$all     = Check_Registry::all();
		$groups  = $this->findings->groups( $scan_id );
		$checks  = array();
		$by_cat  = array();
		$context = (array) $state['context'];

		foreach ( $all as $key => $check ) {
			$status = 'passed';

			if ( isset( $state['skipped'][ $key ] ) ) {
				$status = 'not_applicable';
			} elseif ( isset( $state['errors'][ $key ] ) ) {
				$status = 'error';
			} elseif ( ! in_array( $key, (array) $state['checks'], true ) ) {
				$status = 'not_tested';
			}

			$checks[ $key ] = array(
				'category' => $check->category(),
				'label'    => $check->label(),
				'status'   => $status,
				'reason'   => isset( $state['skipped'][ $key ] ) ? $state['skipped'][ $key ] : ( isset( $state['errors'][ $key ] ) ? $state['errors'][ $key ] : '' ),
				'counts'   => array(),
				'dropped'  => isset( $state['dropped'][ $key ] ) ? (array) $state['dropped'][ $key ] : array(),
				'scanned'  => isset( $state['scanned'][ $key ] ) ? (int) $state['scanned'][ $key ] : null,
			);
		}

		foreach ( $groups as $group ) {
			$key = $group['check_key'];

			if ( ! isset( $checks[ $key ] ) ) {
				continue;
			}

			$checks[ $key ]['counts'][ $group['severity'] ] = ( isset( $checks[ $key ]['counts'][ $group['severity'] ] ) ? $checks[ $key ]['counts'][ $group['severity'] ] : 0 ) + $group['total'];
		}

		foreach ( $checks as $key => $info ) {
			foreach ( $info['dropped'] as $kind => $count ) {
				$definition = $all[ $key ]->kind( $kind );
				$severity   = null === $definition ? Severity::NOTICE : $definition['severity'];

				$checks[ $key ]['counts'][ $severity ] = ( isset( $checks[ $key ]['counts'][ $severity ] ) ? $checks[ $key ]['counts'][ $severity ] : 0 ) + (int) $count;
			}

			if ( 'passed' === $checks[ $key ]['status'] && ( ! empty( $checks[ $key ]['counts'][ Severity::CRITICAL ] ) || ! empty( $checks[ $key ]['counts'][ Severity::WARNING ] ) ) ) {
				$checks[ $key ]['status'] = 'issues';
			}

			$by_cat[ $info['category'] ][] = $key;
		}

		$categories = array();

		foreach ( array_keys( Health_Summary::categories() ) as $category ) {
			$keys     = isset( $by_cat[ $category ] ) ? $by_cat[ $category ] : array();
			$counts   = array_fill_keys( Severity::all(), 0 );
			$statuses = array();

			foreach ( $keys as $key ) {
				$statuses[] = $checks[ $key ]['status'];
				foreach ( $checks[ $key ]['counts'] as $severity => $count ) {
					$counts[ $severity ] = ( isset( $counts[ $severity ] ) ? $counts[ $severity ] : 0 ) + (int) $count;
				}
			}

			$categories[ $category ] = array(
				'status'  => self::category_status( $statuses, $counts ),
				'counts'  => $counts,
				'issues'  => (int) $counts[ Severity::CRITICAL ] + (int) $counts[ Severity::WARNING ],
				'notices' => (int) $counts[ Severity::NOTICE ],
				'checks'  => $keys,
			);
		}

		$rows = array();
		foreach ( $categories as $category ) {
			$rows[] = array( 'status' => $category['status'] );
		}

		return array(
			'overall'       => Health_Summary::overall_status( $rows ),
			'categories'    => $categories,
			'checks'        => $checks,
			'home'          => isset( $context['home'] ) ? (string) $context['home'] : '',
			'old_locations' => isset( $context['old_locations'] ) ? (array) $context['old_locations'] : array(),
			'duration'      => max( 0, time() - (int) $state['started'] ),
			'limit'         => Finding_Repository::MAX_PER_CHECK,
		);
	}

	/**
	 * Category status from its checks.
	 *
	 * @param string[]           $statuses Check statuses.
	 * @param array<string, int> $counts   Severity counts.
	 * @return string Health_Summary status.
	 */
	public static function category_status( array $statuses, array $counts ) {
		if ( ! empty( $counts[ Severity::CRITICAL ] ) ) {
			return Health_Summary::STATUS_CRITICAL;
		}

		if ( ! empty( $counts[ Severity::WARNING ] ) ) {
			return Health_Summary::STATUS_WARNING;
		}

		$tested = array_diff( $statuses, array( 'not_applicable' ) );

		if ( empty( $tested ) ) {
			return Health_Summary::STATUS_NOT_APPLICABLE;
		}

		if ( in_array( 'error', $tested, true ) || in_array( 'not_tested', $tested, true ) ) {
			return Health_Summary::STATUS_NOT_TESTED;
		}

		return Health_Summary::STATUS_PASSED;
	}

	/**
	 * Mark the scan failed or cancelled.
	 *
	 * @param array<string, mixed> $params Params.
	 * @param array<string, mixed> $state  State.
	 * @param string               $status Job status.
	 * @return void
	 */
	public function on_stop( array $params, array $state, $status ) {
		unset( $state );

		$this->scans->finish( (int) $params['scan_id'], 'cancelled' === $status ? Scan_Repository::STATUS_CANCELLED : Scan_Repository::STATUS_FAILED );
	}
}
