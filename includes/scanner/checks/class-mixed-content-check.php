<?php
/**
 * Insecure resources loaded by content on an HTTPS site.
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
 * Finds http:// URLs that a browser would load (src, srcset, poster,
 * data-src, CSS url(), stylesheet links) in post content. Plain links
 * (<a href="http://…">) are not mixed content and are not reported.
 * This site's own address is reported by the "insecure links" checks
 * instead, so it is left out here.
 */
final class Mixed_Content_Check extends Batched_Check {

	/**
	 * Rows read per batch.
	 */
	const ROWS_PER_BATCH = 100;

	/**
	 * Resource URL patterns. Group 1 is the host.
	 */
	const PATTERNS = array(
		'#\b(?:src|data-src|data-lazy-src|poster|data)\s*=\s*["\']?\s*http://([a-z0-9.\-]+(?::\d+)?)#i',
		'#\bsrcset\s*=\s*["\'][^"\']*?http://([a-z0-9.\-]+(?::\d+)?)#i',
		'#url\(\s*["\']?http://([a-z0-9.\-]+(?::\d+)?)#i',
		'#<link\b[^>]*\bhref\s*=\s*["\']http://([a-z0-9.\-]+(?::\d+)?)[^"\']*\.css#i',
	);

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'mixed_content';
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
		return __( 'Insecure images, scripts and styles in content', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'insecure_resource' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Content loads files from other sites over http://', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'These posts and pages embed images, videos, scripts or styles from other websites using http:// on a site that uses HTTPS. Browsers block or upgrade such "mixed content", so the files may not appear, and the padlock icon can disappear.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Open each listed item and check whether the other site supports https://. If it does, change the link to https://. If it does not, host the file on this site or remove it.', 'unstoppable-migration-inspector' ),
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
		return $context->is_https() ? true : __( 'The site does not use HTTPS, so there is no mixed content.', 'unstoppable-migration-inspector' );
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

		$own   = array_filter( array( Location::host( (string) $context->get( 'location' ) ), Location::host( (string) $context->get( 'site_location' ) ) ) );
		$found = Row_Reader::prefilter( $wpdb->posts, 'ID', array( 'post_content' ), array( Row_Reader::contains( 'http://' ) ), $after, $context->batch_size() * 10, self::ROWS_PER_BATCH, array( 'post_type', 'post_status' ) );
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
			function ( $id, array $values ) use ( $own, $rows, &$findings ) {
				$hosts = self::insecure_hosts( (string) $values['post_content'], $own );

				if ( ! empty( $hosts ) ) {
					$findings[] = ( new Finding( 'insecure_resource', implode( ', ', array_keys( $hosts ) ) ) )
						->on( 'post', $id, 'post_content' )
						->with(
							array(
								'post_type' => (string) $rows[ $id ]['post_type'],
								'hosts'     => $hosts,
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
	 * Hosts loaded over http:// in a piece of HTML, with counts.
	 *
	 * @param string   $html HTML.
	 * @param string[] $own  This site's hosts (left out).
	 * @return array<string, int>
	 */
	public static function insecure_hosts( $html, array $own ) {
		$hosts = array();

		foreach ( self::PATTERNS as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $host ) {
				$host = strtolower( $host );
				$bare = explode( ':', $host, 2 );

				if ( in_array( $bare[0], $own, true ) ) {
					continue;
				}

				$hosts[ $host ] = isset( $hosts[ $host ] ) ? $hosts[ $host ] + 1 : 1;
			}
		}

		ksort( $hosts );

		return $hosts;
	}
}
