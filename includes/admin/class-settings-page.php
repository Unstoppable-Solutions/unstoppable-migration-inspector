<?php
/**
 * Settings screen.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tabbed settings screen.
 *
 * Each tab is its own form and declares the fields it owns in a hidden
 * input, so saving one tab never touches another tab's values.
 */
final class Settings_Page {

	/**
	 * Tabs and the fields each one owns.
	 *
	 * @return array<string, array{label: string, fields: string[]}>
	 */
	public static function tabs() {
		return array(
			'general'     => array(
				'label'  => __( 'General', 'unstoppable-migration-inspector' ),
				'fields' => array( 'previous_domains' ),
			),
			'performance' => array(
				'label'  => __( 'Performance', 'unstoppable-migration-inspector' ),
				'fields' => array( 'batch_size', 'time_budget' ),
			),
			'data'        => array(
				'label'  => __( 'Data & Privacy', 'unstoppable-migration-inspector' ),
				'fields' => array( 'report_retention_days', 'delete_data_on_uninstall' ),
			),
		);
	}

	/**
	 * Currently selected tab, validated against the tab list.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; validated against an allowlist below.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_view() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'unstoppable-migration-inspector' ) );
		}

		$tabs     = self::tabs();
		$current  = self::current_tab();
		$settings = Settings::all();
		?>
		<div class="wrap umin-wrap">
			<h1 class="umin-title"><?php esc_html_e( 'Migration Inspector Settings', 'unstoppable-migration-inspector' ); ?></h1>

			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'unstoppable-migration-inspector' ); ?>">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a
						href="<?php echo esc_url( Admin_Menu::url( Admin_Menu::SETTINGS_SLUG, array( 'tab' => $key ) ) ); ?>"
						class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"
						<?php echo $key === $current ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $tab['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="umin-settings-form">
				<?php settings_fields( Settings::GROUP ); ?>
				<?php self::render_declared_fields( $tabs[ $current ]['fields'] ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<?php
						switch ( $current ) {
							case 'performance':
								self::render_performance_tab( $settings );
								break;
							case 'data':
								self::render_data_tab( $settings );
								break;
							default:
								self::render_general_tab( $settings );
						}
						?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<p class="umin-settings-footer">
				<?php
				printf(
					/* translators: %s: support email address. */
					esc_html__( 'Questions or problems? Email %s.', 'unstoppable-migration-inspector' ),
					'<a href="mailto:support@unstoppablesolutions.app">support@unstoppablesolutions.app</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Hidden inputs declaring which settings this form owns.
	 *
	 * @param string[] $fields Field names.
	 * @return void
	 */
	private static function render_declared_fields( array $fields ) {
		foreach ( $fields as $field ) {
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( self::field_name( Settings::DECLARED_KEY ) . '[]' ),
				esc_attr( $field )
			);
		}
	}

	/**
	 * Form field name for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field_name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * General tab.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private static function render_general_tab( array $settings ) {
		?>
		<tr>
			<th scope="row">
				<label for="umin-previous-domains"><?php esc_html_e( 'Previous domains', 'unstoppable-migration-inspector' ); ?></label>
			</th>
			<td>
				<textarea
					id="umin-previous-domains"
					name="<?php echo esc_attr( self::field_name( 'previous_domains' ) ); ?>"
					rows="5"
					class="large-text code"
					aria-describedby="umin-previous-domains-help"
				><?php echo esc_textarea( implode( "\n", $settings['previous_domains'] ) ); ?></textarea>
				<p id="umin-previous-domains-help" class="description">
					<?php
					printf(
						/* translators: %d: maximum number of entries. */
						esc_html__( 'Optional. The old addresses this site used before it was moved, such as the previous domain, a staging site or a temporary hosting URL. One per line, up to %d. The scanner looks for leftover references to these. Other external links are never treated as problems just because they point elsewhere.', 'unstoppable-migration-inspector' ),
						(int) Settings::MAX_PREVIOUS_DOMAINS
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Performance tab.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private static function render_performance_tab( array $settings ) {
		$ranges = Settings::int_ranges();
		?>
		<tr>
			<th scope="row">
				<label for="umin-batch-size"><?php esc_html_e( 'Rows per batch', 'unstoppable-migration-inspector' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					id="umin-batch-size"
					name="<?php echo esc_attr( self::field_name( 'batch_size' ) ); ?>"
					value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>"
					min="<?php echo esc_attr( (string) $ranges['batch_size'][0] ); ?>"
					max="<?php echo esc_attr( (string) $ranges['batch_size'][1] ); ?>"
					step="1"
					class="small-text"
					aria-describedby="umin-batch-size-help"
				/>
				<p id="umin-batch-size-help" class="description"><?php esc_html_e( 'How many database rows are read in each step of a scan. Lower this if scans stop with memory or timeout errors on your host.', 'unstoppable-migration-inspector' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="umin-time-budget"><?php esc_html_e( 'Seconds per step', 'unstoppable-migration-inspector' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					id="umin-time-budget"
					name="<?php echo esc_attr( self::field_name( 'time_budget' ) ); ?>"
					value="<?php echo esc_attr( (string) $settings['time_budget'] ); ?>"
					min="<?php echo esc_attr( (string) $ranges['time_budget'][0] ); ?>"
					max="<?php echo esc_attr( (string) $ranges['time_budget'][1] ); ?>"
					step="1"
					class="small-text"
					aria-describedby="umin-time-budget-help"
				/>
				<p id="umin-time-budget-help" class="description"><?php esc_html_e( 'The longest a single step may run before pausing and continuing in the next request. Keep this well under your host\'s PHP time limit.', 'unstoppable-migration-inspector' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Data & Privacy tab.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private static function render_data_tab( array $settings ) {
		$ranges = Settings::int_ranges();
		?>
		<tr>
			<th scope="row">
				<label for="umin-retention"><?php esc_html_e( 'Keep scan history for', 'unstoppable-migration-inspector' ); ?></label>
			</th>
			<td>
				<input
					type="number"
					id="umin-retention"
					name="<?php echo esc_attr( self::field_name( 'report_retention_days' ) ); ?>"
					value="<?php echo esc_attr( (string) $settings['report_retention_days'] ); ?>"
					min="<?php echo esc_attr( (string) $ranges['report_retention_days'][0] ); ?>"
					max="<?php echo esc_attr( (string) $ranges['report_retention_days'][1] ); ?>"
					step="1"
					class="small-text"
					aria-describedby="umin-retention-help"
				/>
				<?php esc_html_e( 'days', 'unstoppable-migration-inspector' ); ?>
				<p id="umin-retention-help" class="description"><?php esc_html_e( 'Older scans and their findings are removed automatically. Enter 0 to keep them until you delete them. Snapshots are never removed automatically.', 'unstoppable-migration-inspector' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'unstoppable-migration-inspector' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'When the plugin is deleted', 'unstoppable-migration-inspector' ); ?></legend>
					<label for="umin-delete-data">
						<input
							type="checkbox"
							id="umin-delete-data"
							name="<?php echo esc_attr( self::field_name( 'delete_data_on_uninstall' ) ); ?>"
							value="1"
							<?php checked( (bool) $settings['delete_data_on_uninstall'] ); ?>
						/>
						<?php esc_html_e( 'Remove all Migration Inspector data when the plugin is deleted', 'unstoppable-migration-inspector' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default, so your snapshots and scan history survive if you delete and reinstall the plugin. When this is on, deleting the plugin from the Plugins screen permanently removes its settings, snapshots, scan history and undo records for this site. Deactivating never removes anything. The plugin keeps no data outside its own tables and settings.', 'unstoppable-migration-inspector' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
	}
}
