<?php
/**
 * Files linked from content that are not on disk.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Batched_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Row_Reader;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for links in post content to this site's uploads folder (by full
 * URL or by root-relative path) and checks whether each file exists. Links
 * to old addresses are reported by the old-address checks instead.
 */
final class Content_Media_Check extends Batched_Check {

	/**
	 * Rows read per batch.
	 */
	const ROWS_PER_BATCH = 100;

	/**
	 * Most missing files listed per post.
	 */
	const MAX_PER_POST = 25;

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'content_media';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'media';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Images and files linked from content', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'missing_linked_file' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Content links to uploaded files that are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'These posts and pages show or link to files in this site\'s uploads folder that do not exist on the server. Visitors see broken images or get "not found" errors on downloads.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the listed files from the old server or a backup into the same folders under wp-content/uploads, then run the scan again.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Applicability.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string
	 */
	public function applicable( Scan_Context $context ) {
		$basedir = (string) $context->get( 'uploads_basedir' );

		if ( '' === $basedir || ! is_dir( $basedir ) || '' === Location::from_url( (string) $context->get( 'uploads_baseurl' ) ) ) {
			return __( 'The uploads folder could not be found, so linked files cannot be checked.', 'unstoppable-migration-inspector' );
		}

		return true;
	}

	/**
	 * First cursor.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string, mixed>
	 */
	public function start( Scan_Context $context ) {
		global $wpdb;

		return array(
			'after' => 0,
			'max'   => Row_Reader::max_id( $wpdb->posts, 'ID' ),
		);
	}

	/**
	 * One batch.
	 *
	 * @param Scan_Context         $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @return array{findings: Finding[], cursor: array<string, mixed>|null, scanned: int}
	 */
	public function batch( Scan_Context $context, array $cursor ) {
		global $wpdb;

		$after = (int) $cursor['after'];
		$max   = (int) $cursor['max'];

		if ( $after >= $max ) {
			return array(
				'findings' => array(),
				'cursor'   => null,
				'scanned'  => 0,
			);
		}

		$base_location = Location::from_url( (string) $context->get( 'uploads_baseurl' ) );
		$base_path     = (string) wp_parse_url( (string) $context->get( 'uploads_baseurl' ), PHP_URL_PATH );
		$bases         = self::bases( $base_location, (string) $context->get( 'location' ), (string) $context->get( 'uploads_basedir' ) );
		$basedir       = trailingslashit( (string) $context->get( 'uploads_basedir' ) );
		$needle        = '' !== trim( $base_path, '/' ) ? trim( $base_path, '/' ) . '/' : $base_location;

		$found = Row_Reader::prefilter( $wpdb->posts, 'ID', array( 'post_content' ), array( Row_Reader::contains( $needle ) ), $after, $context->batch_size() * 10, self::ROWS_PER_BATCH, array( 'post_type', 'post_status' ) );
		$rows  = array();

		foreach ( $found['rows'] as $row ) {
			if ( 'revision' !== $row['post_type'] && 'auto-draft' !== $row['post_status'] ) {
				$rows[ (int) $row['id'] ] = $row;
			}
		}

		$findings = array();

		Row_Reader::fetch(
			$wpdb->posts,
			'ID',
			array( 'post_content' ),
			array_values( $rows ),
			function ( $id, array $values ) use ( $bases, $base_path, $basedir, $rows, &$findings ) {
				$missing = self::missing_files( (string) $values['post_content'], $bases, $base_path, $basedir );

				if ( ! empty( $missing ) ) {
					/* translators: %d: number of missing files. */
					$findings[] = ( new Finding( 'missing_linked_file', sprintf( _n( '%d missing file:', '%d missing files:', count( $missing ), 'unstoppable-migration-inspector' ), count( $missing ) ) ) )
						->on( 'post', $id, 'post_content' )
						->with(
							array(
								'post_type' => (string) $rows[ $id ]['post_type'],
								'files'     => array_slice( $missing, 0, self::MAX_PER_POST ),
								'total'     => count( $missing ),
							)
						);
				}
			}
		);

		$next = (int) $found['next'];

		return array(
			'findings' => $findings,
			'cursor'   => $next >= $max ? null : array(
				'after' => $next,
				'max'   => $max,
			),
			'scanned'  => count( $found['rows'] ),
		);
	}

	/**
	 * Locations under which this site's uploads are linked: the configured
	 * uploads URL, and the same folder under the Site Address (in case the
	 * uploads URL setting differs from how content links to files).
	 *
	 * @param string $base_location Uploads base location.
	 * @param string $home_location Site Address location.
	 * @param string $basedir       Uploads folder.
	 * @return string[]
	 */
	public static function bases( $base_location, $home_location, $basedir ) {
		$bases = array( $base_location );
		$root  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$dir   = untrailingslashit( wp_normalize_path( $basedir ) );

		if ( '' !== $home_location && 0 === strpos( $dir, $root . '/' ) ) {
			$bases[] = $home_location . '/' . strtolower( substr( $dir, strlen( $root ) + 1 ) );
		}

		return array_values( array_unique( array_filter( $bases ) ) );
	}

	/**
	 * Uploads-relative paths linked from HTML that do not exist.
	 *
	 * @param string          $html      HTML.
	 * @param string|string[] $bases     Uploads base locations (host/path).
	 * @param string          $base_path Uploads base URL path, e.g. /wp-content/uploads.
	 * @param string          $basedir   Uploads folder with trailing slash.
	 * @return string[] Relative paths, unique.
	 */
	public static function missing_files( $html, $bases, $base_path, $basedir ) {
		$patterns = array();

		foreach ( (array) $bases as $base ) {
			$patterns[] = '#//' . preg_quote( $base, '#' ) . '/([^"\'\s<>()\\\\?\#]+)#i';
		}

		$path = '/' . trim( (string) $base_path, '/' );

		if ( '/' !== $path ) {
			$patterns[] = '#(?<=["\'(=\s])' . preg_quote( $path, '#' ) . '/([^"\'\s<>()\\\\?\#]+)#i';
		}

		$relative = array();

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					$file = rawurldecode( html_entity_decode( $match, ENT_QUOTES ) );

					if ( '' === $file || false !== strpos( $file, '..' ) || '/' === substr( $file, -1 ) ) {
						continue;
					}

					$relative[ $file ] = true;
				}
			}
		}

		$missing = array();

		foreach ( array_keys( $relative ) as $file ) {
			if ( ! file_exists( $basedir . $file ) ) {
				$missing[] = $file;
			}
		}

		return $missing;
	}
}
