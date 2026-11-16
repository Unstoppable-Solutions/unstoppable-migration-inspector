<?php
/**
 * Migration Snapshots screen.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparison_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Identity_Check;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Transfer;
use UnstoppableSolutions\MigrationInspector\Snapshots\Source_Registry;
use UnstoppableSolutions\MigrationInspector\Snapshots\Woo_Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Lists snapshots, starts captures and comparisons, and shows results.
 *
 * Buttons that start or resume work are handled by assets/js/snapshots.js,
 * which drives the job one batch at a time through the REST API.
 */
final class Snapshots_Page {

	/**
	 * Rows per page in item lists.
	 */
	const PER_PAGE = 100;

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_view() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-migration-inspector' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view selection; every value is validated.
		$comparison_id = isset( $_GET['comparison'] ) ? absint( wp_unslash( $_GET['comparison'] ) ) : 0;
		$type          = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$kind          = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap umin-wrap">';

		$notice = Snapshot_Downloads::take_notice();
		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}

		if ( $comparison_id > 0 ) {
			self::render_comparison( $comparison_id, $type, $kind, $paged );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * The snapshot list with capture and import panels.
	 *
	 * @return void
	 */
	private static function render_list() {
		$snapshots   = new Snapshot_Repository();
		$comparisons = new Comparison_Repository();
		$store       = new Job_Store();
		$rows        = $snapshots->all();
		?>
		<h1 class="umin-title"><?php esc_html_e( 'Migration Snapshots', 'unstoppable-migration-inspector' ); ?></h1>
		<p class="umin-intro">
			<?php esc_html_e( 'A snapshot records which posts, pages, media, users, comments, terms and WooCommerce orders this site has, plus key settings. Take one before you move the site. After the move, compare it with the new site to find exactly which records did not arrive.', 'unstoppable-migration-inspector' ); ?>
		</p>

		<div class="umin-panel umin-snapshot-actions">
			<h2><?php esc_html_e( 'Take a snapshot of this site', 'unstoppable-migration-inspector' ); ?></h2>
			<p><?php esc_html_e( 'Snapshots store record IDs, statuses, dates, content sizes and settings. They never contain names, email addresses, post content, order details or payment information.', 'unstoppable-migration-inspector' ); ?></p>
			<p class="description"><?php esc_html_e( 'For the most accurate result, take the snapshot right before the move, when no new orders or posts are being created. Large sites are processed in small steps; keep this page open until it finishes. If you close it, the snapshot pauses and can be resumed.', 'unstoppable-migration-inspector' ); ?></p>
			<p class="umin-inline-form">
				<label for="umin-snapshot-label"><?php esc_html_e( 'Label (optional)', 'unstoppable-migration-inspector' ); ?></label>
				<input type="text" id="umin-snapshot-label" class="regular-text" maxlength="190" placeholder="<?php esc_attr_e( 'For example: Before moving to new host', 'unstoppable-migration-inspector' ); ?>" />
				<button type="button" class="button button-primary" data-umin-action="capture"><?php esc_html_e( 'Take Snapshot', 'unstoppable-migration-inspector' ); ?></button>
			</p>
			<?php self::render_progress(); ?>
		</div>

		<div class="umin-panel">
			<h2><?php esc_html_e( 'Import a snapshot from another site', 'unstoppable-migration-inspector' ); ?></h2>
			<p><?php esc_html_e( 'Use this when the snapshot was taken on the old site and exported as a file. Only import files you exported yourself or received from someone you trust.', 'unstoppable-migration-inspector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="umin-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( Snapshot_Downloads::IMPORT_ACTION ); ?>" />
				<?php wp_nonce_field( Snapshot_Downloads::IMPORT_ACTION ); ?>
				<label for="umin-snapshot-file" class="screen-reader-text"><?php esc_html_e( 'Snapshot file', 'unstoppable-migration-inspector' ); ?></label>
				<input type="file" id="umin-snapshot-file" name="umin_snapshot_file" accept=".json,application/json" required />
				<button type="submit" class="button"><?php esc_html_e( 'Import Snapshot', 'unstoppable-migration-inspector' ); ?></button>
				<span class="description">
					<?php
					printf(
						/* translators: %s: maximum file size. */
						esc_html__( 'Maximum size: %s.', 'unstoppable-migration-inspector' ),
						esc_html( size_format( min( Snapshot_Transfer::max_import_bytes(), wp_max_upload_size() ) ) )
					);
					?>
				</span>
			</form>
		</div>

		<h2 class="umin-section-title"><?php esc_html_e( 'Snapshots', 'unstoppable-migration-inspector' ); ?></h2>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No snapshots yet.', 'unstoppable-migration-inspector' ); ?></p>
		<?php else : ?>
			<table class="widefat striped umin-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Snapshot', 'unstoppable-migration-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Taken', 'unstoppable-migration-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Site', 'unstoppable-migration-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Records', 'unstoppable-migration-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'unstoppable-migration-inspector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'unstoppable-migration-inspector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $row ) {
						self::render_snapshot_row( $row, $comparisons, $store );
					}
					?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * One snapshot row.
	 *
	 * @param array<string, mixed>  $row         Snapshot.
	 * @param Comparison_Repository $comparisons Comparisons.
	 * @param Job_Store             $store       Jobs.
	 * @return void
	 */
	private static function render_snapshot_row( array $row, Comparison_Repository $comparisons, Job_Store $store ) {
		$header  = $row['header'];
		$records = 0;
		foreach ( isset( $header['types'] ) ? (array) $header['types'] : array() as $type ) {
			$records += isset( $type['count'] ) ? (int) $type['count'] : 0;
		}

		$job    = isset( $header['job_id'] ) ? $store->get( (int) $header['job_id'] ) : null;
		$status = self::snapshot_status( $row, $job, $store );
		$latest = $comparisons->ids_for_snapshot( $row['id'] );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $row['label'] ); ?></strong><br />
				<span class="description">
					<?php echo 'imported' === $row['origin'] ? esc_html__( 'Imported', 'unstoppable-migration-inspector' ) : esc_html__( 'Taken on this site', 'unstoppable-migration-inspector' ); ?>
				</span>
			</td>
			<td><?php echo esc_html( self::format_time( strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
			<td><?php echo esc_html( (string) $row['source_home'] ); ?></td>
			<td><?php echo esc_html( Snapshot_Repository::STATUS_COMPLETE === $row['status'] ? number_format_i18n( $records ) : '—' ); ?></td>
			<td><?php echo esc_html( $status['label'] ); ?></td>
			<td class="umin-actions">
				<?php if ( Snapshot_Repository::STATUS_COMPLETE === $row['status'] ) : ?>
					<button type="button" class="button button-primary" data-umin-action="compare" data-umin-snapshot="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Compare with This Site', 'unstoppable-migration-inspector' ); ?></button>
					<a class="button" href="<?php echo esc_url( Snapshot_Downloads::export_url( $row['id'] ) ); ?>"><?php esc_html_e( 'Export', 'unstoppable-migration-inspector' ); ?></a>
				<?php elseif ( $status['resumable'] ) : ?>
					<button type="button" class="button" data-umin-action="resume" data-umin-job="<?php echo esc_attr( (string) $job['id'] ); ?>"><?php esc_html_e( 'Resume', 'unstoppable-migration-inspector' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $latest ) ) : ?>
					<a class="button" href="<?php echo esc_url( Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG, array( 'comparison' => $latest[0] ) ) ); ?>"><?php esc_html_e( 'Latest Comparison', 'unstoppable-migration-inspector' ); ?></a>
				<?php endif; ?>
				<button type="button" class="button button-link-delete" data-umin-action="delete" data-umin-snapshot="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Delete', 'unstoppable-migration-inspector' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Status label for a snapshot.
	 *
	 * @param array<string, mixed>      $row   Snapshot.
	 * @param array<string, mixed>|null $job   Capture job.
	 * @param Job_Store                 $store Jobs.
	 * @return array{label: string, resumable: bool}
	 */
	private static function snapshot_status( array $row, $job, Job_Store $store ) {
		if ( Snapshot_Repository::STATUS_COMPLETE === $row['status'] ) {
			return array(
				'label'     => __( 'Complete', 'unstoppable-migration-inspector' ),
				'resumable' => false,
			);
		}

		if ( Snapshot_Repository::STATUS_FAILED === $row['status'] || null === $job || Job_Store::is_finished( $job ) ) {
			$message = null !== $job && '' !== (string) $job['error'] ? ' ' . $job['error'] : '';
			return array(
				'label'     => __( 'Incomplete (stopped). Delete it and take a new snapshot.', 'unstoppable-migration-inspector' ) . $message,
				'resumable' => false,
			);
		}

		$display = $store->display_status( $job );

		return array(
			'label'     => 'running' === $display
				? __( 'In progress in another window', 'unstoppable-migration-inspector' )
				: __( 'Paused. Resume to finish it.', 'unstoppable-migration-inspector' ),
			'resumable' => true,
		);
	}

	/**
	 * Progress area used by the script.
	 *
	 * @return void
	 */
	private static function render_progress() {
		?>
		<div class="umin-progress" data-umin-progress hidden>
			<progress max="100" value="0" data-umin-progress-bar></progress>
			<p class="umin-progress__text" data-umin-progress-text role="status" aria-live="polite"></p>
			<button type="button" class="button" data-umin-action="cancel" hidden><?php esc_html_e( 'Cancel', 'unstoppable-migration-inspector' ); ?></button>
		</div>
		<?php
	}

	/**
	 * A comparison: summary, or one list of records.
	 *
	 * @param int    $comparison_id Comparison ID.
	 * @param string $type          Selected type key.
	 * @param string $kind          Selected bucket.
	 * @param int    $paged         Page.
	 * @return void
	 */
	private static function render_comparison( $comparison_id, $type, $kind, $paged ) {
		$comparisons = new Comparison_Repository();
		$comparison  = $comparisons->get( $comparison_id );
		$snapshots   = new Snapshot_Repository();
		$snapshot    = null === $comparison ? null : $snapshots->get( (int) $comparison['snapshot_id'] );
		$back        = Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG );

		if ( null === $comparison || null === $snapshot ) {
			echo '<h1 class="umin-title">' . esc_html__( 'Comparison not found', 'unstoppable-migration-inspector' ) . '</h1>';
			echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to snapshots', 'unstoppable-migration-inspector' ) . '</a></p>';
			return;
		}

		$summary = $comparison['summary'];
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to snapshots', 'unstoppable-migration-inspector' ); ?></a></p>
		<h1 class="umin-title"><?php esc_html_e( 'Snapshot Comparison', 'unstoppable-migration-inspector' ); ?></h1>
		<p class="umin-intro">
			<?php
			printf(
				/* translators: 1: snapshot label, 2: source site address, 3: date the snapshot was taken. */
				esc_html__( 'Snapshot "%1$s" of %2$s, taken %3$s, compared with this site.', 'unstoppable-migration-inspector' ),
				esc_html( $snapshot['label'] ),
				esc_html( (string) $snapshot['source_home'] ),
				esc_html( self::format_time( (int) $snapshot['header']['created_at'] ) )
			);
			?>
		</p>

		<?php
		if ( 'complete' !== $comparison['status'] ) {
			self::render_comparison_in_progress( $comparison );
			return;
		}

		if ( '' !== $type && '' !== $kind ) {
			self::render_items( $comparison_id, $type, $kind, $paged );
			return;
		}

		self::render_comparison_summary( $comparison_id, $summary );
	}

	/**
	 * A comparison that has not finished.
	 *
	 * @param array<string, mixed> $comparison Comparison.
	 * @return void
	 */
	private static function render_comparison_in_progress( array $comparison ) {
		$store = new Job_Store();
		$job   = (int) $comparison['job_id'] > 0 ? $store->get( (int) $comparison['job_id'] ) : null;
		?>
		<div class="umin-panel">
			<?php if ( null !== $job && ! Job_Store::is_finished( $job ) ) : ?>
				<p><?php esc_html_e( 'This comparison has not finished. Resume it to see the results.', 'unstoppable-migration-inspector' ); ?></p>
				<p><button type="button" class="button button-primary" data-umin-action="resume" data-umin-job="<?php echo esc_attr( (string) $job['id'] ); ?>" data-umin-then="reload"><?php esc_html_e( 'Resume Comparison', 'unstoppable-migration-inspector' ); ?></button></p>
				<?php self::render_progress(); ?>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'This comparison stopped before it finished. Go back and start a new comparison.', 'unstoppable-migration-inspector' ); ?>
					<?php echo null !== $job ? esc_html( (string) $job['error'] ) : ''; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The comparison summary.
	 *
	 * @param int                  $comparison_id Comparison ID.
	 * @param array<string, mixed> $summary       Summary.
	 * @return void
	 */
	private static function render_comparison_summary( $comparison_id, array $summary ) {
		$totals = Comparator::empty_totals();
		foreach ( isset( $summary['types'] ) ? (array) $summary['types'] : array() as $type_totals ) {
			$totals = Comparator::add_totals( $totals, (array) $type_totals );
		}

		$overall = $totals[ Comparator::MISSING ] > 0 ? 'critical' : ( $totals[ Comparator::CHANGED ] > 0 ? 'warning' : 'passed' );

		self::render_identity( isset( $summary['identity'] ) ? (array) $summary['identity'] : array() );
		?>
		<div class="umin-overall umin-status--<?php echo esc_attr( $overall ); ?>">
			<h2 class="umin-overall__heading">
				<?php
				if ( $totals[ Comparator::MISSING ] > 0 ) {
					printf(
						/* translators: %s: number of records. */
						esc_html( _n( '%s record from the snapshot was not found on this site', '%s records from the snapshot were not found on this site', $totals[ Comparator::MISSING ], 'unstoppable-migration-inspector' ) ),
						esc_html( number_format_i18n( $totals[ Comparator::MISSING ] ) )
					);
				} else {
					esc_html_e( 'Every record in the snapshot was found on this site', 'unstoppable-migration-inspector' );
				}
				?>
			</h2>
			<p>
				<?php
				printf(
					/* translators: 1: changed count, 2: new count, 3: not-in-snapshot count, 4: unchanged count. */
					esc_html__( '%1$s changed, %2$s created after the snapshot, %3$s not in the snapshot, %4$s unchanged.', 'unstoppable-migration-inspector' ),
					esc_html( number_format_i18n( $totals[ Comparator::CHANGED ] ) ),
					esc_html( number_format_i18n( $totals[ Comparator::ADDED ] ) ),
					esc_html( number_format_i18n( $totals[ Comparator::EXTRA ] ) ),
					esc_html( number_format_i18n( $totals[ Comparator::UNCHANGED ] ) )
				);
				?>
			</p>
			<?php if ( $totals[ Comparator::MISSING ] > 0 ) : ?>
				<p><a class="button" href="<?php echo esc_url( Snapshot_Downloads::csv_url( $comparison_id, Comparator::MISSING ) ); ?>"><?php esc_html_e( 'Download Not-Found IDs (CSV)', 'unstoppable-migration-inspector' ); ?></a></p>
			<?php endif; ?>
		</div>

		<p class="description">
			<?php esc_html_e( 'What this means: "Not found" records existed when the snapshot was taken and their IDs cannot be found on this site now. That is a strong sign something did not transfer, but it is not proof of loss: a record may also have been deleted on purpose after the snapshot. "Changed" records exist on both but their status, date, content, size or role differs. "Created after the snapshot" records are newer than the snapshot. "Not in the snapshot" records are older than the snapshot but were not in it, which usually means data came from somewhere else. A snapshot is not a backup: it can show what was not found, but it cannot restore it.', 'unstoppable-migration-inspector' ); ?>
		</p>

		<?php self::render_order_caution( isset( $summary['types'] ) ? (array) $summary['types'] : array() ); ?>
		<?php self::render_order_storage( isset( $summary['order_storage'] ) ? (array) $summary['order_storage'] : array() ); ?>

		<h2 class="umin-section-title"><?php esc_html_e( 'Records', 'unstoppable-migration-inspector' ); ?></h2>
		<table class="widefat striped umin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Record type', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'In snapshot', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'On this site', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Not found', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Changed', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Created after snapshot', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Not in snapshot', 'unstoppable-migration-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( isset( $summary['types'] ) ? (array) $summary['types'] : array() as $key => $type_totals ) {
					self::render_type_row( $comparison_id, (string) $key, (array) $type_totals );
				}
				?>
			</tbody>
		</table>

		<?php if ( ! empty( $summary['types_only_on_site'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: list of record types. */
					esc_html__( 'Record types on this site that were not in the snapshot (not compared): %s', 'unstoppable-migration-inspector' ),
					esc_html( implode( ', ', array_map( array( Source_Registry::class, 'label' ), (array) $summary['types_only_on_site'] ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		self::render_config_diffs( isset( $summary['config'] ) ? (array) $summary['config'] : array() );
		self::render_table_diffs( isset( $summary['tables'] ) ? (array) $summary['tables'] : array() );
	}

	/**
	 * One record type row with links to its lists.
	 *
	 * @param int                $comparison_id Comparison ID.
	 * @param string             $key           Type key.
	 * @param array<string, int> $totals        Totals.
	 * @return void
	 */
	private static function render_type_row( $comparison_id, $key, array $totals ) {
		$totals = Comparator::add_totals( Comparator::empty_totals(), $totals );
		$class  = $totals[ Comparator::MISSING ] > 0 ? ' class="umin-row--missing"' : '';
		?>
		<tr<?php echo $class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed string. ?>>
			<th scope="row"><?php echo esc_html( Source_Registry::label( $key ) ); ?></th>
			<td class="num"><?php echo esc_html( number_format_i18n( $totals['in_snapshot'] ) ); ?></td>
			<td class="num"><?php echo esc_html( number_format_i18n( $totals['on_site'] ) ); ?></td>
			<?php foreach ( array( Comparator::MISSING, Comparator::CHANGED, Comparator::ADDED, Comparator::EXTRA ) as $kind ) : ?>
				<td class="num">
					<?php if ( $totals[ $kind ] > 0 ) : ?>
						<a href="
						<?php
						echo esc_url(
							Admin_Menu::url(
								Admin_Menu::SNAPSHOTS_SLUG,
								array(
									'comparison' => $comparison_id,
									'type'       => $key,
									'kind'       => $kind,
								)
							)
						);
						?>
									">
							<?php echo esc_html( number_format_i18n( $totals[ $kind ] ) ); ?>
						</a>
					<?php else : ?>
						0
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
		</tr>
		<?php
	}

	/**
	 * Caution shown when orders were not found: not found is not proof of loss.
	 *
	 * @param array<string, array<string, int>> $types Per-type totals.
	 * @return void
	 */
	private static function render_order_caution( array $types ) {
		foreach ( $types as $key => $totals ) {
			if ( 0 === strpos( (string) $key, 'wc_' ) && ! empty( $totals[ Comparator::MISSING ] ) ) {
				self::render_order_notice();
				return;
			}
		}
	}

	/**
	 * The "not found is not the same as lost" notice for orders.
	 *
	 * @return void
	 */
	private static function render_order_notice() {
		?>
		<div class="notice notice-warning inline umin-order-caution">
			<p>
				<strong><?php esc_html_e( 'Orders not found are not necessarily lost orders.', 'unstoppable-migration-inspector' ); ?></strong>
				<?php esc_html_e( 'Each order listed as not found existed when the snapshot was taken and its ID cannot be found on this site now. That alone does not prove the order was lost: it may have been deleted on purpose after the snapshot, permanently removed from the trash, or moved by another plugin. Before treating an order as lost, look it up on the old site or in a backup taken before the move.', 'unstoppable-migration-inspector' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the snapshot was matched to this site.
	 *
	 * @param array<string, mixed> $identity Identity assessment stored with the comparison.
	 * @return void
	 */
	private static function render_identity( array $identity ) {
		if ( empty( $identity['verdict'] ) ) {
			return;
		}

		$confirmed = in_array( $identity['verdict'], array( Identity_Check::SAME_SITE, Identity_Check::LIKELY_SAME ), true );
		?>
		<div class="notice <?php echo $confirmed ? 'notice-info' : 'notice-error'; ?> inline umin-identity">
			<p>
				<strong><?php echo $confirmed ? esc_html__( 'Snapshot matched to this site.', 'unstoppable-migration-inspector' ) : esc_html__( 'Snapshot not matched to this site.', 'unstoppable-migration-inspector' ); ?></strong>
				<?php echo esc_html( Identity_Check::describe( (string) $identity['verdict'] ) ); ?>
			</p>
			<?php if ( ! $confirmed && ! empty( $identity['reasons'] ) ) : ?>
				<ul class="ul-disc">
					<?php foreach ( (array) $identity['reasons'] as $reason ) : ?>
						<li><?php echo esc_html( (string) $reason ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Order storage note.
	 *
	 * @param array<string, string> $storage Snapshot and site modes.
	 * @return void
	 */
	private static function render_order_storage( array $storage ) {
		$before = isset( $storage['snapshot'] ) ? $storage['snapshot'] : Woo_Storage::MODE_NONE;
		$after  = isset( $storage['site'] ) ? $storage['site'] : Woo_Storage::MODE_NONE;

		if ( Woo_Storage::MODE_NONE === $before && Woo_Storage::MODE_NONE === $after ) {
			return;
		}

		$names = array(
			Woo_Storage::MODE_HPOS   => __( 'High-Performance Order Storage (HPOS)', 'unstoppable-migration-inspector' ),
			Woo_Storage::MODE_LEGACY => __( 'posts storage (legacy)', 'unstoppable-migration-inspector' ),
			Woo_Storage::MODE_NONE   => __( 'no order storage found', 'unstoppable-migration-inspector' ),
		);
		?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: storage used when the snapshot was taken, 2: storage used on this site. */
				esc_html__( 'WooCommerce orders: the snapshot read orders from %1$s; this site was read from %2$s. Order IDs are kept when WooCommerce changes storage, so orders are compared by ID either way.', 'unstoppable-migration-inspector' ),
				esc_html( isset( $names[ $before ] ) ? $names[ $before ] : $before ),
				esc_html( isset( $names[ $after ] ) ? $names[ $after ] : $after )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Configuration differences.
	 *
	 * @param array<int, array<string, mixed>> $diffs Differences.
	 * @return void
	 */
	private static function render_config_diffs( array $diffs ) {
		?>
		<h2 class="umin-section-title"><?php esc_html_e( 'Settings and configuration', 'unstoppable-migration-inspector' ); ?></h2>
		<?php if ( empty( $diffs ) ) : ?>
			<p><?php esc_html_e( 'No differences in the recorded settings.', 'unstoppable-migration-inspector' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped umin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Setting', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'In snapshot', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'On this site', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it means', 'unstoppable-migration-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $diffs as $diff ) : ?>
					<?php $severity = self::severity_status( isset( $diff['severity'] ) ? (string) $diff['severity'] : 'info' ); ?>
					<tr>
						<th scope="row">
							<code><?php echo esc_html( (string) $diff['key'] ); ?></code><br />
							<span class="umin-badge umin-badge--<?php echo esc_attr( $severity['class'] ); ?>"><?php echo esc_html( $severity['label'] ); ?></span>
						</th>
						<td><?php echo esc_html( self::display_value( $diff['before'] ) ); ?></td>
						<td><?php echo esc_html( self::display_value( $diff['after'] ) ); ?></td>
						<td><?php echo esc_html( (string) $diff['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Tables that are missing or lost rows.
	 *
	 * @param array<string, array<string, mixed>> $tables Table results.
	 * @return void
	 */
	private static function render_table_diffs( array $tables ) {
		$problems = array_filter(
			$tables,
			static function ( $table ) {
				return isset( $table['status'] ) && in_array( $table['status'], array( 'missing', 'decreased' ), true );
			}
		);
		?>
		<h2 class="umin-section-title"><?php esc_html_e( 'Database tables', 'unstoppable-migration-inspector' ); ?></h2>
		<?php if ( empty( $problems ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of tables. */
					esc_html__( 'All %s tables from the snapshot exist, and none has noticeably fewer rows.', 'unstoppable-migration-inspector' ),
					esc_html( number_format_i18n( count( $tables ) ) )
				);
				?>
			</p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped umin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Table', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Rows in snapshot', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Rows now', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Problem', 'unstoppable-migration-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $problems as $name => $table ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( (string) $name ); ?></code></th>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $table['before'] ) ); ?></td>
						<td class="num"><?php echo 'missing' === $table['status'] ? '—' : esc_html( number_format_i18n( (int) $table['after'] ) ); ?></td>
						<td>
							<?php
							echo 'missing' === $table['status']
								? esc_html__( 'This table does not exist on this site. The plugin that uses it will have lost its data.', 'unstoppable-migration-inspector' )
								: esc_html__( 'This table has fewer rows than before the move. Part of its data may not have been imported.', 'unstoppable-migration-inspector' );
							echo ! empty( $table['approx'] ) ? ' ' . esc_html__( '(Row counts are estimates for very large tables.)', 'unstoppable-migration-inspector' ) : '';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One list of records.
	 *
	 * @param int    $comparison_id Comparison ID.
	 * @param string $type          Type key.
	 * @param string $kind          Bucket.
	 * @param int    $paged         Page.
	 * @return void
	 */
	private static function render_items( $comparison_id, $type, $kind, $paged ) {
		$titles = array(
			Comparator::MISSING => __( 'Not found on this site', 'unstoppable-migration-inspector' ),
			Comparator::CHANGED => __( 'Changed since the snapshot', 'unstoppable-migration-inspector' ),
			Comparator::ADDED   => __( 'Created after the snapshot', 'unstoppable-migration-inspector' ),
			Comparator::EXTRA   => __( 'Not in the snapshot', 'unstoppable-migration-inspector' ),
		);

		$summary_url = Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG, array( 'comparison' => $comparison_id ) );

		if ( ! isset( $titles[ $kind ] ) || ! preg_match( Source_Registry::KEY_PATTERN, $type ) ) {
			echo '<p><a href="' . esc_url( $summary_url ) . '">' . esc_html__( 'Back to the comparison', 'unstoppable-migration-inspector' ) . '</a></p>';
			return;
		}

		$repository = new Comparison_Repository();
		$result     = $repository->items( $comparison_id, $type, $kind, $paged, self::PER_PAGE );
		$pages      = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		?>
		<p><a href="<?php echo esc_url( $summary_url ); ?>">&larr; <?php esc_html_e( 'Back to the comparison', 'unstoppable-migration-inspector' ); ?></a></p>
		<h2>
			<?php
			printf(
				/* translators: 1: record type, 2: list name, 3: number of records. */
				esc_html__( '%1$s: %2$s (%3$s)', 'unstoppable-migration-inspector' ),
				esc_html( Source_Registry::label( $type ) ),
				esc_html( $titles[ $kind ] ),
				esc_html( number_format_i18n( $result['total'] ) )
			);
			?>
		</h2>
		<?php if ( Comparator::MISSING === $kind ) : ?>
			<p class="description"><?php esc_html_e( 'These IDs existed when the snapshot was taken and cannot be found on this site. Check them against the old site or a backup: if they should be here, restore them from that backup or re-run the migration for the missing data.', 'unstoppable-migration-inspector' ); ?></p>
			<?php if ( 0 === strpos( $type, 'wc_' ) ) : ?>
				<?php self::render_order_notice(); ?>
			<?php endif; ?>
		<?php endif; ?>
		<table class="widefat striped umin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'ID', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'unstoppable-migration-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['items'] as $item ) : ?>
					<?php $link = Comparator::MISSING === $kind ? '' : self::edit_link( $type, $item['id'] ); ?>
					<tr>
						<td>
							<?php if ( '' !== $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>">#<?php echo esc_html( (string) $item['id'] ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( (string) $item['id'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::describe_detail( $kind, (array) $item['detail'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Admin link to an existing record, when one can be built.
	 *
	 * @param string $type Type key.
	 * @param int    $id   Record ID.
	 * @return string
	 */
	private static function edit_link( $type, $id ) {
		if ( 0 === strpos( $type, 'post:' ) ) {
			return (string) get_edit_post_link( $id, 'raw' );
		}

		if ( 'user' === $type ) {
			return (string) get_edit_user_link( $id );
		}

		if ( 'comment' === $type ) {
			return admin_url( 'comment.php?action=editcomment&c=' . (int) $id );
		}

		if ( 0 === strpos( $type, 'term:' ) ) {
			$term = get_term_by( 'term_taxonomy_id', $id );
			return $term instanceof \WP_Term ? (string) get_edit_term_link( $term->term_id, $term->taxonomy ) : '';
		}

		if ( 0 === strpos( $type, 'wc_' ) ) {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url' ) ) {
				return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $id );
			}
			return admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
		}

		return '';
	}

	/**
	 * Plain-language description of an item's detail.
	 *
	 * @param string               $kind   Bucket.
	 * @param array<string, mixed> $detail Detail.
	 * @return string
	 */
	private static function describe_detail( $kind, array $detail ) {
		$parts = array();

		if ( Comparator::MISSING === $kind ) {
			foreach ( array( 'status', 'role', 'ctype', 'approved' ) as $field ) {
				if ( isset( $detail[ $field ] ) ) {
					/* translators: 1: field name, 2: value. */
					$parts[] = sprintf( __( 'was %1$s: %2$s', 'unstoppable-migration-inspector' ), self::field_label( $field ), (string) $detail[ $field ] );
				}
			}
			return empty( $parts ) ? __( 'Not found on this site.', 'unstoppable-migration-inspector' ) : implode( '; ', $parts );
		}

		if ( Comparator::ADDED === $kind || Comparator::EXTRA === $kind ) {
			if ( isset( $detail['created'] ) ) {
				/* translators: %s: date. */
				return sprintf( __( 'Created %s', 'unstoppable-migration-inspector' ), self::format_time( (int) $detail['created'] ) );
			}
			return __( 'No creation date recorded.', 'unstoppable-migration-inspector' );
		}

		foreach ( $detail as $field => $change ) {
			if ( 'shrank' === $field || ! is_array( $change ) || 2 !== count( $change ) ) {
				continue;
			}

			list( $before, $after ) = array_values( $change );

			if ( 'digest' === $field ) {
				// The digest is a privacy-preserving fingerprint, not a value
				// worth showing; only the fact that it changed matters.
				$parts[] = isset( $detail['length'] )
					? __( 'content changed', 'unstoppable-migration-inspector' )
					: __( 'content changed, although its size is the same', 'unstoppable-migration-inspector' );
				continue;
			}

			if ( 'modified' === $field ) {
				$before = self::format_time( (int) $before );
				$after  = self::format_time( (int) $after );
			} elseif ( 'length' === $field ) {
				/* translators: %s: number of bytes. */
				$before = sprintf( __( '%s bytes', 'unstoppable-migration-inspector' ), number_format_i18n( (int) $before ) );
				/* translators: %s: number of bytes. */
				$after = sprintf( __( '%s bytes', 'unstoppable-migration-inspector' ), number_format_i18n( (int) $after ) );
			}

			/* translators: 1: field name, 2: old value, 3: new value. */
			$parts[] = sprintf( __( '%1$s: %2$s → %3$s', 'unstoppable-migration-inspector' ), self::field_label( (string) $field ), (string) $before, (string) $after );
		}

		if ( ! empty( $detail['shrank'] ) ) {
			$parts[] = __( 'The content is smaller than before, which can mean it was cut off during import or damaged by a character set problem.', 'unstoppable-migration-inspector' );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Readable field name.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private static function field_label( $field ) {
		$labels = array(
			'status'   => __( 'status', 'unstoppable-migration-inspector' ),
			'modified' => __( 'last modified', 'unstoppable-migration-inspector' ),
			'length'   => __( 'content size', 'unstoppable-migration-inspector' ),
			'role'     => __( 'role', 'unstoppable-migration-inspector' ),
			'approved' => __( 'approval', 'unstoppable-migration-inspector' ),
			'ctype'    => __( 'comment type', 'unstoppable-migration-inspector' ),
			'count'    => __( 'items using this term', 'unstoppable-migration-inspector' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Badge class and label for a configuration severity.
	 *
	 * @param string $severity info, warning or critical.
	 * @return array{class: string, label: string}
	 */
	private static function severity_status( $severity ) {
		if ( 'critical' === $severity ) {
			return array(
				'class' => 'critical',
				'label' => __( 'Critical', 'unstoppable-migration-inspector' ),
			);
		}

		if ( 'warning' === $severity ) {
			return array(
				'class' => 'warning',
				'label' => __( 'Warning', 'unstoppable-migration-inspector' ),
			);
		}

		return array(
			'class' => 'not_tested',
			'label' => __( 'Info', 'unstoppable-migration-inspector' ),
		);
	}

	/**
	 * Display a configuration value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display_value( $value ) {
		if ( null === $value ) {
			return __( '(not present)', 'unstoppable-migration-inspector' );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'yes', 'unstoppable-migration-inspector' ) : __( 'no', 'unstoppable-migration-inspector' );
		}

		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		return '' === (string) $value ? __( '(empty)', 'unstoppable-migration-inspector' ) : (string) $value;
	}

	/**
	 * Format a unix time in the site's timezone.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function format_time( $time ) {
		if ( $time <= 0 ) {
			return __( 'unknown', 'unstoppable-migration-inspector' );
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
