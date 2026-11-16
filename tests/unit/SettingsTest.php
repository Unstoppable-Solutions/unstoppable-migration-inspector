<?php
/**
 * Tests for Settings validation and scoped (per-tab) saves.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use UnstoppableSolutions\MigrationInspector\Core\Settings;

/**
 * Settings tests.
 */
final class SettingsTest extends Test_Case {

	/**
	 * A fully customised settings array, different from every default.
	 *
	 * @return array<string, mixed>
	 */
	private function customised() {
		return array(
			'previous_domains'         => array( 'https://old.example.com' ),
			'batch_size'               => 1200,
			'time_budget'              => 5,
			'report_retention_days'    => 30,
			'delete_data_on_uninstall' => true,
		);
	}

	public function test_defaults_keep_data_on_uninstall() {
		$this->assertFalse( Settings::defaults()['delete_data_on_uninstall'] );
	}

	public function test_saving_general_tab_leaves_other_tabs_untouched() {
		$current = $this->customised();

		$result = Settings::merge_submission(
			array(
				'_declared'        => array( 'previous_domains' ),
				'previous_domains' => "staging.example.com\nhttp://temp.host.test/site",
			),
			$current
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array( 'https://staging.example.com', 'http://temp.host.test/site' ),
			$result['settings']['previous_domains']
		);
		$this->assertSame( 1200, $result['settings']['batch_size'] );
		$this->assertSame( 5, $result['settings']['time_budget'] );
		$this->assertSame( 30, $result['settings']['report_retention_days'] );
		$this->assertTrue( $result['settings']['delete_data_on_uninstall'] );
	}

	public function test_saving_performance_tab_leaves_other_tabs_untouched() {
		$current = $this->customised();

		$result = Settings::merge_submission(
			array(
				'_declared'   => array( 'batch_size', 'time_budget' ),
				'batch_size'  => '250',
				'time_budget' => '12',
			),
			$current
		);

		$this->assertSame( 250, $result['settings']['batch_size'] );
		$this->assertSame( 12, $result['settings']['time_budget'] );
		$this->assertSame( array( 'https://old.example.com' ), $result['settings']['previous_domains'] );
		$this->assertSame( 30, $result['settings']['report_retention_days'] );
		$this->assertTrue( $result['settings']['delete_data_on_uninstall'] );
	}

	public function test_unchecked_box_on_saved_tab_turns_off() {
		$current = $this->customised();

		$result = Settings::merge_submission(
			array(
				'_declared'             => array( 'report_retention_days', 'delete_data_on_uninstall' ),
				'report_retention_days' => '30',
			),
			$current
		);

		$this->assertFalse( $result['settings']['delete_data_on_uninstall'] );
	}

	public function test_checkbox_on_another_tab_is_not_turned_off() {
		$current = $this->customised();

		$result = Settings::merge_submission(
			array(
				'_declared'  => array( 'batch_size' ),
				'batch_size' => '300',
			),
			$current
		);

		$this->assertTrue( $result['settings']['delete_data_on_uninstall'] );
	}

	public function test_undeclared_posted_field_is_ignored() {
		$current = $this->customised();

		$result = Settings::merge_submission(
			array(
				'_declared'                => array( 'batch_size' ),
				'batch_size'               => '300',
				'delete_data_on_uninstall' => '0',
				'time_budget'              => '19',
			),
			$current
		);

		$this->assertTrue( $result['settings']['delete_data_on_uninstall'] );
		$this->assertSame( 5, $result['settings']['time_budget'] );
	}

	public function test_unknown_keys_cannot_be_injected() {
		$result = Settings::merge_submission(
			array(
				'_declared' => array( 'batch_size', 'is_admin', 'siteurl' ),
				'is_admin'  => '1',
				'siteurl'   => 'https://evil.test',
			),
			$this->customised()
		);

		$this->assertArrayNotHasKey( 'is_admin', $result['settings'] );
		$this->assertArrayNotHasKey( 'siteurl', $result['settings'] );
		$this->assertArrayNotHasKey( '_declared', $result['settings'] );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $result['settings'] ) );
	}

	public function test_out_of_range_number_is_rejected_and_old_value_kept() {
		$result = Settings::merge_submission(
			array(
				'_declared'  => array( 'batch_size' ),
				'batch_size' => '999999',
			),
			$this->customised()
		);

		$this->assertSame( 1200, $result['settings']['batch_size'] );
		$this->assertArrayHasKey( 'batch_size', $result['errors'] );
	}

	public function test_non_numeric_number_is_rejected() {
		$result = Settings::merge_submission(
			array(
				'_declared'   => array( 'time_budget' ),
				'time_budget' => '5; DROP TABLE',
			),
			$this->customised()
		);

		$this->assertSame( 5, $result['settings']['time_budget'] );
		$this->assertArrayHasKey( 'time_budget', $result['errors'] );
	}

	public function test_one_invalid_domain_rejects_the_whole_list() {
		$result = Settings::merge_submission(
			array(
				'_declared'        => array( 'previous_domains' ),
				'previous_domains' => "good.example.com\nnot a domain\nftp://files.example.com",
			),
			$this->customised()
		);

		$this->assertSame( array( 'https://old.example.com' ), $result['settings']['previous_domains'] );
		$this->assertArrayHasKey( 'previous_domains', $result['errors'] );
	}

	public function test_too_many_domains_is_rejected() {
		$lines = array();
		for ( $i = 1; $i <= Settings::MAX_PREVIOUS_DOMAINS + 1; $i++ ) {
			$lines[] = 'site' . $i . '.example.com';
		}

		$result = Settings::merge_submission(
			array(
				'_declared'        => array( 'previous_domains' ),
				'previous_domains' => implode( "\n", $lines ),
			),
			$this->customised()
		);

		$this->assertArrayHasKey( 'previous_domains', $result['errors'] );
	}

	public function test_programmatic_full_save_applies_every_field() {
		$result = Settings::merge_submission( $this->customised(), Settings::defaults() );

		$this->assertSame( $this->customised(), $result['settings'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Domain normalisation cases.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function domain_provider() {
		return array(
			'bare host'              => array( 'Old.Example.COM', 'https://old.example.com' ),
			'http url'               => array( 'http://old.example.com/', 'http://old.example.com' ),
			'subdirectory'           => array( 'https://example.com/blog/', 'https://example.com/blog' ),
			'port'                   => array( 'http://localhost:8080', 'http://localhost:8080' ),
			'ip address'             => array( '192.168.1.20', 'https://192.168.1.20' ),
			'credentials rejected'   => array( 'https://user:pass@example.com', '' ),
			'query rejected'         => array( 'https://example.com/?a=1', '' ),
			'other scheme rejected'  => array( 'javascript://example.com', '' ),
			'spaces rejected'        => array( 'exa mple.com', '' ),
			'empty rejected'         => array( '', '' ),
			'bad characters'         => array( 'exa_mple!.com', '' ),
			'leading hyphen label'   => array( '-bad.example.com', '' ),
		);
	}

	/**
	 * Domain entries are normalised or rejected.
	 *
	 * @dataProvider domain_provider
	 *
	 * @param string $input    Entry.
	 * @param string $expected Expected normalised value.
	 */
	public function test_normalize_domain( $input, $expected ) {
		$this->assertSame( $expected, Settings::normalize_domain( $input ) );
	}

	public function test_all_falls_back_to_defaults_for_corrupt_stored_values() {
		$this->options[ Settings::OPTION ] = array(
			'batch_size'               => 'lots',
			'time_budget'              => 9,
			'delete_data_on_uninstall' => 'maybe',
			'unexpected'               => 'x',
		);

		$all = Settings::all();

		$this->assertSame( 500, $all['batch_size'] );
		$this->assertSame( 9, $all['time_budget'] );
		$this->assertFalse( $all['delete_data_on_uninstall'] );
		$this->assertArrayNotHasKey( 'unexpected', $all );
	}
}
