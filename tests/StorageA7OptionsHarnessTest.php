<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/** Executable host seams only. Synthetic receipts are never native acceptance. */
class StorageA7OptionsHarnessTest extends TestCase {
	private function setup_harness() {
		require_once __DIR__ . '/support/StorageA7OptionsFixture.php';
		require_once __DIR__ . '/integration/storage-a7-options-harness.php';
		$manifest = StorageA7OptionsFixture::manifest();
		return array( 'format' => 'a7-options-seed-v1', 'corpus' => $manifest['format'], 'stage' => 'seed', 'owner' => str_repeat( 'a', 32 ), 'blog_id' => 1,
			'status' => 'measurement_only', 'contract_decision' => 'pending', 'method_approved' => false, 'profile_approved' => false,
			'outcome' => 'SUCCESS', 'first_failure' => null, 'fixture' => $manifest['fixture'],
			'persisted_sql' => array( 'bytes' => $manifest['fixture']['raw_bytes'], 'sha256' => $manifest['fixture']['sha256'] ),
			'wp_logical_sha256' => $manifest['fixture']['logical_sha256'] );
	}
	private function run_stage( $stage, $seed, $sql = null, $wp = null, $result = null, &$calls = array() ) {
		$sql = null === $sql ? $seed['persisted_sql'] : $sql;
		$wp = null === $wp ? StorageA7OptionsFixture::value() : $wp;
		$result = null === $result ? ( 'backup' === $stage ? $seed['persisted_sql'] : array( 'blog_id' => 1, 'backend' => 'reverse' === $stage ? 'option' : 'table', 'epoch' => 'reverse' === $stage ? 2 : 1, 'phase' => 'stable' ) ) : $result;
		return StorageA7OptionsHarness::run( $stage, $seed, str_repeat( 'a', 32 ), 1,
			function ( $name ) use ( $sql, &$calls ) { $calls[] = 'sql:' . $name; return $sql; },
			function ( $name ) use ( $wp, &$calls ) { $calls[] = 'wp:' . $name; return $wp; },
			function () use ( $result, &$calls ) { $calls[] = 'downstream'; return $result; } );
	}
	/** @dataProvider stages */
	public function test_success_requires_receipt_then_fresh_sql_and_wp_before_downstream( $stage ): void {
		$seed = $this->setup_harness(); $calls = array(); $result = $this->run_stage( $stage, $seed, null, null, null, $calls );
		$name = 'reverse' === $stage ? 'foogallery-migrate-data-storage-backup' : 'foogallery-migrate-data';
		$this->assertSame( array( 'sql:' . $name, 'wp:' . $name, 'downstream' ), $calls );
		$this->assertNotEmpty( $result );
	}
	public static function stages(): array { return array( array( 'forward' ), array( 'backup' ), array( 'reverse' ) ); }

	/** @dataProvider bad_receipts */
	public function test_missing_error_or_mutated_receipt_blocks_all_reads_and_downstream( $field, $value ): void {
		$seed = $this->setup_harness(); if ( 'missing' === $field ) { $seed = null; } else { $seed[$field] = $value; }
		$calls = array();
		try { $this->run_stage( 'forward', $seed, array(), array(), array(), $calls ); $this->fail( 'Bad receipt admitted.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Seed receipt not SUCCESS/bound/exact.', $error->getMessage() ); }
		$this->assertSame( array(), $calls );
	}
	public static function bad_receipts(): array {
		return array( array( 'missing', null ), array( 'outcome', 'FAILURE' ), array( 'outcome', 'SQL_SUCCESS' ), array( 'first_failure', 'write failed' ),
			array( 'owner', str_repeat( 'b', 32 ) ), array( 'blog_id', 2 ), array( 'corpus', 'a7-compact-v1' ), array( 'stage', 'forward' ),
			array( 'method_approved', true ), array( 'profile_approved', true ), array( 'status', 'approved' ), array( 'contract_decision', 'approved' ),
			array( 'fixture', array() ), array( 'persisted_sql', array( 'bytes' => 2998293, 'sha256' => str_repeat( '0', 64 ) ) ), array( 'wp_logical_sha256', str_repeat( '0', 64 ) ) );
	}
	/** @dataProvider bad_sql */
	public function test_fresh_sql_failure_or_mismatch_blocks_wp_and_downstream( $sql ): void {
		$seed = $this->setup_harness(); $calls = array();
		try { $this->run_stage( 'forward', $seed, $sql, array(), array(), $calls ); $this->fail( 'Bad SQL admitted.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Fresh persisted SQL binary digest mismatch.', $error->getMessage() ); }
		$this->assertSame( array( 'sql:foogallery-migrate-data' ), $calls );
	}
	public static function bad_sql(): array {
		return array( array( false ), array( array() ), array( array( 'bytes' => 0, 'sha256' => hash( 'sha256', '' ) ) ),
			array( array( 'bytes' => 2998293, 'sha256' => '3b3befd74a0decec51090dcf3d48c5d71e1dfd5ced486ec86d4e9ee098ef7cf8' ) ),
			array( array( 'bytes' => 2998292, 'sha256' => '60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297' ) ) );
	}
	/** @dataProvider bad_wp */
	public function test_missing_empty_original_or_mutated_wp_readback_blocks_downstream( $kind ): void {
		$seed = $this->setup_harness(); $wp = StorageA7OptionsFixture::value();
		if ( 'missing' === $kind ) { $wp = false; } elseif ( 'empty' === $kind ) { $wp = array(); }
		elseif ( 'original' === $kind ) { $wp = StorageA7Fixture::value( 20000 ); }
		else { $wp['galleries']['items']['gallery-009']['ID'] = 9; }
		$calls = array();
		try { $this->run_stage( 'forward', $seed, null, $wp, null, $calls ); $this->fail( 'Bad WP readback admitted.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Fresh WordPress logical readback mismatch.', $error->getMessage() ); }
		$this->assertCount( 2, $calls ); $this->assertNotContains( 'downstream', $calls );
	}
	public static function bad_wp(): array { return array( array( 'missing' ), array( 'empty' ), array( 'original' ), array( 'id-type' ) ); }

	/** @dataProvider stages */
	public function test_empty_conversion_or_backup_never_accepted( $stage ): void {
		$seed = $this->setup_harness(); $this->expectExceptionMessage( 'Downstream result empty or mismatched.' );
		$this->run_stage( $stage, $seed, null, null, array() );
	}
	public function test_unknown_stage_refused_before_callbacks(): void {
		$seed = $this->setup_harness(); $calls = array();
		try { $this->run_stage( 'convert-anything', $seed, null, null, null, $calls ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Unknown positive-storage stage.', $error->getMessage() ); }
		$this->assertSame( array(), $calls );
	}
	public function test_reader_exception_blocks_downstream(): void {
		$seed = $this->setup_harness(); $calls = array();
		try { StorageA7OptionsHarness::run( 'forward', $seed, str_repeat( 'a', 32 ), 1,
			function () { throw new \RuntimeException( 'SQL read failed' ); }, function () { $this->fail( 'WP called' ); }, function () { $this->fail( 'Downstream called' ); } ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'SQL read failed', $error->getMessage() ); }
	}
	public function test_wordpress_adapter_queries_binary_digest_and_evicts_caches(): void {
		$seed = $this->setup_harness(); $db = new A7OptionsHostDatabase(); $db->row = array( 'bytes' => '2998293', 'sha256' => $seed['fixture']['sha256'] );
		$GLOBALS['foogallery_migrate_test_options']['foogallery-migrate-data'] = StorageA7OptionsFixture::value();
		$GLOBALS['foogallery_migrate_test_cache_deletes'] = array(); $GLOBALS['foogallery_migrate_test_option_reads'] = array();
		list( $sql, $wp ) = StorageA7OptionsHarness::wordpress_readers( $db );
		$this->assertSame( $seed['persisted_sql'], $sql( 'foogallery-migrate-data' ) );
		$this->assertStringContainsString( 'SHA2(CAST(option_value AS BINARY),256)', $db->query );
		$this->assertStringContainsString( 'OCTET_LENGTH(option_value)', $db->query );
		$this->assertSame( serialize( StorageA7OptionsFixture::value() ), serialize( $wp( 'foogallery-migrate-data' ) ) );
		$this->assertSame( array( array( 'foogallery-migrate-data', 'options' ), array( 'alloptions', 'options' ), array( 'notoptions', 'options' ) ), $GLOBALS['foogallery_migrate_test_cache_deletes'] );
		$this->assertSame( array( 'foogallery-migrate-data' ), $GLOBALS['foogallery_migrate_test_option_reads'] );
		unset( $GLOBALS['foogallery_migrate_test_options']['foogallery-migrate-data'], $GLOBALS['foogallery_migrate_test_option_reads'] );
		$db->last_error = 'failed'; $this->expectExceptionMessage( 'SQL binary readback failed.' ); $sql( 'foogallery-migrate-data' );
	}
}

/** No connection method: this owned seam cannot contact SQL. */
class A7OptionsHostDatabase {
	public $options = 'wp_options'; public $last_error = ''; public $row; public $query;
	public function prepare( $query, $name ) { return str_replace( '%s', "'" . $name . "'", $query ); }
	public function get_row( $query, $mode ) { $this->query = $query; return $this->row; }
}
