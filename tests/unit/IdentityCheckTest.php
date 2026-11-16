<?php
/**
 * Tests for the snapshot identity verdict rules.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey\Functions;
use UnstoppableSolutions\MigrationInspector\Snapshots\Identity_Check;

/**
 * Identity check tests.
 */
final class IdentityCheckTest extends Test_Case {

	/**
	 * Stub number formatting.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals );
			}
		);
	}

	/**
	 * Signals with defaults.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function signals( array $overrides ) {
		return array_merge(
			array(
				'key_match'    => null,
				'name_match'   => false,
				'domain_match' => false,
				'prefix_match' => false,
				'id_overlap'   => null,
			),
			$overrides
		);
	}

	public function test_matching_site_key_with_high_overlap_is_same_site_without_ack() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => true, 'id_overlap' => 0.98 ) ) );

		$this->assertSame( Identity_Check::SAME_SITE, $result['verdict'] );
		$this->assertFalse( $result['needs_ack'] );
	}

	/**
	 * A matching key must not hide that most records are absent: an old
	 * staging copy or a cloned template shares the key but has diverged.
	 */
	public function test_matching_site_key_with_low_overlap_needs_ack() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => true, 'id_overlap' => 0.1 ) ) );

		$this->assertSame( Identity_Check::UNVERIFIED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
		$this->assertNotEmpty( $result['reasons'] );
	}

	/**
	 * Matching key, partial overlap: likely the same, with the overlap explained.
	 */
	public function test_matching_site_key_with_partial_overlap_is_likely_same() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => true, 'id_overlap' => 0.7 ) ) );

		$this->assertSame( Identity_Check::LIKELY_SAME, $result['verdict'] );
		$this->assertFalse( $result['needs_ack'] );
		$this->assertStringContainsString( '70%', implode( ' ', $result['reasons'] ) );
	}

	/**
	 * Address, prefix and name differences are reported even when the key matches.
	 */
	public function test_matching_site_key_still_reports_other_differences() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => true, 'id_overlap' => 1.0, 'domain_match' => false, 'prefix_match' => false, 'name_match' => false ) ) );

		$this->assertSame( Identity_Check::SAME_SITE, $result['verdict'] );
		$this->assertCount( 3, $result['reasons'] );
	}

	public function test_high_overlap_with_another_signal_is_likely_same() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => false, 'prefix_match' => true, 'id_overlap' => 0.96 ) ) );

		$this->assertSame( Identity_Check::LIKELY_SAME, $result['verdict'] );
		$this->assertFalse( $result['needs_ack'] );
		$this->assertNotEmpty( $result['reasons'], 'A key mismatch is still explained.' );
	}

	public function test_high_overlap_alone_needs_ack() {
		$result = Identity_Check::decide( $this->signals( array( 'id_overlap' => 0.95 ) ) );

		$this->assertSame( Identity_Check::UNVERIFIED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
	}

	public function test_partial_overlap_needs_ack() {
		$result = Identity_Check::decide( $this->signals( array( 'domain_match' => true, 'id_overlap' => 0.6 ) ) );

		$this->assertSame( Identity_Check::UNVERIFIED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
	}

	public function test_low_overlap_is_likely_unrelated_even_if_names_match() {
		$result = Identity_Check::decide( $this->signals( array( 'key_match' => false, 'name_match' => true, 'domain_match' => true, 'id_overlap' => 0.2 ) ) );

		$this->assertSame( Identity_Check::LIKELY_UNRELATED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
	}

	public function test_nothing_to_sample_and_nothing_matching_is_unrelated() {
		$result = Identity_Check::decide( $this->signals( array() ) );

		$this->assertSame( Identity_Check::LIKELY_UNRELATED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
	}

	public function test_nothing_to_sample_but_name_matches_is_unverified() {
		$result = Identity_Check::decide( $this->signals( array( 'name_match' => true ) ) );

		$this->assertSame( Identity_Check::UNVERIFIED, $result['verdict'] );
		$this->assertTrue( $result['needs_ack'] );
	}
}
