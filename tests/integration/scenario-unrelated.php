<?php
/**
 * Unrelated-site scenario: a snapshot from another site must not be
 * compared without an explicit acknowledgment.
 *
 * Usage: wp eval-file scenario-unrelated.php <export file from another site>
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

// phpcs:disable -- Test harness, not shipped.

use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Service;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Transfer;

require __DIR__ . '/lib.php';

wp_set_current_user( 1 );
echo "== Scenario: snapshot from an unrelated site ==\n";

$imported = ( new Snapshot_Transfer() )->import( file_get_contents( $args[0] ) );
umin_t_check( 'Foreign snapshot imports (import itself is not blocked)', $imported > 0 );

$refused = Snapshot_Service::start_compare( $imported );
umin_t_check( 'Comparison is refused without acknowledgment', is_wp_error( $refused ) && 'umin_identity_unconfirmed' === $refused->get_error_code() );
if ( is_wp_error( $refused ) ) {
	$data = $refused->get_error_data();
	umin_t_same( 'Refusal says the snapshot looks like another site', 'likely_unrelated', $data['verdict'] );
	umin_t_check( 'Refusal explains why', ! empty( $data['reasons'] ) );
	umin_t_same( 'Refusal uses HTTP 409', 409, $data['status'] );
}

$started = Snapshot_Service::start_compare( $imported, true );
umin_t_check( 'Comparison runs after acknowledgment', ! is_wp_error( $started ) );
if ( ! is_wp_error( $started ) ) {
	umin_t_same( 'Acknowledged comparison completes', 'completed', umin_t_run_job( $started['job_id'] )['status'] );
	$summary = umin_t_summary( $started['comparison_id'] );
	umin_t_same( 'Results are marked as not matched to this site', 'likely_unrelated', $summary['identity']['verdict'] );
	umin_t_check( 'Results record that the admin acknowledged it', true === $summary['identity']['acknowledged'] );
}

umin_t_done();
