<?php
/**
 * Search engine visibility.
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
 * Staging copies switch on "Discourage search engines". When a staging copy
 * is pushed live, the setting often comes with it.
 */
final class Search_Visibility_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'search_visibility';
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
		return __( 'Search engine visibility', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'discouraged'     => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Search engines are asked not to index this site', 'unstoppable-migration-inspector' ),
				'explanation' => __( '"Discourage search engines from indexing this site" is switched on. Staging and migration tools turn it on for copies, and it often stays on when a copy becomes the live site. Search engines will gradually drop the site from their results.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'If this is the live site, go to Settings > Reading and untick "Discourage search engines from indexing this site". Leave it on for staging copies.', 'unstoppable-migration-inspector' ),
			),
			'staging_visible' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'This looks like a staging address, but search engines may index it', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The Site Address looks like a staging, temporary or local address, and the site does not discourage search engines. A public copy can compete with the live site in search results.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'If this is a copy, switch on "Discourage search engines" in Settings > Reading or protect it with a password.', 'unstoppable-migration-inspector' ),
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
		$public  = '0' !== (string) get_option( 'blog_public' );
		$staging = Scan_Context::is_temporary_host( Location::host( (string) $context->get( 'location' ) ) ) || in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true );

		if ( ! $public && ! $staging ) {
			return array( ( new Finding( 'discouraged' ) )->on( 'option', 'blog_public' ) );
		}

		if ( $public && $staging ) {
			return array( ( new Finding( 'staging_visible' ) )->on( 'option', 'blog_public' ) );
		}

		return array();
	}
}
