<?php
/**
 * Scan results screen.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Scanner\Check_Registry;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding_Repository;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Repository;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Shows one scan: overall status, then each category with its groups of
 * findings. Every group states what was found, why it matters and the
 * recommended next action, followed by the affected items.
 */
final class Scan_Page {

	/**
	 * Items per group page.
	 */
	const PER_PAGE = 20;

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_view() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-migration-inspector' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters, validated below.
		$scan_id = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;
		$group   = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$scans  = new Scan_Repository();
		$recent = $scans->recent( 10 );
		$scan   = $scan_id > 0 ? $scans->get( $scan_id ) : ( empty( $recent ) ? null : $recent[0] );
		?>
		<div class="wrap umin-wrap">
			<h1 class="umin-title"><?php esc_html_e( 'Scan Results', 'unstoppable-migration-inspector' ); ?></h1>
			<p class="umin-intro"><?php esc_html_e( 'The scan only reads your site. It does not change content, settings or files.', 'unstoppable-migration-inspector' ); ?></p>

			<?php self::render_scan_bar( $scan ); ?>

			<?php
			if ( null === $scan ) {
				echo '<p>' . esc_html__( 'No scan has been run yet.', 'unstoppable-migration-inspector' ) . '</p>';
			} elseif ( Scan_Repository::STATUS_COMPLETE === $scan['status'] ) {
				self::render_results( $scan, $group, $paged );
			} else {
				self::render_incomplete( $scan );
			}

			self::render_history( $recent, null === $scan ? 0 : $scan['id'] );
			?>
		</div>
		<?php
	}

	/**
	 * Scan button and progress.
	 *
	 * @param array<string, mixed>|null $scan Shown scan.
	 * @return void
	 */
	private static function render_scan_bar( $scan ) {
		?>
		<div class="umin-panel umin-scan-panel">
			<div class="umin-scan-panel__text">
				<?php if ( null !== $scan && Scan_Repository::STATUS_COMPLETE === $scan['status'] ) : ?>
					<h2>
						<?php esc_html_e( 'Migration Health:', 'unstoppable-migration-inspector' ); ?>
						<?php self::status_badge( isset( $scan['summary']['overall'] ) ? (string) $scan['summary']['overall'] : Health_Summary::STATUS_NOT_TESTED ); ?>
					</h2>
					<p>
						<?php
						printf(
							/* translators: 1: date and time, 2: duration in seconds. */
							esc_html__( 'Scanned %1$s, took %2$s seconds.', 'unstoppable-migration-inspector' ),
							esc_html( self::format_time( (string) $scan['finished_at'] ) ),
							esc_html( number_format_i18n( isset( $scan['summary']['duration'] ) ? (int) $scan['summary']['duration'] : 0 ) )
						);
						?>
					</p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Scan this website', 'unstoppable-migration-inspector' ); ?></h2>
					<p><?php esc_html_e( 'Checks domains and URLs, the database, media files, HTTPS, WordPress configuration and, if installed, WooCommerce.', 'unstoppable-migration-inspector' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="umin-scan-panel__action">
				<button type="button" class="button button-primary button-hero" data-umin-action="scan">
					<?php null === $scan ? esc_html_e( 'Scan Website', 'unstoppable-migration-inspector' ) : esc_html_e( 'Scan Again', 'unstoppable-migration-inspector' ); ?>
				</button>
			</div>
			<?php self::render_progress(); ?>
		</div>
		<?php
	}

	/**
	 * Progress markup driven by scan.js.
	 *
	 * @return void
	 */
	public static function render_progress() {
		?>
		<div class="umin-progress umin-progress--wide" data-umin-progress hidden>
			<progress max="100" value="0" data-umin-progress-bar></progress>
			<p class="umin-progress__text" data-umin-progress-text role="status" aria-live="polite"></p>
			<button type="button" class="button" data-umin-action="cancel" hidden><?php esc_html_e( 'Cancel', 'unstoppable-migration-inspector' ); ?></button>
		</div>
		<?php
	}

	/**
	 * A scan that did not complete.
	 *
	 * @param array<string, mixed> $scan Scan.
	 * @return void
	 */
	private static function render_incomplete( array $scan ) {
		$job_id = isset( $scan['progress']['job_id'] ) ? (int) $scan['progress']['job_id'] : 0;
		$store  = new Job_Store();
		$job    = $job_id > 0 ? $store->get( $job_id ) : null;
		?>
		<div class="umin-panel">
			<?php if ( Scan_Repository::STATUS_RUNNING === $scan['status'] && null !== $job && in_array( $job['status'], array( Job_Store::STATUS_PENDING, Job_Store::STATUS_RUNNING ), true ) ) : ?>
				<h2><?php esc_html_e( 'This scan has not finished', 'unstoppable-migration-inspector' ); ?></h2>
				<p><?php esc_html_e( 'The scan is paused (the page was closed or the connection dropped). It continues from where it stopped.', 'unstoppable-migration-inspector' ); ?></p>
				<p><button type="button" class="button button-primary" data-umin-action="resume-scan" data-umin-job="<?php echo esc_attr( (string) $job_id ); ?>"><?php esc_html_e( 'Resume Scan', 'unstoppable-migration-inspector' ); ?></button></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'This scan did not complete', 'unstoppable-migration-inspector' ); ?></h2>
				<p>
					<?php echo esc_html( null !== $job && '' !== (string) $job['error'] ? (string) $job['error'] : __( 'It was cancelled or stopped. Run a new scan.', 'unstoppable-migration-inspector' ) ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Results of a completed scan.
	 *
	 * @param array<string, mixed> $scan  Scan.
	 * @param string               $group Open group (check|kind).
	 * @param int                  $paged Page of the open group.
	 * @return void
	 */
	private static function render_results( array $scan, $group, $paged ) {
		$summary  = $scan['summary'];
		$checks   = Check_Registry::all();
		$findings = new Finding_Repository();
		$groups   = array();

		foreach ( $findings->groups( $scan['id'] ) as $row ) {
			$id = $row['check_key'] . '|' . $row['kind'];

			if ( ! isset( $groups[ $row['category'] ][ $id ] ) ) {
				$groups[ $row['category'] ][ $id ] = array(
					'check'    => $row['check_key'],
					'kind'     => $row['kind'],
					'severity' => $row['severity'],
					'total'    => 0,
				);
			}

			$groups[ $row['category'] ][ $id ]['total'] += $row['total'];

			if ( Severity::rank( $row['severity'] ) < Severity::rank( $groups[ $row['category'] ][ $id ]['severity'] ) ) {
				$groups[ $row['category'] ][ $id ]['severity'] = $row['severity'];
			}
		}

		self::render_overview( $summary );
		self::render_locations( $summary );

		foreach ( Health_Summary::categories() as $category => $info ) {
			$result = isset( $summary['categories'][ $category ] ) ? $summary['categories'][ $category ] : array( 'status' => Health_Summary::STATUS_NOT_TESTED );
			$list   = isset( $groups[ $category ] ) ? $groups[ $category ] : array();

			uasort(
				$list,
				static function ( $a, $b ) {
					return array( Severity::rank( $a['severity'] ), -$a['total'] ) <=> array( Severity::rank( $b['severity'] ), -$b['total'] );
				}
			);
			?>
			<section class="umin-category umin-status--<?php echo esc_attr( (string) $result['status'] ); ?>" id="<?php echo esc_attr( 'umin-cat-' . $category ); ?>">
				<h2 class="umin-category__title">
					<?php echo esc_html( $info['label'] ); ?>
					<?php self::status_badge( (string) $result['status'] ); ?>
				</h2>

				<?php if ( empty( $list ) ) : ?>
					<p class="umin-category__empty">
						<?php
						echo esc_html(
							Health_Summary::STATUS_NOT_APPLICABLE === $result['status']
								? __( 'Not applicable to this site.', 'unstoppable-migration-inspector' )
								: ( Health_Summary::STATUS_PASSED === $result['status'] ? __( 'No problems found.', 'unstoppable-migration-inspector' ) : __( 'Some checks could not run; see "Checks run" below.', 'unstoppable-migration-inspector' ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				foreach ( $list as $id => $item ) {
					$check = isset( $checks[ $item['check'] ] ) ? $checks[ $item['check'] ] : null;
					$kind  = null === $check ? null : $check->kind( $item['kind'] );

					if ( null !== $kind ) {
						$dropped = isset( $summary['checks'][ $item['check'] ]['dropped'][ $item['kind'] ] ) ? (int) $summary['checks'][ $item['check'] ]['dropped'][ $item['kind'] ] : 0;
						self::render_group( $scan['id'], $item, $kind, $dropped, $group === $id, $group === $id ? $paged : 1 );
					}
				}

				self::render_checks_run( $summary, $category, $checks );
				?>
			</section>
			<?php
		}
	}

	/**
	 * Counts across categories.
	 *
	 * @param array<string, mixed> $summary Summary.
	 * @return void
	 */
	private static function render_overview( array $summary ) {
		$totals = array_fill_keys( Severity::all(), 0 );

		foreach ( isset( $summary['categories'] ) ? (array) $summary['categories'] : array() as $category ) {
			foreach ( Severity::all() as $severity ) {
				$totals[ $severity ] += isset( $category['counts'][ $severity ] ) ? (int) $category['counts'][ $severity ] : 0;
			}
		}
		?>
		<ul class="umin-totals">
			<?php foreach ( Severity::all() as $severity ) : ?>
				<li class="umin-totals__item umin-totals__item--<?php echo esc_attr( $severity ); ?>">
					<span class="umin-totals__count"><?php echo esc_html( number_format_i18n( $totals[ $severity ] ) ); ?></span>
					<?php self::severity_badge( $severity ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description"><?php esc_html_e( 'Critical items break something and should be fixed first. Warnings are problems visitors or admins are likely to notice. Notices are worth knowing and are often expected after a move.', 'unstoppable-migration-inspector' ); ?></p>
		<?php
	}

	/**
	 * The old addresses that were searched for.
	 *
	 * @param array<string, mixed> $summary Summary.
	 * @return void
	 */
	private static function render_locations( array $summary ) {
		$locations = isset( $summary['old_locations'] ) ? (array) $summary['old_locations'] : array();
		$sources   = array(
			'settings'  => __( 'entered in Settings', 'unstoppable-migration-inspector' ),
			'snapshot'  => __( 'a migration snapshot was taken there', 'unstoppable-migration-inspector' ),
			'guid'      => __( 'content was originally created there', 'unstoppable-migration-inspector' ),
			'temporary' => __( 'looks like a staging, local or temporary address linked from content', 'unstoppable-migration-inspector' ),
		);
		?>
		<details class="umin-panel umin-locations">
			<summary><strong><?php esc_html_e( 'Addresses searched for', 'unstoppable-migration-inspector' ); ?></strong></summary>
			<?php if ( empty( $locations ) ) : ?>
				<p><?php esc_html_e( 'No previous, staging or temporary address of this site was entered or detected, so old-address checks did not run.', 'unstoppable-migration-inspector' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'The scan looked for links to these addresses. Detected addresses can include sites that are not yours (for example demo content); check the results before changing anything.', 'unstoppable-migration-inspector' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $locations as $location ) : ?>
						<li>
							<code><?php echo esc_html( (string) $location['location'] ); ?></code>
							— <?php echo esc_html( isset( $sources[ $location['source'] ] ) ? $sources[ $location['source'] ] : (string) $location['source'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( Admin_Menu::url( Admin_Menu::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Add previous domains in Settings', 'unstoppable-migration-inspector' ); ?></a></p>
		</details>
		<?php
	}

	/**
	 * One group of findings.
	 *
	 * @param int                   $scan_id Scan ID.
	 * @param array<string, mixed>  $item    Group: check, kind, severity, total.
	 * @param array<string, string> $kind    Kind definition.
	 * @param int                   $dropped Findings not stored.
	 * @param bool                  $open    Whether to show expanded.
	 * @param int                   $paged   Page.
	 * @return void
	 */
	private static function render_group( $scan_id, array $item, array $kind, $dropped, $open, $paged ) {
		$id     = $item['check'] . '|' . $item['kind'];
		$total  = (int) $item['total'];
		$rows   = ( new Finding_Repository() )->page( $scan_id, $item['check'], $item['kind'], $paged, self::PER_PAGE );
		$pages  = (int) ceil( $total / self::PER_PAGE );
		$anchor = 'umin-group-' . md5( $id );
		?>
		<details class="umin-group umin-group--<?php echo esc_attr( (string) $item['severity'] ); ?>" id="<?php echo esc_attr( $anchor ); ?>"<?php echo esc_attr( $open ? ' open' : '' ); ?>>
			<summary class="umin-group__summary">
				<?php self::severity_badge( (string) $item['severity'] ); ?>
				<span class="umin-group__title"><?php echo esc_html( $kind['title'] ); ?></span>
				<span class="umin-group__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of items. */
							_n( '%s item', '%s items', $total + $dropped, 'unstoppable-migration-inspector' ),
							number_format_i18n( $total + $dropped )
						)
					);
					?>
				</span>
			</summary>
			<div class="umin-group__body">
				<p><strong><?php esc_html_e( 'Why it matters:', 'unstoppable-migration-inspector' ); ?></strong> <?php echo esc_html( $kind['explanation'] ); ?></p>
				<p class="umin-group__action"><strong><?php esc_html_e( 'Recommended next step:', 'unstoppable-migration-inspector' ); ?></strong> <?php echo esc_html( $kind['action'] ); ?></p>

				<?php if ( $dropped > 0 ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: number listed, 2: number not listed. */
							esc_html__( 'Only the first %1$s are listed; %2$s more were found.', 'unstoppable-migration-inspector' ),
							esc_html( number_format_i18n( $total ) ),
							esc_html( number_format_i18n( $dropped ) )
						);
						?>
					</p>
				<?php endif; ?>

				<table class="widefat striped umin-findings">
					<thead>
						<tr>
							<th scope="col" class="umin-findings__item"><?php esc_html_e( 'Item', 'unstoppable-migration-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'unstoppable-migration-inspector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php self::render_object( $row ); ?></td>
								<td><?php self::render_details( $row ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<p class="umin-pagination">
						<?php
						for ( $page = 1; $page <= $pages; $page++ ) {
							if ( $page === $paged ) {
								echo '<span class="button disabled" aria-current="page">' . esc_html( number_format_i18n( $page ) ) . '</span> ';
								continue;
							}

							$url = add_query_arg(
								array(
									'scan'  => (int) $scan_id,
									'group' => $id,
									'paged' => $page,
								),
								Admin_Menu::url( Admin_Menu::SCAN_SLUG )
							) . '#' . $anchor;

							echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $page ) ) . '</a> ';
						}
						?>
					</p>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/**
	 * The affected object, linked where possible.
	 *
	 * @param array<string, mixed> $row Finding row.
	 * @return void
	 */
	private static function render_object( array $row ) {
		$type    = (string) $row['object_type'];
		$id      = (string) $row['object_id'];
		$details = (array) $row['context'];

		switch ( $type ) {
			case 'post':
			case 'attachment':
				self::post_link( (int) $id );
				break;

			case 'postmeta':
				self::post_link( isset( $details['post_id'] ) ? (int) $details['post_id'] : 0 );
				if ( isset( $details['meta_key'] ) ) {
					echo '<br /><span class="description">' . esc_html__( 'Custom field:', 'unstoppable-migration-inspector' ) . '</span> <code>' . esc_html( (string) $details['meta_key'] ) . '</code>';
				}
				break;

			case 'comment':
				$link = get_edit_comment_link( (int) $id );
				/* translators: %s: comment ID. */
				$text = sprintf( __( 'Comment #%s', 'unstoppable-migration-inspector' ), $id );
				echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
				break;

			case 'termmeta':
				$term_id = isset( $details['term_id'] ) ? (int) $details['term_id'] : 0;
				$term    = $term_id > 0 ? get_term( $term_id ) : null;
				if ( $term instanceof \WP_Term ) {
					$link = get_edit_term_link( $term );
					echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>' : esc_html( $term->name );
				} else {
					/* translators: %d: term ID. */
					echo esc_html( sprintf( __( 'Term #%d', 'unstoppable-migration-inspector' ), $term_id ) );
				}
				if ( isset( $details['meta_key'] ) ) {
					echo '<br /><code>' . esc_html( (string) $details['meta_key'] ) . '</code>';
				}
				break;

			case 'option':
				echo '<span class="description">' . esc_html__( 'Setting:', 'unstoppable-migration-inspector' ) . '</span> <code>' . esc_html( isset( $details['option_name'] ) ? (string) $details['option_name'] : $id ) . '</code>';
				break;

			case '':
				esc_html_e( 'Site-wide', 'unstoppable-migration-inspector' );
				break;

			default:
				echo '<code>' . esc_html( $id ) . '</code>';
		}
	}

	/**
	 * A post edit link with title and type.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function post_link( $post_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			/* translators: %d: post ID. */
			echo esc_html( sprintf( __( 'Item #%d (no longer exists)', 'unstoppable-migration-inspector' ), $post_id ) );
			return;
		}

		$title  = '' === trim( $post->post_title ) ? __( '(no title)', 'unstoppable-migration-inspector' ) : $post->post_title;
		$object = get_post_type_object( $post->post_type );
		$link   = get_edit_post_link( $post->ID, 'raw' );

		echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		echo '<br /><span class="description">' . esc_html( ( $object ? $object->labels->singular_name : $post->post_type ) . ' #' . $post->ID ) . '</span>';
	}

	/**
	 * Finding details.
	 *
	 * @param array<string, mixed> $row Finding row.
	 * @return void
	 */
	private static function render_details( array $row ) {
		$details = (array) $row['context'];

		if ( '' !== (string) $row['message'] ) {
			echo '<div>' . esc_html( (string) $row['message'] ) . '</div>';
		}

		if ( '' !== (string) $row['location'] && in_array( $row['object_type'], array( 'file', 'table' ), true ) ) {
			echo '<div class="description"><code>' . esc_html( (string) $row['location'] ) . '</code></div>';
		}

		if ( ! empty( $details['excerpt'] ) ) {
			echo '<div class="umin-excerpt"><code>' . esc_html( (string) $details['excerpt'] ) . '</code></div>';
		}

		if ( ! empty( $details['files'] ) && is_array( $details['files'] ) ) {
			echo '<ul class="umin-files">';
			foreach ( $details['files'] as $file ) {
				echo '<li><code>' . esc_html( (string) $file ) . '</code></li>';
			}
			echo '</ul>';
			if ( isset( $details['total'] ) && (int) $details['total'] > count( $details['files'] ) ) {
				/* translators: %d: number of files not listed. */
				echo '<div class="description">' . esc_html( sprintf( __( 'and %d more', 'unstoppable-migration-inspector' ), (int) $details['total'] - count( $details['files'] ) ) ) . '</div>';
			}
		}

		if ( ! empty( $details['found'] ) ) {
			/* translators: %s: file name. */
			echo '<div>' . esc_html( sprintf( __( 'Found on disk as: %s', 'unstoppable-migration-inspector' ), (string) $details['found'] ) ) . '</div>';
		}

		if ( ! empty( $details['sizes'] ) && is_array( $details['sizes'] ) ) {
			echo '<div class="description">' . esc_html( implode( ', ', array_map( 'strval', array_values( $details['sizes'] ) ) ) ) . '</div>';
		}

		if ( ! empty( $details['problem'] ) ) {
			$problems = array(
				'missing'       => __( 'The assigned page does not exist.', 'unstoppable-migration-inspector' ),
				'unassigned'    => __( 'No page is assigned.', 'unstoppable-migration-inspector' ),
				'not_published' => __( 'The page is not published.', 'unstoppable-migration-inspector' ),
				'no_block'      => __( 'The page does not contain the WooCommerce block or shortcode.', 'unstoppable-migration-inspector' ),
			);
			echo '<div>' . esc_html( isset( $problems[ $details['problem'] ] ) ? $problems[ $details['problem'] ] : (string) $details['problem'] ) . '</div>';
		}

		if ( isset( $details['users'], $details['old_prefix'] ) ) {
			/* translators: 1: number of users, 2: table prefix. */
			echo '<div>' . esc_html( sprintf( _n( '%1$d user has a role under the prefix "%2$s".', '%1$d users have roles under the prefix "%2$s".', (int) $details['users'], 'unstoppable-migration-inspector' ), (int) $details['users'], (string) $details['old_prefix'] ) ) . '</div>';
		}

		if ( isset( $details['oldest_hours'] ) ) {
			/* translators: %d: hours. */
			echo '<div class="description">' . esc_html( sprintf( _n( 'The oldest is %d hour late.', 'The oldest is %d hours late.', (int) $details['oldest_hours'], 'unstoppable-migration-inspector' ), (int) $details['oldest_hours'] ) ) . '</div>';
		}
	}

	/**
	 * Which checks ran in a category, and why others did not.
	 *
	 * @param array<string, mixed>                                                  $summary  Summary.
	 * @param string                                                                $category Category.
	 * @param array<string, \UnstoppableSolutions\MigrationInspector\Scanner\Check> $checks Checks.
	 * @return void
	 */
	private static function render_checks_run( array $summary, $category, array $checks ) {
		$keys = isset( $summary['categories'][ $category ]['checks'] ) ? (array) $summary['categories'][ $category ]['checks'] : array();

		if ( empty( $keys ) ) {
			return;
		}

		$labels = array(
			'passed'         => __( 'Passed', 'unstoppable-migration-inspector' ),
			'issues'         => __( 'Found items', 'unstoppable-migration-inspector' ),
			'not_applicable' => __( 'Not applicable', 'unstoppable-migration-inspector' ),
			'error'          => __( 'Could not complete', 'unstoppable-migration-inspector' ),
			'not_tested'     => __( 'Not tested', 'unstoppable-migration-inspector' ),
		);
		?>
		<details class="umin-checks-run">
			<summary><?php esc_html_e( 'Checks run', 'unstoppable-migration-inspector' ); ?></summary>
			<ul>
				<?php foreach ( $keys as $key ) : ?>
					<?php
					$info   = isset( $summary['checks'][ $key ] ) ? $summary['checks'][ $key ] : array();
					$status = isset( $info['status'] ) ? (string) $info['status'] : 'not_tested';
					$label  = isset( $checks[ $key ] ) ? $checks[ $key ]->label() : $key;
					?>
					<li>
						<?php echo esc_html( $label ); ?>:
						<strong><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></strong>
						<?php if ( ! empty( $info['reason'] ) ) : ?>
							<span class="description">— <?php echo esc_html( (string) $info['reason'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/**
	 * Recent scans.
	 *
	 * @param array<int, array<string, mixed>> $recent  Scans.
	 * @param int                              $current Shown scan ID.
	 * @return void
	 */
	private static function render_history( array $recent, $current ) {
		if ( count( $recent ) < 1 ) {
			return;
		}

		$statuses = array(
			Scan_Repository::STATUS_RUNNING   => __( 'Not finished', 'unstoppable-migration-inspector' ),
			Scan_Repository::STATUS_COMPLETE  => __( 'Complete', 'unstoppable-migration-inspector' ),
			Scan_Repository::STATUS_FAILED    => __( 'Failed', 'unstoppable-migration-inspector' ),
			Scan_Repository::STATUS_CANCELLED => __( 'Cancelled', 'unstoppable-migration-inspector' ),
		);
		?>
		<h2 class="umin-section-title"><?php esc_html_e( 'Recent scans', 'unstoppable-migration-inspector' ); ?></h2>
		<table class="widefat striped umin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Started', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'unstoppable-migration-inspector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'unstoppable-migration-inspector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recent as $scan ) : ?>
					<tr>
						<td><?php echo esc_html( self::format_time( (string) $scan['started_at'] ) ); ?><?php echo $scan['id'] === $current ? ' <span class="description">(' . esc_html__( 'shown', 'unstoppable-migration-inspector' ) . ')</span>' : ''; ?></td>
						<td><?php echo esc_html( isset( $statuses[ $scan['status'] ] ) ? $statuses[ $scan['status'] ] : (string) $scan['status'] ); ?></td>
						<td>
							<?php
							if ( Scan_Repository::STATUS_COMPLETE === $scan['status'] ) {
								self::status_badge( isset( $scan['summary']['overall'] ) ? (string) $scan['summary']['overall'] : Health_Summary::STATUS_NOT_TESTED );
							} else {
								echo '—';
							}
							?>
						</td>
						<td class="umin-actions">
							<a class="button" href="<?php echo esc_url( add_query_arg( 'scan', (int) $scan['id'], Admin_Menu::url( Admin_Menu::SCAN_SLUG ) ) ); ?>"><?php esc_html_e( 'View', 'unstoppable-migration-inspector' ); ?></a>
							<button type="button" class="button button-link-delete" data-umin-action="delete-scan" data-umin-scan="<?php echo esc_attr( (string) $scan['id'] ); ?>"><?php esc_html_e( 'Delete', 'unstoppable-migration-inspector' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Deleting a scan removes only its results stored by this plugin.', 'unstoppable-migration-inspector' ); ?></p>
		<?php
	}

	/**
	 * Category status badge.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public static function status_badge( $status ) {
		?>
		<span class="umin-badge umin-badge--<?php echo esc_attr( $status ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( Health_Summary::status_icon( $status ) ); ?>" aria-hidden="true"></span>
			<?php echo esc_html( Health_Summary::status_label( $status ) ); ?>
		</span>
		<?php
	}

	/**
	 * Severity badge.
	 *
	 * @param string $severity Severity.
	 * @return void
	 */
	public static function severity_badge( $severity ) {
		$icons = array(
			Severity::CRITICAL => 'dismiss',
			Severity::WARNING  => 'warning',
			Severity::NOTICE   => 'info-outline',
		);
		?>
		<span class="umin-badge umin-badge--<?php echo esc_attr( $severity ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( isset( $icons[ $severity ] ) ? $icons[ $severity ] : 'info-outline' ); ?>" aria-hidden="true"></span>
			<?php echo esc_html( Severity::label( $severity ) ); ?>
		</span>
		<?php
	}

	/**
	 * Format a stored GMT datetime in the site's time zone.
	 *
	 * @param string $gmt GMT datetime.
	 * @return string
	 */
	private static function format_time( $gmt ) {
		$time = '' === $gmt ? false : strtotime( $gmt . ' UTC' );

		return false === $time ? '—' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
