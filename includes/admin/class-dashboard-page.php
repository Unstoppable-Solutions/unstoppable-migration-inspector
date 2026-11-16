<?php
/**
 * Dashboard screen.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Migration Health dashboard.
 *
 * The screen leads with one action, Scan Website. Repair tools are offered
 * from individual findings, never as the headline.
 */
final class Dashboard_Page {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_view() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-migration-inspector' ) );
		}

		$summary = Health_Summary::summary();
		?>
		<div class="wrap umin-wrap">
			<h1 class="umin-title"><?php esc_html_e( 'Migration Inspector', 'unstoppable-migration-inspector' ); ?></h1>
			<p class="umin-intro">
				<?php esc_html_e( 'Moved this site to a new host, server or domain? Run a scan to check what transferred correctly and what needs attention. Scanning is read-only and does not change your site.', 'unstoppable-migration-inspector' ); ?>
			</p>

			<?php self::render_scan_panel(); ?>
			<?php self::render_overall( $summary['overall'] ); ?>

			<h2 class="umin-section-title"><?php esc_html_e( 'Inspection categories', 'unstoppable-migration-inspector' ); ?></h2>
			<ul class="umin-categories">
				<?php
				foreach ( $summary['categories'] as $category ) {
					self::render_category( $category );
				}
				?>
			</ul>

			<?php self::render_snapshot_panel(); ?>
		</div>
		<?php
	}

	/**
	 * The primary Scan Website panel.
	 *
	 * The scanner ships in the next build. Until then the button is shown,
	 * disabled, with a plain note, so nothing pretends to work.
	 *
	 * @return void
	 */
	private static function render_scan_panel() {
		?>
		<div class="umin-panel umin-scan-panel">
			<div class="umin-scan-panel__text">
				<h2><?php esc_html_e( 'Scan this website', 'unstoppable-migration-inspector' ); ?></h2>
				<p><?php esc_html_e( 'Checks domains and URLs, the database, media files, HTTPS, WordPress configuration and, if installed, WooCommerce.', 'unstoppable-migration-inspector' ); ?></p>
			</div>
			<div class="umin-scan-panel__action">
				<button type="button" class="button button-primary button-hero" disabled="disabled" aria-describedby="umin-scan-note">
					<?php esc_html_e( 'Scan Website', 'unstoppable-migration-inspector' ); ?>
				</button>
				<p id="umin-scan-note" class="description"><?php esc_html_e( 'The scanner is not available in this development build yet.', 'unstoppable-migration-inspector' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * The overall Migration Health line.
	 *
	 * @param string $overall Overall status.
	 * @return void
	 */
	private static function render_overall( $overall ) {
		$messages = array(
			Health_Summary::STATUS_NOT_TESTED => __( 'Not tested yet. Run a scan to see how the migration went.', 'unstoppable-migration-inspector' ),
			Health_Summary::STATUS_PASSED     => __( 'Every applicable check passed in the last scan. Checks cannot rule out every possible problem, so keep an eye on the site.', 'unstoppable-migration-inspector' ),
			Health_Summary::STATUS_WARNING    => __( 'Some checks need your attention.', 'unstoppable-migration-inspector' ),
			Health_Summary::STATUS_CRITICAL   => __( 'Some problems need fixing.', 'unstoppable-migration-inspector' ),
		);

		$message = isset( $messages[ $overall ] ) ? $messages[ $overall ] : $messages[ Health_Summary::STATUS_NOT_TESTED ];
		?>
		<div class="umin-overall umin-status--<?php echo esc_attr( $overall ); ?>">
			<h2 class="umin-overall__heading">
				<?php esc_html_e( 'Migration Health:', 'unstoppable-migration-inspector' ); ?>
				<?php self::render_badge( $overall ); ?>
			</h2>
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * One category card.
	 *
	 * @param array<string, mixed> $category Category row from Health_Summary.
	 * @return void
	 */
	private static function render_category( array $category ) {
		$issues = null === $category['issues']
			? __( 'No results yet', 'unstoppable-migration-inspector' )
			: sprintf(
				/* translators: %d: number of issues found. */
				_n( '%d issue', '%d issues', (int) $category['issues'], 'unstoppable-migration-inspector' ),
				(int) $category['issues']
			);
		?>
		<li class="umin-card umin-status--<?php echo esc_attr( $category['status'] ); ?>">
			<div class="umin-card__header">
				<h3 class="umin-card__title"><?php echo esc_html( $category['label'] ); ?></h3>
				<?php self::render_badge( $category['status'] ); ?>
			</div>
			<p class="umin-card__issues"><?php echo esc_html( $issues ); ?></p>
			<p class="umin-card__description"><?php echo esc_html( $category['description'] ); ?></p>
			<p class="umin-card__action">
				<strong><?php esc_html_e( 'Recommended:', 'unstoppable-migration-inspector' ); ?></strong>
				<?php echo esc_html( $category['action'] ); ?>
			</p>
		</li>
		<?php
	}

	/**
	 * Status badge with an icon and text label.
	 *
	 * @param string $status Status key.
	 * @return void
	 */
	private static function render_badge( $status ) {
		?>
		<span class="umin-badge umin-badge--<?php echo esc_attr( $status ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( Health_Summary::status_icon( $status ) ); ?>" aria-hidden="true"></span>
			<?php echo esc_html( Health_Summary::status_label( $status ) ); ?>
		</span>
		<?php
	}

	/**
	 * Migration snapshot panel.
	 *
	 * @return void
	 */
	private static function render_snapshot_panel() {
		?>
		<div class="umin-panel umin-snapshot-panel">
			<h2><?php esc_html_e( 'Planning a move? Take a snapshot first', 'unstoppable-migration-inspector' ); ?></h2>
			<p><?php esc_html_e( 'A migration snapshot records how many posts, pages, media files, users, orders and other records this site has, plus key settings. After the move, compare it with the new site to find records that did not arrive. Snapshots contain counts and settings only, never customer details.', 'unstoppable-migration-inspector' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG ) ); ?>"><?php esc_html_e( 'Go to Snapshots', 'unstoppable-migration-inspector' ); ?></a></p>
		</div>
		<?php
	}
}
