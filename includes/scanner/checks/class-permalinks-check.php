<?php
/**
 * Permalinks and rewrite rules.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Pretty permalinks need stored rewrite rules and, on Apache and
 * LiteSpeed, an .htaccess file whose RewriteBase matches the site path.
 * The .htaccess file is only read, never written.
 */
final class Permalinks_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'permalinks';
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
		return __( 'Permalinks', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		$refresh = __( 'Go to Settings > Permalinks and click Save Changes without changing anything. This rebuilds the rules.', 'unstoppable-migration-inspector' );

		return array(
			'rules_missing'    => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Permalink rules have not been built', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Pretty permalinks are switched on, but WordPress has no stored rewrite rules. Until they are rebuilt, posts and pages other than the home page can show "page not found".', 'unstoppable-migration-inspector' ),
				'action'      => $refresh,
			),
			'htaccess_missing' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'The .htaccess file is missing or has no WordPress rules', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The server runs Apache or LiteSpeed, which need WordPress\'s rules in the .htaccess file for pretty permalinks. The file was not found, or does not contain them. .htaccess files are hidden and often left behind when copying files.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the .htaccess file from the old server, or save the Permalinks settings so WordPress writes it (the folder must be writable). If your host uses nginx in front, ask them.', 'unstoppable-migration-inspector' ),
			),
			'htaccess_base'    => array(
				'severity'    => Severity::WARNING,
				'title'       => __( '.htaccess points to a different folder', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The RewriteBase in .htaccess does not match where the site now lives. This is typical after moving a site into or out of a subfolder, and makes most pages show "not found" or the wrong site.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Edit .htaccess so RewriteBase (and the final RewriteRule) use the site\'s current path, or delete WordPress\'s block and save the Permalinks settings to regenerate it. Back up the file first.', 'unstoppable-migration-inspector' ),
			),
			'plain_permalinks' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'The site uses plain permalinks', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Links look like ?p=123. If the old site used pretty links (such as /my-post/), the setting was reset during the move, and existing links from search engines and other sites may now redirect or break.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'If the old site used a different permalink structure, set the same one in Settings > Permalinks.', 'unstoppable-migration-inspector' ),
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
		$structure = (string) get_option( 'permalink_structure' );

		if ( '' === $structure ) {
			return array( ( new Finding( 'plain_permalinks' ) )->on( 'option', 'permalink_structure' ) );
		}

		$findings = array();
		$rules    = Db_Info::raw_option( 'rewrite_rules' );

		if ( null === $rules || '' === $rules || 'a:0:{}' === $rules ) {
			$findings[] = ( new Finding( 'rules_missing' ) )->on( 'option', 'rewrite_rules' );
		}

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( false === strpos( $software, 'apache' ) && false === strpos( $software, 'litespeed' ) ) {
			return $findings;
		}

		$file = ABSPATH . '.htaccess';

		if ( ! is_readable( $file ) ) {
			$findings[] = ( new Finding( 'htaccess_missing' ) )->on( 'file', '.htaccess' );
			return $findings;
		}

		$contents = (string) file_get_contents( $file, false, null, 0, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read only, size-limited.

		if ( false === stripos( $contents, 'index.php' ) || false === stripos( $contents, 'RewriteRule' ) ) {
			$findings[] = ( new Finding( 'htaccess_missing' ) )->on( 'file', '.htaccess' );
			return $findings;
		}

		$expected = self::expected_base( (string) $context->get( 'home' ) );

		if ( preg_match( '/^\s*RewriteBase\s+(\S+)/mi', $contents, $match ) && trailingslashit( $match[1] ) !== $expected ) {
			$findings[] = ( new Finding( 'htaccess_base', $match[1] . ' → ' . $expected ) )->on( 'file', '.htaccess', 'RewriteBase' );
		}

		return $findings;
	}

	/**
	 * RewriteBase WordPress would write for a home URL.
	 *
	 * @param string $home Home URL.
	 * @return string With leading and trailing slash.
	 */
	public static function expected_base( $home ) {
		$path = (string) wp_parse_url( $home, PHP_URL_PATH );

		return '/' . ( '' === trim( $path, '/' ) ? '' : trim( $path, '/' ) . '/' );
	}
}
