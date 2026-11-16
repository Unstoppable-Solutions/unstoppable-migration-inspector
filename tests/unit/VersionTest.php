<?php
/**
 * The version number must agree everywhere and have a changelog entry.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Version consistency.
 */
final class VersionTest extends TestCase {

	/**
	 * Read a file from the plugin root.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function file( $name ) {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a local file.
	}

	/**
	 * Header, constant, Stable tag, changelogs and the build note agree.
	 */
	public function test_version_is_consistent_everywhere() {
		$main      = $this->file( 'unstoppable-migration-inspector.php' );
		$readme    = $this->file( 'readme.txt' );
		$changelog = $this->file( 'CHANGELOG.md' );

		$this->assertMatchesRegularExpression( '/^ \* Version: (\d+\.\d+\.\d+)$/m', $main );
		preg_match( '/^ \* Version: (\d+\.\d+\.\d+)$/m', $main, $header );
		$version = $header[1];

		$this->assertStringContainsString( "define( 'UMIN_VERSION', '" . $version . "' );", $main, 'UMIN_VERSION matches the header.' );
		$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( $version, '/' ) . '$/m', $readme, 'Stable tag matches.' );
		$this->assertMatchesRegularExpression( '/^= ' . preg_quote( $version, '/' ) . ' =$/m', $readme, 'readme.txt has a changelog entry.' );
		$this->assertMatchesRegularExpression( '/^## \[' . preg_quote( $version, '/' ) . '\]/m', $changelog, 'CHANGELOG.md has a section.' );

		if ( false !== strpos( $readme, 'This is a development build (' ) ) {
			$this->assertStringContainsString( 'This is a development build (' . $version . ')', $readme, 'The development-build note matches.' );
		}
	}
}
