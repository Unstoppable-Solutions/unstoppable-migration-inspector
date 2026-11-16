<?php
/**
 * Whether the site uses HTTPS.
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
 * Site address scheme versus the connection the scan was started over.
 * No outbound requests are made.
 */
final class Https_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'https_setting';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'https';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'HTTPS setting', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'https_not_used' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'HTTPS works, but the site address uses http://', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'You are connected over HTTPS, so the server has a certificate, but the Site Address still starts with http://. Visitors are sent to the insecure address, browsers mark pages "Not secure", and search engines may index both versions.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Change both addresses in Settings > General to https://, then check for http:// links to this site (run the scan again afterwards).', 'unstoppable-migration-inspector' ),
			),
			'no_https'       => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'The site does not use HTTPS', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The Site Address starts with http://. Browsers mark such sites "Not secure", and logins and checkout data travel unencrypted. If the old host had HTTPS, the certificate may not have been set up on the new one yet.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Ask your host to issue a certificate (most offer free Let\'s Encrypt certificates), then switch the addresses to https://.', 'unstoppable-migration-inspector' ),
			),
			'insecure_visit' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'This page was opened over http:// on an HTTPS site', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The Site Address uses HTTPS, but this admin page was loaded over plain http://. The server may not be redirecting insecure requests to HTTPS.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Open the admin with https:// and ask your host to redirect all http:// requests to https://.', 'unstoppable-migration-inspector' ),
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
		$request = $context->get( 'request_https' );

		if ( ! $context->is_https() ) {
			return array( ( new Finding( true === $request ? 'https_not_used' : 'no_https' ) )->on( 'setting', 'home' ) );
		}

		if ( false === $request ) {
			return array( ( new Finding( 'insecure_visit' ) )->on( 'setting', 'home' ) );
		}

		return array();
	}
}
