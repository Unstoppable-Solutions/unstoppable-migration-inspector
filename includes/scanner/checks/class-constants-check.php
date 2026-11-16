<?php
/**
 * Important wp-config.php constants.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Only whether a constant is set and its effect are reported; values are
 * never stored or shown (the content URL is reduced to its host).
 */
final class Constants_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'config_constants';
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
		return __( 'wp-config.php settings', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'content_url_host'  => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'WP_CONTENT_URL uses a different domain', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'wp-config.php sets the address of the wp-content folder to a domain that is not the site\'s. Themes, plugins and media then load from the other domain, which is often the old site.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Update or remove WP_CONTENT_URL in wp-config.php (and WP_CONTENT_DIR if it is a path from the old server).', 'unstoppable-migration-inspector' ),
			),
			'content_dir_stale' => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'WP_CONTENT_DIR points to a folder that does not exist', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'wp-config.php sets the wp-content folder to a path that is not on this server, usually the old server\'s path.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Correct or remove WP_CONTENT_DIR in wp-config.php.', 'unstoppable-migration-inspector' ),
			),
			'cache_dropin'      => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Page caching is switched on but its file is missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WP_CACHE is set to true, but wp-content/advanced-cache.php does not exist. The caching plugin is not working, and some hosts show errors.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Re-save the caching plugin\'s settings so it recreates the file, or set WP_CACHE to false if you no longer use page caching.', 'unstoppable-migration-inspector' ),
			),
			'object_cache'      => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'An object cache drop-in is installed', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'wp-content/object-cache.php connects WordPress to a cache server (Redis, Memcached…). If it was copied from the old server, it may point to a cache server that does not exist here, or serve stale data from before the move.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Check the cache plugin\'s status screen on the new server and flush the cache.', 'unstoppable-migration-inspector' ),
			),
			'debug_display'     => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Error messages are shown to visitors', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WP_DEBUG is on and errors are displayed on pages. Visitors can see PHP warnings that reveal file paths, and pages can break. Debug settings are often copied from a development or staging copy.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Set WP_DEBUG_DISPLAY to false (or WP_DEBUG to false) in wp-config.php on the live site.', 'unstoppable-migration-inspector' ),
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

		if ( defined( 'WP_CONTENT_URL' ) ) {
			$content_host = Location::host( Location::from_url( (string) WP_CONTENT_URL ) );
			$home_host    = Location::host( (string) $context->get( 'location' ) );

			if ( '' !== $content_host && $content_host !== $home_host ) {
				$findings[] = ( new Finding( 'content_url_host', $content_host ) )->on( 'setting', 'WP_CONTENT_URL' );
			}
		}

		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			$findings[] = ( new Finding( 'content_dir_stale' ) )->on( 'setting', 'WP_CONTENT_DIR' );
		}

		if ( defined( 'WP_CACHE' ) && WP_CACHE && ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$findings[] = ( new Finding( 'cache_dropin' ) )->on( 'setting', 'WP_CACHE' );
		}

		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
			$findings[] = ( new Finding( 'object_cache' ) )->on( 'file', 'object-cache.php' );
		}

		$display = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $display && 'local' !== wp_get_environment_type() && 'development' !== wp_get_environment_type() ) {
			$findings[] = ( new Finding( 'debug_display' ) )->on( 'setting', 'WP_DEBUG_DISPLAY' );
		}

		return $findings;
	}
}
