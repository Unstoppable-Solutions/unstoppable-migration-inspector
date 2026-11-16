<?php
/**
 * Tests for scanner helpers that need no database.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey\Functions;
use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;
use UnstoppableSolutions\MigrationInspector\Scanner\Checks\Attachment_Files_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Checks\Content_Media_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Checks\Mixed_Content_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Checks\Permalinks_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Handler;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

/**
 * Scanner tests.
 */
final class ScannerTest extends Test_Case {

	/**
	 * Temporary uploads folder.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/\\' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/\\' );
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $value ) {
				return str_replace( '\\', '/', $value );
			}
		);
		Functions\when( 'is_serialized' )->alias(
			static function ( $value ) {
				return is_string( $value ) && preg_match( '/^[aOsibd]:/', $value );
			}
		);

		$this->dir = sys_get_temp_dir() . '/umin-scan-test-' . uniqid() . '/';
		mkdir( $this->dir . '2020/01', 0777, true );
	}

	/**
	 * Remove temporary files.
	 */
	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '2020/01/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir . '2020/01' );
		rmdir( $this->dir . '2020' );
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * Locations drop the scheme, lower-case the host and trim slashes.
	 */
	public function test_location_from_url() {
		$this->assertSame( 'example.com', Location::from_url( 'https://Example.COM/' ) );
		$this->assertSame( 'example.com/staging01', Location::from_url( 'http://example.com/Staging01/' ) );
		$this->assertSame( 'example.com:8080', Location::from_url( 'http://example.com:8080' ) );
		$this->assertSame( '', Location::from_url( 'not a url' ) );
		$this->assertSame( 'example.com', Location::host( 'example.com:8080/sub' ) );
	}

	/**
	 * Plain, JSON-escaped and URL-encoded forms are all counted, at a boundary.
	 */
	public function test_location_count_all_encodings_with_boundary() {
		$text = strtolower( 'a http://old.com/x b {"u":"https:\/\/old.com\/y"} c ?r=https%3A%2F%2Fold.com%2Fz d //old.com.au/ e //old.community/ f //bold.com/' );

		$this->assertSame( 3, Location::count( $text, '//old.com' ) );
	}

	/**
	 * Links to a longer current location are not counted as old references.
	 */
	public function test_count_excluding_shadow() {
		$text = '//example.com/staging01/page //example.com/staging01/ //example.com/about/';

		$this->assertSame( 1, Location::count_excluding( $text, '//example.com', array( '//example.com/staging01' ) ) );
		$this->assertSame( 0, Location::count_excluding( '//example.com/staging01/x', '//example.com', array( '//example.com/staging01' ) ) );
	}

	/**
	 * Shadows: only current locations that extend the old one.
	 */
	public function test_shadows() {
		$this->assertSame( array( '//example.com/staging01' ), Scan_Context::shadows( 'example.com', array( 'example.com/staging01' ) ) );
		$this->assertSame( array(), Scan_Context::shadows( 'example.com/staging01', array( 'example.com' ) ) );
		$this->assertSame( array(), Scan_Context::shadows( 'example.co', array( 'example.com' ) ) );
	}

	/**
	 * GUID bases.
	 */
	public function test_guid_base() {
		$this->assertSame( 'old.com', Scan_Context::guid_base( 'http://old.com/?p=12' ) );
		$this->assertSame( 'old.com/blog', Scan_Context::guid_base( 'https://old.com/blog/?page_id=3' ) );
		$this->assertSame( 'old.com', Scan_Context::guid_base( 'http://old.com/wp-content/uploads/2020/01/a.jpg' ) );
		$this->assertSame( '', Scan_Context::guid_base( 'urn:uuid:1234' ) );
		$this->assertSame( '', Scan_Context::guid_base( 'http://old.com/pretty-permalink/' ) );
	}

	/**
	 * Temporary host patterns.
	 */
	public function test_temporary_hosts() {
		foreach ( array( 'localhost', '127.0.0.1', 'mysite.local', 'shop.test', 'staging.example.com', 'dev2.example.com', 'mysite.wpengine.com', 'abc.kinsta.cloud', 'x.instawp.xyz', 'site.sg-host.com' ) as $host ) {
			$this->assertTrue( Scan_Context::is_temporary_host( $host ), $host );
		}
		foreach ( array( 'example.com', 'developer.example.com', 'shop.co.uk', 'testing-tools.com', 'staging-area.com' ) as $host ) {
			$this->assertFalse( Scan_Context::is_temporary_host( $host ), $host );
		}
	}

	/**
	 * Loaded resources over http:// are found; links and own host are not.
	 */
	public function test_insecure_hosts() {
		$html = '<img src="http://cdn.example.org/a.jpg"><img srcset="https://x.org/a.jpg 1x, http://img.example.net/b.jpg 2x">'
			. '<div style="background:url(http://bg.example.com/c.png)"></div><a href="http://link.example.org/">x</a>'
			. '<img src="http://mysite.com/d.jpg"><link rel="stylesheet" href="http://css.example.com/s.css">';

		$this->assertSame(
			array(
				'bg.example.com'  => 1,
				'cdn.example.org' => 1,
				'css.example.com' => 1,
				'img.example.net' => 1,
			),
			Mixed_Content_Check::insecure_hosts( $html, array( 'mysite.com' ) )
		);
	}

	/**
	 * Missing linked files: full URL and root-relative forms; present files skipped.
	 */
	public function test_missing_linked_files() {
		touch( $this->dir . '2020/01/present.jpg' );
		$html = '<img src="https://site.com/wp-content/uploads/2020/01/present.jpg"><img src="https://site.com/wp-content/uploads/2020/01/gone.jpg?ver=1">'
			. '<a href="/wp-content/uploads/2020/01/my%20file.pdf">x</a><img src="https://other.com/wp-content/uploads/2020/01/not-ours.jpg">'
			. '<a href="https://site.com/wp-content/uploads/../../wp-config.php">x</a>';

		$this->assertSame(
			array( '2020/01/gone.jpg', '2020/01/my file.pdf' ),
			Content_Media_Check::missing_files( $html, array( 'site.com/wp-content/uploads' ), '/wp-content/uploads', $this->dir )
		);
	}

	/**
	 * Attachment paths: relative, absolute inside uploads, absolute from another server.
	 */
	public function test_resolve_attachment_paths() {
		$this->assertSame( $this->dir . '2020/01/a.jpg', Attachment_Files_Check::resolve( '2020/01/a.jpg', $this->dir ) );
		$this->assertSame( $this->dir . '2020/01/b.jpg', Attachment_Files_Check::resolve( $this->dir . '2020/01/b.jpg', $this->dir ) );
		$this->assertNull( Attachment_Files_Check::resolve( '/home/old/public_html/wp-content/uploads/c.jpg', $this->dir ) );
	}

	/**
	 * Missing sizes; serialized objects are never instantiated.
	 */
	public function test_missing_sizes_and_objects_refused() {
		touch( $this->dir . '2020/01/a-150x150.jpg' );
		$meta = serialize(
			array(
				'sizes' => array(
					'thumbnail' => array( 'file' => 'a-150x150.jpg' ),
					'medium'    => array( 'file' => 'a-300x300.jpg' ),
				),
			)
		);

		$this->assertSame( array( 'medium' => 'a-300x300.jpg' ), Attachment_Files_Check::missing_sizes( $meta, $this->dir . '2020/01' ) );
		$this->assertSame( array(), Attachment_Files_Check::missing_sizes( 'O:8:"stdClass":1:{s:5:"sizes";a:0:{}}', $this->dir ) );
		$this->assertSame( array(), Attachment_Files_Check::missing_sizes( 'not serialized', $this->dir ) );
	}

	/**
	 * RewriteBase WordPress would write.
	 */
	public function test_expected_rewrite_base() {
		$this->assertSame( '/', Permalinks_Check::expected_base( 'https://example.com' ) );
		$this->assertSame( '/', Permalinks_Check::expected_base( 'https://example.com/' ) );
		$this->assertSame( '/staging01/', Permalinks_Check::expected_base( 'https://example.com/staging01' ) );
	}

	/**
	 * Category status from check results.
	 */
	public function test_category_status() {
		$none = array_fill_keys( Severity::all(), 0 );

		$this->assertSame( Health_Summary::STATUS_CRITICAL, Scan_Handler::category_status( array( 'issues' ), array_merge( $none, array( Severity::CRITICAL => 1 ) ) ) );
		$this->assertSame( Health_Summary::STATUS_WARNING, Scan_Handler::category_status( array( 'issues' ), array_merge( $none, array( Severity::WARNING => 2 ) ) ) );
		$this->assertSame( Health_Summary::STATUS_PASSED, Scan_Handler::category_status( array( 'passed' ), array_merge( $none, array( Severity::NOTICE => 5 ) ) ) );
		$this->assertSame( Health_Summary::STATUS_NOT_APPLICABLE, Scan_Handler::category_status( array( 'not_applicable' ), $none ) );
		$this->assertSame( Health_Summary::STATUS_NOT_TESTED, Scan_Handler::category_status( array( 'passed', 'error' ), $none ) );
	}

	/**
	 * Severity ordering and validation.
	 */
	public function test_severity() {
		$this->assertLessThan( Severity::rank( Severity::WARNING ), Severity::rank( Severity::CRITICAL ) );
		$this->assertLessThan( Severity::rank( Severity::NOTICE ), Severity::rank( Severity::WARNING ) );
		$this->assertFalse( Severity::is_valid( 'info' ) );
	}
}
