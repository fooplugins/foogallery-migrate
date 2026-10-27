<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Cleared/empty authoritative tables permit lazy discovery without option fallback. */
class StorageEmptyCollectionContractTest extends TestCase {
	private $old_options;
	protected function setUp(): void { $this->old_options = $GLOBALS['foogallery_migrate_test_options']; }
	protected function tearDown(): void { $GLOBALS['foogallery_migrate_test_options'] = $this->old_options; }
	/** @dataProvider absentCollections */
	public function test_missing_collection_returns_its_lazy_default_without_writes_or_option_fallback( $key, $scope ): void {
		$db = new EmptyCollectionConnection(); $db->scope = $scope;
		$GLOBALS['foogallery_migrate_test_options'] = array( FOOGALLERY_MIGRATE_OPTION_DATA => array( $key => 'stale option data' ) );
		$before = serialize( $GLOBALS['foogallery_migrate_test_options'] );
		$settings = new MigratorSettings( new LegacyTableBackend( $db, 7 ) );
		$this->assertSame( 'lazy discovery', $settings->get_migrator_setting( $key, 'lazy discovery' ) );
		$this->assertSame( array( 'found' => false, 'value' => null ), ( new LegacyTableBackend( $db, 7 ) )->read_collection( $key ) );
		$this->assertSame( $before, serialize( $GLOBALS['foogallery_migrate_test_options'] ) );
		$this->assertSame( 0, $db->writes );
		$this->assertSame( $scope ? 0 : 2, $db->record_checks );
	}
	public static function absentCollections(): array {
		$out = array(); foreach ( array( 'plugins', 'galleries', 'albums' ) as $key ) {
			$out[] = array( $key, false ); $out[] = array( $key, true );
		} return $out;
	}
	public function test_empty_scope_with_orphan_records_refuses_instead_of_becoming_an_empty_collection(): void {
		$db = new EmptyCollectionConnection(); $db->mode = 'orphan';
		try { ( new LegacyTableBackend( $db, 7 ) )->read_collection( 'plugins' ); $this->fail( 'Orphan records must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Orphan', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
	/** @dataProvider failedReadStages */
	public function test_failed_sql_never_reports_an_empty_collection( $mode ): void {
		$db = new EmptyCollectionConnection(); $db->mode = $mode;
		try { ( new LegacyTableBackend( $db, 7 ) )->read_collection( 'plugins' ); $this->fail( 'Failed read must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'read', strtolower( $error->getMessage() ) ); }
		$this->assertSame( 0, $db->writes );
	}
	public static function failedReadStages(): array { return array( array( 'sql-head' ), array( 'sql-scope' ), array( 'sql-records' ) ); }
	public function test_concurrently_created_head_refuses_the_absent_result(): void {
		$db = new EmptyCollectionConnection(); $db->mode = 'changed';
		try { ( new LegacyTableBackend( $db, 7 ) )->read_collection( 'plugins' ); $this->fail( 'Changed head must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
}

class EmptyCollectionConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_'; public $prefix = 'wp_'; public $last_error = '';
	public $mode = ''; public $scope = false; public $writes = 0; public $record_checks = 0;
	private $head_calls = 0;
	public function __construct() {}
	public function prepare( $sql, $args ) {
		if ( false !== strpos( $sql, '%i' ) ) {
			if ( $args !== array( 'wp_foogallery_migrate_records', 7 ) ) { throw new \RuntimeException( 'Wrong record table/site predicate.' ); }
			return str_replace( '%i', '`wp_foogallery_migrate_records`', $sql );
		}
		if ( false !== strpos( $sql, 'SELECT id FROM' ) && $args !== array( 7 ) ) { throw new \RuntimeException( 'Wrong site predicate.' ); }
		return $sql;
	}
	public function get_results( $sql, $format ) {
		$this->last_error = ''; $stage = '';
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) { $stage = 'head'; }
		elseif ( false !== strpos( $sql, 'SELECT id FROM `wp_foogallery_migrate_state` WHERE blog_id = %d LIMIT 1' ) ) { $stage = 'scope'; }
		elseif ( false !== strpos( $sql, 'SELECT id FROM `wp_foogallery_migrate_records` WHERE blog_id = %d LIMIT 1' ) ) { $stage = 'records'; ++$this->record_checks; }
		else { throw new \RuntimeException( 'Unexpected empty-collection SQL.' ); }
		if ( 'sql-' . $stage === $this->mode ) { $this->last_error = 'Simulated read failure.'; return null; }
		if ( 'head' === $stage && ++$this->head_calls > 1 && 'changed' === $this->mode ) {
			return array( array( 'id' => '1', 'blog_id' => '7', 'state_order' => '0', 'state_kind' => 'map',
				'active_generation' => '1', 'revision' => '1', 'codec_version' => '1', 'record_count' => '0',
				'payload_bytes' => '0', 'root_payload_bytes' => '0', 'state_key_value' => ( new LegacyStateCodec() )->key_value( 'plugins' ) ) );
		}
		return ( 'scope' === $stage && $this->scope || 'records' === $stage && 'orphan' === $this->mode ) ? array( array( 'id' => '1' ) ) : array();
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Read-only collection attempted a write.' ); }
}
