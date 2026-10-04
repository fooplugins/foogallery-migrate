<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use PHPUnit\Framework\TestCase;

class LegacyTableSnapshotTest extends TestCase {
	private function change( $value = false ) {
		return array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => $value, 'expected_revision' => null );
	}

	public function test_construction_is_storage_neutral(): void {
		$db = new SnapshotTestWpdb();
		new LegacyTableSnapshot( $db, 7 );
		$this->assertSame( array(), $db->queries );
	}

	public function test_raw_replaying_connection_is_not_admitted(): void {
		$this->expectException( \RuntimeException::class );
		new LegacyTableSnapshot( new \stdClass(), 7 );
	}

	public function test_read_key_byte_budget_is_checked_before_sql(): void {
		$db = new SnapshotTestWpdb();
		try { ( new LegacyTableSnapshot( $db, 7, 100, 10 ) )->read_bounded( str_repeat( 'x', 100 ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
	}

	public function test_dirty_maintenance_validates_admission_before_sql(): void {
		$db = new SnapshotTestWpdb(); $repo = new LegacyTableSnapshot( $db, 7 );
		foreach ( array( array( 'migrated', 0, 10 ), array( true, 1, 10 ), array( 'migrated', 1, 0 ) ) as $args ) {
			try { call_user_func_array( array( $repo, 'verify_digest' ), $args ); $this->fail( 'Expected maintenance refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
	}

	/** @dataProvider invalidChanges */
	public function test_preflight_refuses_before_sql( $changes ): void {
		$db = new SnapshotTestWpdb();
		try { ( new LegacyTableSnapshot( $db, 7 ) )->replace_many( $changes ); $this->fail( 'Expected preflight refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
	}

	public static function invalidChanges(): array {
		$valid = array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => false, 'expected_revision' => null );
		$cases = array( array(), array( $valid, $valid ) );
		foreach ( array( 'kind' => 'invented', 'order' => -1, 'expected_revision' => 0, 'value' => new \stdClass(), 'key' => true ) as $key => $value ) {
			$bad = $valid; $bad[$key] = $value; $cases[] = array( $bad );
		}
		$bad = $valid; unset( $bad['value'] ); $cases[] = array( $bad );
		$bad = $valid; $bad['kind'] = 'map'; $bad['value'] = false; $cases[] = array( $bad );
		$bad = $valid; $bad['kind'] = 'list'; $bad['value'] = array( 9 => null ); $cases[] = array( $bad );
		return array_map( function ( $case ) { return array( $case ); }, $cases );
	}

	public function test_aggregate_row_and_byte_limits_precede_sql(): void {
		foreach ( array( new LegacyTableSnapshot( $db = new SnapshotTestWpdb(), 7, 1, 10000 ),
			new LegacyTableSnapshot( $db2 = new SnapshotTestWpdb(), 7, 100, 10 ) ) as $repo ) {
			$change = $this->change( array( 'children' => array( false ) ) );
			$change['kind'] = 'map'; $change['value'] = array( 'root' => $change['value'] );
			try { $repo->replace_many( array( $change ) ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		}
		$this->assertSame( array(), $db->queries ); $this->assertSame( array(), $db2->queries );
	}

	/** @dataProvider transactionFaults */
	public function test_failures_rollback_without_commit_or_authority_writes( $fault ): void {
		$db = new SnapshotTestWpdb(); $db->fault = $fault;
		$change = $this->change();
		if ( in_array( $fault, array( 'stale', 'collision', 'overflow', 'cas', 'flip', 'state-kind' ), true ) ) {
			$change['expected_revision'] = 3;
			$db->head = array( 'id' => '4', 'state_key_value' => ( new LegacyStateCodec() )->key_value( 'checkpoint' ),
				'active_generation' => '2', 'revision' => '3', 'codec_version' => '1', 'state_order' => '0', 'state_kind' => 'scalar' );
		}
		try { ( new LegacyTableSnapshot( $db, 7 ) )->replace_many( array( $change ) ); $this->fail( 'Expected transaction refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertContains( 'START TRANSACTION', $db->queries );
			$this->assertSame( 'ROLLBACK', end( $db->queries ) );
			$this->assertNotContains( 'COMMIT', $db->queries );
			$this->assertStringNotContainsString( 'options', implode( '\n', $db->queries ) );
		}
	}

	public static function transactionFaults(): array {
		return array_map( function ( $fault ) { return array( $fault ); }, array( 'stale', 'collision', 'overflow', 'insert', 'verification', 'cas', 'flip', 'state-kind' ) );
	}

	public function test_commit_failure_taints_repository_and_reports_uncertain_outcome(): void {
		$db = new SnapshotTestWpdb(); $db->fault = 'commit';
		$repo = new LegacyTableSnapshot( $db, 7 );
		try { $repo->replace_many( array( $this->change() ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'uncertain', $error->getMessage() ); }
		$count = count( $db->queries );
		try { $repo->replace_many( array( $this->change() ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( $count, count( $db->queries ) ); }
	}

	public function test_schema_failure_does_not_begin_transaction(): void {
		$db = new SnapshotTestWpdb(); $db->fault = 'schema';
		try { ( new LegacyTableSnapshot( $db, 7 ) )->replace_many( array( $this->change() ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertNotContains( 'START TRANSACTION', $db->queries ); }
	}

	public function test_ambient_transaction_is_refused_without_begin_commit_or_rollback(): void {
		$db = new SnapshotTestWpdb(); $db->fault = 'ambient';
		try { ( new LegacyTableSnapshot( $db, 7 ) )->replace_many( array( $this->change() ) ); $this->fail(); }
		catch ( \RuntimeException $error ) {
			$this->assertContains( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $db->queries );
			foreach ( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ) as $sql ) { $this->assertNotContains( $sql, $db->queries ); }
		}
	}
}

/** Query/failure contract spy only. Actual atomicity is proved by real DB harness. */
class SnapshotTestWpdb extends LegacyTransactionConnection {
	public function __construct() {}
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 4;
	public $queries = array();
	public $fault = '';
	public $head;
	public function prepare( $sql, $args ) {
		$args = is_array( $args ) ? $args : array( $args );
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[ds]/', function ( $match ) use ( $arg ) { return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( $arg ) . "'"; }, $sql, 1 );
		}
		return $sql;
	}
	public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 'ambient' === $this->fault && 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $sql ) { $this->last_error = 'fixture error'; return false; }
		if ( 'START TRANSACTION' === $sql || 'ROLLBACK' === $sql ) { return 0; }
		if ( 'COMMIT' === $sql ) { return 'commit' === $this->fault ? false : 0; }
		if ( false !== strpos( $sql, 'INSERT INTO' ) ) {
			if ( 'insert' === $this->fault ) { return false; }
			return 1;
		}
		if ( false !== strpos( $sql, 'UPDATE' ) ) {
			if ( 'flip' === $this->fault ) { return false; }
			if ( 'cas' === $this->fault ) { return 0; }
			return 1;
		}
		return 0;
	}
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql;
		if ( 'SELECT @@SESSION.autocommit AS autocommit' === $sql ) { return array( array( 'autocommit' => '1' ) ); }
		$schema = ( new LegacyStorageSchema( $this ) )->definitions();
		foreach ( $schema as $table => $definition ) {
			if ( false !== strpos( $sql, 'SHOW TABLE STATUS' ) && false !== strpos( stripslashes( $sql ), $this->esc_like( $table ) ) ) {
				return 'schema' === $this->fault ? array() : array( array( 'Name' => $table, 'Engine' => 'InnoDB' ) );
			}
			if ( 'SHOW COLUMNS FROM `' . $table . '`' === $sql ) {
				$rows = array();
				foreach ( $definition['columns'] as $name => $spec ) { $rows[] = array( 'Field' => $name, 'Type' => $spec[0], 'Null' => $spec[1] ? 'YES' : 'NO', 'Extra' => ! empty( $spec[2] ) ? 'auto_increment' : '' ); }
				return $rows;
			}
			if ( 'SHOW INDEX FROM `' . $table . '`' === $sql ) {
				$rows = array();
				foreach ( $definition['indexes'] as $name => $spec ) {
					foreach ( $spec[1] as $position => $column ) { $rows[] = array( 'Key_name' => $name, 'Non_unique' => $spec[0] ? '0' : '1', 'Seq_in_index' => $position + 1, 'Column_name' => $column, 'Sub_part' => null, 'Index_type' => 'BTREE' ); }
				}
				return $rows;
			}
		}
		if ( false !== strpos( $sql, 'FOR UPDATE' ) ) {
			if ( null === $this->head ) { return array(); }
			$head = $this->head;
			if ( 'stale' === $this->fault ) { $head['revision'] = '4'; }
			if ( 'collision' === $this->fault ) { $head['state_key_value'] = 'corrupt'; }
			if ( 'overflow' === $this->fault ) { $head['active_generation'] = (string) PHP_INT_MAX; }
			if ( 'state-kind' === $this->fault ) { $head['state_kind'] = 'staging'; }
			return array( $head );
		}
		if ( false !== strpos( $sql, 'AS record_count' ) ) { return array( array( 'record_count' => '0', 'payload_bytes' => '0', 'encoded_bytes' => '0' ) ); }
		if ( false !== strpos( $sql, 'root_payload' ) ) {
			$root = ( new LegacyStateCodec() )->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => false ) );
			return array( array( 'root_payload' => 'verification' === $this->fault ? 'corrupt' : $root ) );
		}
		return array();
	}
}
