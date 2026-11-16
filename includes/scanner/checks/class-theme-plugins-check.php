<?php
/**
 * Active theme and plugin files.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the database says is active must be on disk.
 */
final class Theme_Plugins_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'theme_plugins';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'configuration';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Active theme and plugins', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'theme_missing'  => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'The active theme\'s files are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The site is set to use a theme (or parent theme) whose folder is not in wp-content/themes. WordPress falls back to another theme or shows a blank page, so the site does not look as it did.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the theme folder from the old server or a backup into wp-content/themes, keeping the same folder name.', 'unstoppable-migration-inspector' ),
			),
			'plugin_missing' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Active plugins whose files are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'These plugins are switched on in the database, but their files are not in wp-content/plugins. Their features are not working, and WordPress will deactivate them the next time the Plugins screen loads. Premium plugins are often missed because they were installed manually.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy each plugin folder from the old server, or reinstall it (re-enter the licence key for premium plugins), then reactivate it.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		$findings = array();
		$theme    = (string) get_option( 'stylesheet' );
		$template = (string) get_option( 'template' );
		$root     = get_theme_root( $theme );

		foreach ( array_unique( array_filter( array( $theme, $template ) ) ) as $slug ) {
			if ( ! is_dir( trailingslashit( get_theme_root( $slug ) ) . $slug ) ) {
				$findings[] = ( new Finding( 'theme_missing', $slug ) )->on( 'file', $slug, $root );
			}
		}

		$plugins = (array) get_option( 'active_plugins', array() );

		if ( $context->get( 'multisite' ) ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( array_unique( $plugins ) as $plugin ) {
			$plugin = (string) $plugin;

			if ( '' === $plugin || 0 !== validate_file( $plugin ) ) {
				continue;
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$findings[] = ( new Finding( 'plugin_missing', $plugin ) )->on( 'file', $plugin );
			}
		}

		return $findings;
	}
}
