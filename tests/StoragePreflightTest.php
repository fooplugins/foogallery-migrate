<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

class StoragePreflightTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_7', 'value' => 'valid-nonce', 'result' => 1 );
		$GLOBALS['foogallery_migrate_test_option_reads'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'],
			$GLOBALS['foogallery_migrate_test_nonce'], $GLOBALS['foogallery_migrate_test_option_reads'], $GLOBALS['foogallery_migrate_test_option_writes'] );
		parent::tearDown();
	}

	private function request(): array {
		return array( 'blog_id' => '7', 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'valid-nonce' );
	}

	private function policy(): array {
		// Synthetic test policy, NOT a benchmarked production admission default.
		return array( 'max_raw_bytes' => 1048576, 'amplification' => 32, 'reserve_bytes' => 16777216 );
	}

	public function test_capability_is_required_before_any_sql_or_option_read(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$db = new PreflightTestWpdb();
		$this->assertError( 'foogallery_migrate_storage_forbidden', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() ) );
		$this->assertSame( array(), $db->reads );
		$this->assertNoOptionAccess();
	}

	public function test_nonce_is_purpose_and_current_site_specific(): void {
		foreach ( array( 'foogallery_migrate', 'foogallery_migrate_storage_option_import_8', '' ) as $action ) {
			$GLOBALS['foogallery_migrate_test_nonce']['action'] = $action;
			$db = new PreflightTestWpdb();
			$this->assertError( 'foogallery_migrate_storage_invalid_nonce', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() ) );
			$this->assertSame( array(), $db->reads );
		}
		$this->assertNoOptionAccess();
	}

	public function test_scope_target_confirmation_and_site_are_not_coerced_or_taken_from_request(): void {
		foreach ( array(
			array( 'blog_id', '8' ), array( 'blog_id', '07' ), array( 'blog_id', 7.0 ), array( 'blog_id', array( 7 ) ),
			array( 'scope', 'network' ), array( 'target', 'option' ), array( 'target', 'wp_foogallery_migrate_state' ),
			array( 'confirmation', true ), array( 'confirmation', '1' ), array( 'nonce', array( 'valid-nonce' ) )
		) as $change ) {
			$request = $this->request();
			$request[$change[0]] = $change[1];
			$db = new PreflightTestWpdb();
			$this->assertError( 'foogallery_migrate_storage_invalid_request', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $request ) );
			$this->assertSame( array(), $db->reads );
		}
		foreach ( array( array(), array_merge( $this->request(), array( 'table' => 'attacker_table' ) ) ) as $request ) {
			$this->assertError( 'foogallery_migrate_storage_invalid_request', ( new StoragePreflight( new PreflightTestWpdb(), $this->policy() ) )->option_import( $request ) );
		}
		$this->assertNoOptionAccess();
	}

	public function test_authorized_observation_uses_only_length_of_the_fixed_current_site_option(): void {
		$db = new PreflightTestWpdb();
		$result = ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() );
		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['blog_id'] );
		$this->assertSame( 128, $result['raw_bytes'] );
		$this->assertTrue( $result['decode_admitted'] );
		$this->assertFalse( $result['conversion_ready'] );
		$this->assertSame( array( "SELECT LENGTH(option_value) AS option_bytes FROM `wp_7_options` WHERE option_name = 'foogallery-migrate-data' LIMIT 1" ), $db->reads );
		$this->assertNoOptionAccess();
	}

	public function test_previous_nonce_tick_is_valid_but_false_is_not(): void {
		$GLOBALS['foogallery_migrate_test_nonce']['result'] = 2;
		$this->assertIsArray( ( new StoragePreflight( new PreflightTestWpdb(), $this->policy() ) )->option_import( $this->request() ) );
		$GLOBALS['foogallery_migrate_test_nonce']['result'] = false;
		$this->assertError( 'foogallery_migrate_storage_invalid_nonce', ( new StoragePreflight( new PreflightTestWpdb(), $this->policy() ) )->option_import( $this->request() ) );
	}

	public function test_sql_failure_and_invalid_size_fail_closed_without_fetching_payload(): void {
		foreach ( array( false, '-1', '1.5', '01', '999999999999999999999999999', array( 1 ) ) as $value ) {
			$db = new PreflightTestWpdb();
			$db->bytes = $value;
			$this->assertError( 'foogallery_migrate_storage_size_unavailable', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() ) );
		}
		$db = new PreflightTestWpdb();
		$db->fail = true;
		$this->assertError( 'foogallery_migrate_storage_size_unavailable', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() ) );
		$this->assertNoOptionAccess();
	}

	public function test_absent_option_has_zero_bytes_and_missing_or_mismatched_table_identifiers_are_refused(): void {
		$db = new PreflightTestWpdb();
		$db->bytes = null;
		$this->assertSame( 0, ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() )['raw_bytes'] );
		foreach ( array( 'wp_options', 'wp_7_options`; DROP TABLE posts', '' ) as $table ) {
			$db = new PreflightTestWpdb();
			$db->options = $table;
			$this->assertError( 'foogallery_migrate_storage_size_unavailable', ( new StoragePreflight( $db, $this->policy() ) )->option_import( $this->request() ) );
			$this->assertSame( array(), $db->reads );
		}
	}

	public function test_policy_is_required_and_not_inferred_from_a_byte_count_or_unlimited_php(): void {
		$this->assertError( 'foogallery_migrate_storage_decode_policy_required', StoragePreflight::decode_budget( 128, '-1', 0, null ) );
		foreach ( array( array(), array( 'max_raw_bytes' => 1000, 'amplification' => 0, 'reserve_bytes' => 10 ),
			array( 'max_raw_bytes' => 1000, 'amplification' => 2, 'reserve_bytes' => 0 ),
			array( 'max_raw_bytes' => 1000, 'amplification' => '32', 'reserve_bytes' => 10 ) ) as $policy ) {
			$this->assertError( 'foogallery_migrate_storage_decode_policy_required', StoragePreflight::decode_budget( 128, '128M', 0, $policy ) );
		}
		$this->assertError( 'foogallery_migrate_storage_decode_policy_required', ( new StoragePreflight( new PreflightTestWpdb() ) )->option_import( $this->request() ) );
		$this->assertNoOptionAccess();
	}

	public function test_budget_enforces_raw_ceiling_effective_headroom_and_reserve_even_for_unlimited_php(): void {
		$policy = array( 'max_raw_bytes' => 1000, 'amplification' => 4, 'reserve_bytes' => 100 );
		$this->assertSame( 400, StoragePreflight::decode_budget( 100, '1000', 500, $policy )['estimated_decode_bytes'] );
		$this->assertError( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( 101, '1000', 500, $policy ) );
		$this->assertError( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( 1001, '-1', 0, $policy ) );
		$this->assertError( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( 0, '1000', 901, $policy ) );
		$this->assertIsArray( StoragePreflight::decode_budget( 1000, '-1', 0, $policy ) );
		$this->assertError( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( PHP_INT_MAX, '-1', 0,
			array( 'max_raw_bytes' => PHP_INT_MAX, 'amplification' => 2, 'reserve_bytes' => 1 ) ) );
	}

	public function test_memory_limit_units_and_invalid_values_are_handled_without_overflow(): void {
		$policy = array( 'max_raw_bytes' => 1, 'amplification' => 1, 'reserve_bytes' => 1 );
		foreach ( array( '1K' => 1024, '1m' => 1048576, '1G' => 1073741824, ' 1024 ' => 1024 ) as $limit => $bytes ) {
			$this->assertSame( $bytes, StoragePreflight::decode_budget( 1, $limit, 0, $policy )['memory_limit_bytes'] );
		}
		foreach ( array( '', '0', '-2', '128MB', '1.5G', 'nonsense', '999999999999999999999999G', false ) as $limit ) {
			$this->assertError( 'foogallery_migrate_storage_memory_unknown', StoragePreflight::decode_budget( 1, $limit, 0, $policy ) );
		}
		$this->assertError( 'foogallery_migrate_storage_memory_unknown', StoragePreflight::decode_budget( 1, '128M', -1, $policy ) );
	}

	private function assertNoOptionAccess(): void {
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_reads'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	private function assertError( string $code, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}

class PreflightTestWpdb {
	public $prefix = 'wp_7_';
	public $options = 'wp_7_options';
	public $last_error = '';
	public $bytes = '128';
	public $fail = false;
	public $reads = array();

	public function prepare( $query, $value ) {
		return str_replace( '%s', "'" . $value . "'", $query );
	}

	public function get_var( $query ) {
		$this->reads[] = $query;
		$this->last_error = $this->fail ? 'SQL failure' : '';
		return $this->bytes;
	}
}
