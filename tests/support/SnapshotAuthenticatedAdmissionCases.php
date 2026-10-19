<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacySnapshotRowReader;
use FooPlugins\FooGalleryMigrate\SnapshotReadPlan;
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
use FooPlugins\FooGalleryMigrate\SnapshotPageWitness;

/** New coverage lives in the original focused class/discovery, not a new filter. */
trait SnapshotAuthenticatedAdmissionCases {
	private function authenticated_snapshot( $db, $memory = null ) {
		$s = $this->snapshot( $db, $memory ); $this->property( $s, 'snapshot_authentication_enabled', true, true ); return $s;
	}
	private function authenticated_rows( $db ) {
		return array_map( function ( $r ) { return is_array( $r ) ? $r : iterator_to_array( $r ); }, $db->records );
	}
	public function test_page_witness_rejects_same_byte_token_inflation_before_native_decode(): void {
		$db = new SnapshotScaleConnection(); $s = $this->authenticated_snapshot( $db ); $s->replace_many( $this->change() );
		$rows = $this->authenticated_rows( $db ); $codec = new LegacyStateCodec(); $before = $rows[0];
		$base = $codec->decode_record_payload( $before['payload'] ); $base['values']['unknown'] = array_fill( 0, 8, null );
		$new = $codec->encode_record_payload( $base ); $this->assertLessThanOrEqual( strlen( $before['payload'] ), strlen( $new ) );
		$new .= str_repeat( ' ', strlen( $before['payload'] ) - strlen( $new ) );
		$old_scan = $codec->inspect_json_record_payload( $before['payload'] ); $new_scan = $codec->inspect_json_record_payload( $new );
		$this->assertSame( 733, strlen( $new ) ); $this->assertSame( 70, $old_scan['tokens'] ); $this->assertSame( 111, $new_scan['tokens'] );
		$rows[0]['payload'] = $new; $rows[0]['payload_hash'] = hash( 'sha256', $new );
		$this->assertSame( SnapshotScaleConnection::bytes( $before ), SnapshotScaleConnection::bytes( $rows[0] ) ); $db->records = $rows;
		// Spy on the actual record codec's native decoder entries, not on a late
		// digest or observer heuristic. Root metadata has its separate codec.
		$spy = new AuthenticatedNativeDecodeSpy(); $records = $this->property( $s, 'records' ); $this->property( $records, 'codec', $spy, true );
		try { $s->read_bounded( 'bucket' ); $this->fail( 'Unauthenticated page entered decode.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'witness refused before decode', $e->getMessage() ); }
		$this->assertSame( 0, $spy->entries ); $this->assertSame( 0, $this->property( $this->property( $s, 'snapshot_owner' ), 'events' ) );
	}
	public function test_page_witness_binds_all_consumed_columns_and_scope_before_decode(): void {
		$db = new SnapshotScaleConnection(); $s = $this->authenticated_snapshot( $db ); $s->replace_many( $this->change() );
		$rows = $this->authenticated_rows( $db ); $original = $rows[0];
		$witnesses = $this->property( $s, 'snapshot_witnesses' ); $witness = reset( $witnesses );
		$definitions = $this->property( $s, 'definitions' ); $table = $this->property( $s, 'record_table' );
		$mutations = array();
		foreach ( $definitions[$table]['columns'] as $field => $spec ) {
			$r = $original;
			if ( null === $r[$field] ) { $r[$field] = 'binary(32)' === $spec[0] ? str_repeat( '0', 64 ) : ( false !== strpos( $spec[0], 'int' ) ? 0 : '' ); }
			else if ( false !== strpos( $spec[0], 'int' ) ) { ++$r[$field]; }
			else if ( 'binary(32)' === $spec[0] ) { $r[$field] = ( $r[$field][0] === '0' ? '1' : '0' ) . substr( $r[$field], 1 ); }
			else { $r[$field] .= 'x'; }
			if ( $field === 'payload' ) { $r['payload_hash'] = hash( 'sha256', $r['payload'] ); }
			if ( $field === 'mutable_payload' ) { $r['mutable_payload_hash'] = hash( 'sha256', $r['mutable_payload'] ); }
			$mutations[$field] = array( $r );
		}
		$missing = $original; unset( $missing['payload_hash'] ); $mutations['missing-column'] = array( $missing );
		$mutations['missing-row'] = array(); $mutations['duplicate-row'] = array( $original, $original );
		foreach ( $mutations as $name => $raw ) {
			$transport = new AuthenticatedPageTransport( $original, $raw ); $spy = new AuthenticatedNativeDecodeSpy();
			$source = new LegacySnapshotRowReader( $transport, 'unused', 'all', 'bytes', array( 7, $original['state_id'], $original['generation'] ), 1048576, function () {}, $witness );
			try { $page = $source->page( null, 0, 0 ); foreach ( $page as $r ) { $spy->decode_record_payload( $r['payload'] ); } $this->fail( 'Changed raw page accepted: ' . $name ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage(), $name ); }
			$this->assertSame( 0, $spy->entries, $name );
		}
		// Native drivers may return unsigned integers as canonical decimal strings
		// and HEX in upper case. Those exact canonical representations agree.
		$driver = $original; foreach ( $definitions[$table]['columns'] as $f => $spec ) {
			if ( null !== $driver[$f] && false !== strpos( $spec[0], 'int' ) ) { $driver[$f] = (string) $driver[$f]; }
			if ( null !== $driver[$f] && $spec[0] === 'binary(32)' ) { $driver[$f] = strtoupper( $driver[$f] ); }
		}
		$witness->authenticate( array( $driver ) ); $this->addToAssertionCount( 1 );
	}
	public function test_missing_stale_wrong_reader_witness_preserves_legacy_fallback(): void {
		$db = new SnapshotScaleConnection(); $samples = array();
		$s = $this->authenticated_snapshot( $db, function ( $bytes ) use ( &$samples ) { $samples[] = $bytes; return true; } );
		$s->replace_many( $this->change() ); $samples = array(); $s->read_bounded( 'bucket' ); $baseline = $samples;
		$witnesses = $this->property( $s, 'snapshot_witnesses' ); $plans = $this->property( $s, 'snapshot_plans' ); $hash = key( $plans );
		foreach ( array( 'missing', 'stale', 'unsupported-profile' ) as $fault ) {
			$this->property( $s, 'snapshot_witnesses', $fault === 'missing' ? array() : $witnesses, true ); $p = $plans;
			if ( $fault === 'stale' ) { ++$p[$hash]['binding']['revision']; $p[$hash]['complete']['features']['logical_depth']++; }
			if ( $fault === 'unsupported-profile' ) { $this->property( $s, 'snapshot_read_profile', new \stdClass(), true ); }
			$this->property( $s, 'snapshot_plans', $p, true ); $samples = array();
			$this->assertSame( $this->value(), $s->read_bounded( 'bucket' ) ); $this->assertSame( $baseline, $samples );
			$this->assertNull( $this->property( $s, 'snapshot_read_phase' ) );
		}
		$fresh = $this->authenticated_snapshot( $db ); $this->assertSame( $this->value(), $fresh->read_bounded( 'bucket' ) ); $this->assertNull( $this->property( $fresh, 'snapshot_owner' ) );
		$w = reset( $witnesses ); $r = $this->authenticated_rows( $db )[0];
		try { new LegacySnapshotRowReader( new AuthenticatedPageTransport( $r, array( $r ) ), 'unused', 'all', 'bytes', array( 8, $r['state_id'], $r['generation'] ), 1048576, function () {}, $w ); $this->fail( 'Wrong reader scope accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'Wrong producer witness scope', $e->getMessage() ); }
		try { $copy = clone $w; $this->fail( 'Cloned authority.' ); } catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'witness', $e->getMessage() ); }
	}
	public function test_full_digest_children_wrapper_cow_and_retained_reads_keep_private(): void {
		$db = new SnapshotScaleConnection(); $s = $this->authenticated_snapshot( $db ); $s->replace_many( $this->change() );
		$plans = $this->property( $s, 'snapshot_plans' ); $p = reset( $plans ); $full = $p['complete'];
		$this->assertGreaterThanOrEqual( 7, $full['features']['logical_depth'] );
		$this->assertGreaterThan( $p['descriptor']['private'], $full['full']['private'] );
		$this->assertSame( array_sum( $full['parts'] ), $full['full']['private'] );
		$this->assertSame( 2 * $full['features']['containers'], $full['parts']['retained_two_outputs_cow'] );
		$this->assertGreaterThanOrEqual( SnapshotPageWitness::workspace( 5 ), $full['parts']['witness_construction_lifetime_lookup'] );
		$first = $s->read_bounded( 'bucket' ); $one = $this->property( $s, 'snapshot_owner' );
		$second = $s->read_bounded( 'bucket' ); $two = $this->property( $s, 'snapshot_owner' );
		$this->assertSame( $this->value(), $first ); $this->assertSame( $first, $second ); $this->assertNotSame( $one, $two );
		$this->assertSame( 0, $one->remaining_own_output() ); $this->assertSame( 0, $two->remaining_own_output() );
		$this->assertSame( $plans, $this->property( $s, 'snapshot_plans' ), 'Neither completed read consumes whole private or witness.' );
		$codec = new LegacyStateCodec(); $before = SnapshotReadPlan::compile( array( 'header' => 'a', 'items' => $first, 'tail' => 'b' ), $p['descriptor'], $codec, null );
		$after = SnapshotReadPlan::compile( array( 'header' => array( 'deep' => array( 'field' => str_repeat( '/', 400 ) ) ), 'items' => $first, 'tail' => array_fill( 0, 40, null ) ), $p['descriptor'], $codec, null );
		$this->assertGreaterThan( $before['features']['logical_tokens'], $after['features']['logical_tokens'] );
		$this->assertGreaterThan( $before['parts']['whole_children_wrapper_cow'], $after['parts']['whole_children_wrapper_cow'] );
	}
	public function test_cell_validator_rejects_missing_overlap_margin_or_runtime_binding(): void {
		$db = new SnapshotScaleConnection(); $s = $this->authenticated_snapshot( $db ); $s->replace_many( $this->change() );
		$plans = $this->property( $s, 'snapshot_plans' ); $p = reset( $plans );
		$cell = array( 'runtime' => SnapshotReadPlan::runtime_binding(), 'plan' => $p['complete'], 'identity' => array(), 'trace_sha256' => array_fill( 0, 3, hash( 'sha256', '{}' ) ),
			'observer' => array( 'independent' => true, 'bounded_bytes' => 1024, 'allocator_growth_quantum' => 0 ), 'domain' => 'host-double-exact-cell' );
		$bad = array( 'missing-runtime' => $cell, 'missing-overlap' => $cell, 'missing-margin' => $cell, 'wrong-binary' => $cell, 'no-raw-seams' => $cell, 'too-few-repeats' => $cell );
		unset( $bad['missing-runtime']['runtime'], $bad['missing-overlap']['plan']['parts']['retained_two_outputs_cow'], $bad['missing-margin']['observer']['bounded_bytes'] );
		$bad['wrong-binary']['runtime']['binary_sha256'] = str_repeat( '0', 64 );
		foreach ( $bad as $name => $candidate ) {
			try { SnapshotReadProfile::qualify( $candidate, array_fill( 0, $name === 'too-few-repeats' ? 2 : 3, '{}' ) ); $this->fail( 'Incomplete cell qualified: ' . $name ); }
			catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'finite host cell refused', $e->getMessage() ); }
		}
		$this->assertNull( $this->property( $s, 'snapshot_read_phase' ), 'No manual/synthetic production qualification.' );
	}
}
class AuthenticatedNativeDecodeSpy extends LegacyStateCodec {
	public $entries = 0;
	public function decode( $json ) { ++$this->entries; return parent::decode( $json ); }
	public function decode_record_payload( $json ) { ++$this->entries; return parent::decode_record_payload( $json ); }
}
/** Bounded transport fault injection, not a native SQL implementation. */
class AuthenticatedPageTransport {
	public $last_error = '';
	private $original;
	private $rows;
	public function __construct( $original, $rows ) { $this->original = $original; $this->rows = $rows; }
	public function prepare( $sql, $args ) { return $sql; }
	public function get_results( $sql, $mode ) {
		if ( strpos( $sql, ' AS window_bytes' ) !== false ) { return array( array( 'id' => $this->original['id'], 'item_order' => $this->original['item_order'], 'window_bytes' => SnapshotScaleConnection::bytes( $this->original ) ) ); }
		return $this->rows;
	}
}
