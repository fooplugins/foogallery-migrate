<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageCopyCursor;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use PHPUnit\Framework\TestCase;

/** Query/failure contract only. NOT native SQL atomicity or public acceptance. */
class StorageCapacityCopyTransactionTest extends TestCase {
	private function setup_copy( $fault = '' ) {
		$h = new StorageCapacityConversionTest( 'unused' ); $scale = $h->scale( 2 );
		$value = array( 'tree' => array( array( 'id' => 1, 'children' => array( false, 'x', null, 7, array( 'id' => 2 ) ) ) ) );
		$codec = new LegacyStateCodec(); $hash = hash( 'sha256', serialize( $value ) );
		$r = array( 'version' => 4, 'blog_id' => 1, 'backend' => 'option', 'epoch' => 0, 'switch_id' => str_repeat( 'a', 32 ), 'from' => 'option', 'to' => 'table', 'phase' => 'copying', 'table_id' => str_repeat( 'a', 32 ), 'source_digest' => hash( 'sha256', 'present:' . $hash ), 'logical_digest' => $codec->snapshot_digest( $value ), 'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1, 'retained_digest' => null );
		$r['copy_cursor'] = StorageCopyCursor::initial( $r ); $db = new CapacityCopyDb(); $db->raw_hash = $hash; $db->control = json_encode( $r ); $db->fault = $fault;
		return array( $db, $value, $r, $scale );
	}
	private function batch( $db, $value, $r, $scale, $discard = false ) {
		return ( new LegacyTableSnapshot( $db, 1, 24576, 16777216, null, null, $scale ) )->conversion_batch( $r, $value, new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ), $discard );
	}
	public function test_row_boundary_cursor_and_head_are_committed_in_the_same_transaction_and_resume_exactly(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		for ( $i = 0; $i < 3; ++$i ) {
			$b = $this->batch( $db, $value, $r, $scale ); $r = $b['record'];
			$this->assertSame( ( $i + 1 ) * 2, $r['copy_cursor']['committed_rows'] );
			$this->assertSame( $r, StorageControl::decode( $db->control, 1 ) );
			$this->assertSame( $i === 2, $b['complete'] );
		}
		$this->assertCount( 6, $db->records ); $this->assertSame( 3, count( array_filter( $db->queries, function ( $q ) { return 'COMMIT' === $q; } ) ) );
		$this->assertTrue( $this->batch( $db, $value, $r, $scale )['complete'] ); $this->assertCount( 6, $db->records );
	}
	public function test_prefix_corruption_and_foreign_rows_refuse_before_any_new_insert(): void {
		foreach ( array( 'payload', 'foreign', 'source' ) as $fault ) {
			list( $db, $value, $r, $scale ) = $this->setup_copy(); $r = $this->batch( $db, $value, $r, $scale )['record'];
			if ( 'payload' === $fault ) { $db->records[0]['payload'][0] = 'X'; }
			elseif ( 'foreign' === $fault ) { $extra = $db->records[0]; $extra['node_hash'] = str_repeat( 'x', 32 ); $db->records[] = $extra; }
			else { $db->raw_hash = str_repeat( '0', 64 ); }
			$before = count( $db->records );
			try { $this->batch( $db, $value, $r, $scale ); $this->fail( 'Changed prefix/source admitted.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			$this->assertCount( $before, $db->records ); $this->assertSame( json_encode( $r ), $db->control );
		}
	}
	public function test_precommit_row_failure_rolls_back_rows_head_and_control_together(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy( 'row' );
		try { $this->batch( $db, $value, $r, $scale ); $this->fail( 'Injected row failure ignored.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $r ), $db->control ); $this->assertCount( 0, $db->records ); $this->assertCount( 0, $db->heads );
		$this->assertContains( 'ROLLBACK', $db->queries );
	}
	public function test_uncertain_commit_does_not_rollback_or_replay_and_fresh_prefix_reconciles(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy( 'commit' );
		$repo = new LegacyTableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		try { $repo->conversion_batch( $r, $value, new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ) ); $this->fail( 'Uncertain commit ignored.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'uncertain', $e->getMessage() ); }
		$this->assertNotContains( 'ROLLBACK', $db->queries ); $durable = StorageControl::decode( $db->control, 1 ); $this->assertSame( 2, $durable['copy_cursor']['committed_rows'] );
		try { $repo->conversion_batch( $durable, $value, new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ) ); $this->fail( 'Tainted repository reused.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'uncertain', $e->getMessage() ); }
		// A new spy stands for a fresh pin, not an authorized hard-death takeover.
		$fresh = clone $db; $fresh->fault = ''; $fresh->queries = array();
		$b = $this->batch( $fresh, $value, $durable, $scale ); $this->assertSame( 4, $b['record']['copy_cursor']['committed_rows'] ); $this->assertCount( 4, $fresh->records );
	}
	public function test_head_boundary_failure_rolls_back_new_rows_finalized_head_and_cursor_together(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		$r = $this->batch( $db, $value, $r, $scale )['record']; $r = $this->batch( $db, $value, $r, $scale )['record'];
		$heads = $db->heads; $db->fault = 'head';
		try { $this->batch( $db, $value, $r, $scale ); $this->fail( 'Head failure ignored.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( $heads, $db->heads ); $this->assertSame( json_encode( $r ), $db->control ); $this->assertCount( 4, $db->records );
	}
	public function test_owned_discard_proves_prefix_then_removes_only_that_scope_atomically(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy(); $r = $this->batch( $db, $value, $r, $scale )['record'];
		$this->assertNull( $this->batch( $db, $value, $r, $scale, true )['record'] );
		$this->assertNull( $db->control ); $this->assertCount( 0, $db->records ); $this->assertCount( 0, $db->heads );
	}
}

class CapacityCopyDb extends SnapshotTestWpdb {
	public $prefix = 'wp_'; public $heads = array(); public $records = array(); public $control; public $raw_hash;
	private $original = array(); private $prepared = array(); private $saved; private $option_args;
	public function prepare( $sql, $args ) { $q = parent::prepare( $sql, $args ); $this->prepared[$q] = $args; $this->original[$q] = $sql; if ( strpos( $sql, 'UPDATE `wp_options`' ) === 0 ) { $this->option_args = $args; } return $q; }
	public function query( $q ) {
		$this->queries[] = $q; $this->last_error = ''; $a = isset( $this->prepared[$q] ) ? $this->prepared[$q] : array();
		if ( 'START TRANSACTION' === $q ) { $this->saved = array( $this->heads, $this->records, $this->control ); return 0; }
		if ( 'ROLLBACK' === $q ) { list( $this->heads, $this->records, $this->control ) = $this->saved; return 0; }
		if ( 'COMMIT' === $q ) { return 'commit' === $this->fault ? false : 0; }
		if ( strpos( $q, 'INSERT INTO' ) === 0 ) {
			if ( false !== strpos( $q, 'records' ) && 'row' === $this->fault ) { return false; }
			preg_match( '/INSERT INTO `([^`]+)` \(([^)]+)\) VALUES \((.*)\)$/', $this->original[$q], $m );
			$cols = explode( ',', str_replace( '`', '', $m[2] ) ); $expressions = preg_split( '/,(?![^()]*\))/', $m[3] ); $i = 0; $row = array();
			foreach ( $cols as $n => $key ) {
				$expr = trim( $expressions[$n] ); if ( 'NULL' === $expr ) { $row[$key] = null; }
				else { $v = $a[$i++]; $row[$key] = strpos( $expr, 'UNHEX(' ) === 0 ? hex2bin( $v ) : $v; }
			}
			$this->insert_id = 10 + count( $this->heads ) + count( $this->records ); $row['id'] = $this->insert_id;
			if ( false !== strpos( $m[1], 'records' ) ) { $this->records[] = $row; } else { $this->heads[] = $row; } return 1;
		}
		if ( strpos( $q, 'UPDATE `wp_options`' ) === 0 ) { $this->control = $this->option_args[0]; return 1; }
		if ( strpos( $q, 'DELETE FROM `wp_options`' ) === 0 ) { $this->control = null; return 1; }
		if ( strpos( $q, 'DELETE FROM' ) === 0 ) { if ( false !== strpos( $q, 'records' ) ) { $n = count( $this->records ); $this->records = array(); return $n; } $this->heads = array(); return 1; }
		if ( strpos( $q, 'UPDATE' ) === 0 ) {
			if ( 'head' === $this->fault ) { return false; }
			$h = &$this->heads[0]; $h['state_kind'] = $a[0]; $h['record_count'] = $a[1]; $h['payload_bytes'] = $a[2]; $h['snapshot_digest'] = hex2bin( $a[3] );
			$h['active_generation'] = 1; $h['revision'] = 1; $h['digest_revision'] = 1; $h['digest_status'] = 'current'; return 1;
		}
		return 0;
	}
	public function get_var( $q ) {
		if ( strpos( $q, "SELECT CONCAT('v:'" ) === 0 ) { $a = $this->prepared[$q]; return $a[0] === \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION && null !== $this->control ? 'v:' . $this->control : null; }
		return parent::get_var( $q );
	}
	public function get_results( $q, $format ) {
		$this->last_error = ''; $a = isset( $this->prepared[$q] ) ? $this->prepared[$q] : array();
		if ( strpos( $q, 'SELECT SHA2(option_value' ) === 0 ) { return array( array( 'v' => $this->raw_hash ) ); }
		if ( false !== strpos( $q, 'FROM `wp_options`' ) ) { return strpos( $q, 'SELECT option_name' ) === 0 ? array() : array( array( 'v' => $this->control ) ); }
		if ( strpos( $q, 'SELECT id, state_order' ) === 0 ) {
			$out = array(); foreach ( $this->heads as $h ) {
				$r = array_intersect_key( $h, array_fill_keys( array( 'id', 'state_order', 'state_kind', 'active_generation', 'revision', 'codec_version', 'record_count', 'payload_bytes', 'digest_revision', 'digest_status' ), 1 ) );
				$r['key_hash'] = bin2hex( $h['state_key_hash'] ); $r['key_digest'] = hash( 'sha256', $h['state_key_value'] ); $r['root_digest'] = hash( 'sha256', $h['root_payload'] ); $r['logical_digest'] = null === $h['snapshot_digest'] ? null : bin2hex( $h['snapshot_digest'] ); $out[] = $r;
			} return $out;
		}
		if ( strpos( $q, 'SELECT COUNT(*) AS n' ) === 0 ) { return array( array( 'n' => (string) count( $this->records ) ) ); }
		if ( strpos( $q, 'SELECT COUNT(*) AS record_count' ) === 0 ) {
			$n = 0; $payload = 0; $bytes = 0;
			foreach ( $this->records as $row ) { if ( $row['state_id'] !== $a[1] ) { continue; } ++$n; $payload += $row['payload_bytes']; foreach ( array( 'payload', 'mutable_payload', 'key_value', 'legacy_identifier_value', 'source_plugin_value', 'source_id_value' ) as $key ) { $bytes += null === $row[$key] ? 0 : strlen( $row[$key] ); } }
			return array( array( 'record_count' => (string) $n, 'payload_bytes' => (string) $payload, 'encoded_bytes' => (string) $bytes ) );
		}
		if ( strpos( $q, 'SELECT * FROM' ) === 0 ) { return array_values( array_filter( $this->records, function ( $row ) use ( $a ) { return $row['state_id'] === $a[1] && bin2hex( $row['node_hash'] ) === $a[2]; } ) ); }
		return parent::get_results( $q, $format );
	}
}
