<?php
/**
 * What a scan knows about the site, fixed when the scan starts.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Core\Settings;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Built once in the scan's first step and stored in the job state, so every
 * later step (possibly in a different request) sees the same facts.
 *
 * Old locations come from four sources, strongest first:
 * - settings:  previous domains the administrator entered (confirmed)
 * - snapshot:  the address a migration snapshot was taken on (likely)
 * - guid:      the address records were created on; WordPress never
 *              rewrites a post's GUID, so it remembers earlier addresses
 *              (likely)
 * - temporary: staging, local and host-temporary addresses seen in recent
 *              content (possible)
 */
final class Scan_Context {

	/**
	 * Most old locations a scan searches for.
	 */
	const MAX_LOCATIONS = 12;

	/**
	 * Host patterns that are almost never a site's permanent address.
	 */
	const TEMPORARY_PATTERNS = array(
		'/^localhost$/',
		'/^(\d{1,3}\.){3}\d{1,3}$/',
		'/\.(local|localhost|test|invalid|example|lan|internal)$/',
		'/^(staging|stage|stg|dev|development|test|testing|preprod|uat)[0-9]*\./',
		'/\.(staging|stage|dev)\./',
		'/\.(wpengine\.com|wpenginepowered\.com|kinsta\.cloud|flywheelsites\.com|flywheelstaging\.com|pantheonsite\.io|myftpupload\.com|cloudwaysapps\.com|wpcomstaging\.com|instawp\.xyz|instawp\.co|tastewp\.com|lndo\.site|ddev\.site|sg-host\.com|mystagingwebsite\.com|hostingersite\.com|temp\.domains|azurewebsites\.net|herokuapp\.com|ngrok\.io|ngrok-free\.app|trycloudflare\.com)$/',
	);

	/**
	 * Context data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Wrap stored context data.
	 *
	 * @param array<string, mixed> $data Data from build() or the job state.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Read the current site and detect old locations.
	 *
	 * @param int $batch_size Rows per batch.
	 * @return array<string, mixed>
	 */
	public static function build( $batch_size ) {
		global $wpdb;

		$home    = (string) get_option( 'home' );
		$siteurl = (string) get_option( 'siteurl' );
		$uploads = wp_upload_dir( null, false );
		$current = Location::from_url( $home );

		$data = array(
			'home'             => $home,
			'siteurl'          => $siteurl,
			'location'         => $current,
			'site_location'    => Location::from_url( $siteurl ),
			'https'            => 0 === strpos( strtolower( $home ), 'https://' ),
			'request_https'    => ( defined( 'WP_CLI' ) && WP_CLI ) ? null : is_ssl(),
			'uploads_basedir'  => isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '',
			'uploads_baseurl'  => isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '',
			'uploads_error'    => ! empty( $uploads['error'] ) ? wp_strip_all_tags( (string) $uploads['error'] ) : '',
			'prefix'           => $wpdb->prefix,
			'base_prefix'      => $wpdb->base_prefix,
			'multisite'        => is_multisite(),
			'blog_id'          => get_current_blog_id(),
			'batch_size'       => max( 50, min( 5000, (int) $batch_size ) ),
			'old_locations'    => array(),
			'candidates_found' => array(),
		);

		$data['old_locations'] = self::detect_old_locations( $current, Location::from_url( $siteurl ) );

		return $data;
	}

	/**
	 * Old locations to search for, strongest source first.
	 *
	 * @param string $current      Current home location.
	 * @param string $current_site Current siteurl location.
	 * @return array<int, array{location: string, source: string, confidence: string, shadows: string[]}>
	 */
	public static function detect_old_locations( $current, $current_site ) {
		$found = array();
		$own   = array_filter( array( $current, $current_site ) );

		$add = static function ( $location, $source, $confidence ) use ( &$found, $own ) {
			$location = (string) $location;

			if ( '' === $location || in_array( $location, $own, true ) || isset( $found[ $location ] ) ) {
				return;
			}

			$found[ $location ] = array(
				'location'   => $location,
				'source'     => $source,
				'confidence' => $confidence,
			);
		};

		foreach ( (array) Settings::get( 'previous_domains' ) as $url ) {
			$add( Location::from_url( $url ), 'settings', 'confirmed' );
		}

		$snapshots = new Snapshot_Repository();
		foreach ( $snapshots->all( 20 ) as $snapshot ) {
			$add( Location::from_url( (string) $snapshot['source_home'] ), 'snapshot', 'likely' );
		}

		foreach ( self::guid_locations() as $location ) {
			$add( $location, 'guid', 'likely' );
		}

		foreach ( self::temporary_locations( $current ) as $location ) {
			$add( $location, 'temporary', 'possible' );
		}

		$found = array_slice( array_values( $found ), 0, self::MAX_LOCATIONS );

		foreach ( $found as $i => $entry ) {
			$found[ $i ]['shadows'] = self::shadows( $entry['location'], $own );
		}

		return $found;
	}

	/**
	 * Current prefixes that start with an old location and so must not be
	 * counted as references to it.
	 *
	 * @param string   $old Old location.
	 * @param string[] $own Current locations.
	 * @return string[] Prefixes in "//location" form.
	 */
	public static function shadows( $old, array $own ) {
		$shadows = array();

		foreach ( $own as $location ) {
			if ( 0 === strpos( $location, $old . '/' ) ) {
				$shadows[] = '//' . $location;
			}
		}

		return array_values( array_unique( $shadows ) );
	}

	/**
	 * Base locations remembered in post GUIDs.
	 *
	 * @return string[]
	 */
	private static function guid_locations() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only sample of the oldest GUIDs.
		$oldest = (array) $wpdb->get_col( "SELECT guid FROM {$wpdb->posts} WHERE post_type IN ('post','page','attachment','product') ORDER BY ID ASC LIMIT 500" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only sample of the newest GUIDs.
		$newest = (array) $wpdb->get_col( "SELECT guid FROM {$wpdb->posts} WHERE post_type IN ('post','page','attachment','product') ORDER BY ID DESC LIMIT 500" );
		$guids  = array_merge( $oldest, $newest );

		$counts = array();

		foreach ( $guids as $guid ) {
			$base = self::guid_base( (string) $guid );

			if ( '' !== $base ) {
				$counts[ $base ] = isset( $counts[ $base ] ) ? $counts[ $base ] + 1 : 1;
			}
		}

		arsort( $counts );

		return array_slice( array_keys( $counts ), 0, 6 );
	}

	/**
	 * The site address part of a GUID.
	 *
	 * GUIDs look like `http://site/path/?p=12` or, for media,
	 * `http://site/path/wp-content/uploads/2024/01/file.jpg`.
	 *
	 * @param string $guid GUID.
	 * @return string Location, or '' when the GUID has no recognisable base.
	 */
	public static function guid_base( $guid ) {
		$guid = strtolower( trim( $guid ) );

		if ( ! preg_match( '#^https?://#', $guid ) ) {
			return '';
		}

		$markers = array( '/?', '/wp-content/', '?' );

		foreach ( $markers as $marker ) {
			$at = strpos( $guid, $marker, 8 );

			if ( false !== $at ) {
				return Location::from_url( substr( $guid, 0, $at ) );
			}
		}

		return '';
	}

	/**
	 * Temporary or staging hosts linked from recent content.
	 *
	 * @param string $current Current location.
	 * @return string[]
	 */
	private static function temporary_locations( $current ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only sample of recent content.
		$rows = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','nav_menu_item') AND post_content LIKE '%//%' AND LENGTH(post_content) < 500000 ORDER BY ID DESC LIMIT 200" );

		$hosts = array();

		foreach ( (array) $rows as $content ) {
			if ( preg_match_all( '#(?:https?:)?(?://|\\\\/\\\\/)([a-z0-9.\-]+(?::\d+)?)#i', (string) $content, $matches ) ) {
				foreach ( $matches[1] as $host ) {
					$hosts[ strtolower( $host ) ] = true;
				}
			}
		}

		$current_host = Location::host( $current );
		$out          = array();

		foreach ( array_keys( $hosts ) as $host ) {
			$bare = explode( ':', $host, 2 );

			if ( $bare[0] === $current_host || ! self::is_temporary_host( $bare[0] ) ) {
				continue;
			}

			$out[] = $host;
		}

		return array_slice( $out, 0, 6 );
	}

	/**
	 * Whether a host looks like a staging, local or temporary address.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_temporary_host( $host ) {
		$host = strtolower( (string) $host );

		foreach ( self::TEMPORARY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Raw value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when the key is absent.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Old locations with their sources.
	 *
	 * @return array<int, array{location: string, source: string, confidence: string, shadows: string[]}>
	 */
	public function old_locations() {
		return (array) $this->get( 'old_locations', array() );
	}

	/**
	 * Rows per batch.
	 *
	 * @return int
	 */
	public function batch_size() {
		return (int) $this->get( 'batch_size', 500 );
	}

	/**
	 * Whether the site address uses HTTPS.
	 *
	 * @return bool
	 */
	public function is_https() {
		return (bool) $this->get( 'https', false );
	}

	/**
	 * All data, for storing.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}
}
