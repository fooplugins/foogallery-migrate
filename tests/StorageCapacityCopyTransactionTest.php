<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageCopyCursor;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use PHPUnit\Framework\TestCase;

/** Query/failure contract only. NOT native SQL atomicity or public acceptance. */
class StorageCapacityCopyTransactionTest extends TestCase {
	private function setup_copy( $fault = '', $value = null ) {
		$h = new StorageCapacityConversionTest( 'unused' ); $scale = $h->scale( 2 );
		if ( null === $value ) { $value = array( 'tree' => array( array( 'id' => 1, 'children' => array( false, 'x', null, 7, array( 'id' => 2 ) ) ) ) ); }
		$codec = new LegacyStateCodec(); $hash = hash( 'sha256', serialize( $value ) );
		$r = array( 'version' => 4, 'blog_id' => 1, 'backend' => 'option', 'epoch' => 0, 'switch_id' => str_repeat( 'a', 32 ), 'from' => 'option', 'to' => 'table', 'phase' => 'copying', 'table_id' => str_repeat( 'a', 32 ), 'source_digest' => hash( 'sha256', 'present:' . $hash ), 'logical_digest' => $codec->snapshot_digest( $value ), 'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1, 'retained_digest' => null );
		$r['copy_cursor'] = StorageCopyCursor::initial( $r ); $db = new CapacityCopyDb(); $db->raw_hash = $hash; $db->control = json_encode( $r ); $db->fault = $fault;
		return array( $db, $value, $r, $scale );
	}
	private function batch( $db, $value, $r, $scale, $discard = false ) {
		return ( new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale ) )->conversion_batch( $r, $value, new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ), $discard );
	}
	private function completed_copy() {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		for ( $i = 0; $i < 3; ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
		$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
		return array( $db, $value, $r, $scale );
	}
	private function verify_batch( $db, $value, $r, $scale ) {
		return ( new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale ) )->conversion_verify_batch( $r, $value,
			new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ) );
	}
	public function test_verified_cursor_requires_every_exact_page_and_old_v4_layout_resumes(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy();
		$this->assertArrayNotHasKey( 'verify_cursor', $r, 'Old persisted verified v4 controls remain readable.' );
		for ( $i = 1; $i <= 3; ++$i ) {
			$proof = $this->verify_batch( $db, $value, $r, $scale ); $r = $proof['record'];
			$this->assertFalse( $proof['already_complete'] );
			$this->assertSame( $i * 2, $r['verify_cursor']['verified_rows'] );
			$this->assertSame( 'verified', $r['phase'] ); $this->assertSame( $r, StorageControl::decode( $db->control, 1 ) );
		}
		$this->assertCount( 6, $db->records );
		$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
	}
	public function test_older_copied_prefix_corruption_and_failed_page_never_advance_proof(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy();
		$original = $db->records[0]['payload']; $db->records[0]['payload'][0] = 'X';
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Old copied row damage was selected.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $r ), $db->control );
		$db->records[0]['payload'] = $original;
		$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
		$db->fault = 'verify-select'; $before = $db->control;
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Interrupted proof advanced.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( $before, $db->control );
		$db->fault = ''; $next = $this->verify_batch( $db, $value, $r, $scale );
		$this->assertSame( 4, $next['record']['verify_cursor']['verified_rows'] );
	}
	public function test_verification_refuses_malformed_cursor_changed_source_and_head_before_progress(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy();
		$bad = $r; $bad['verify_cursor'] = StorageCopyCursor::initial_verify(); $bad['verify_cursor']['verified_rows'] = -1;
		try { StorageControl::decode( json_encode( $bad ), 1 ); $this->fail( 'Malformed verification cursor accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$db->raw_hash = str_repeat( '0', 64 );
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Changed source accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $r ), $db->control );
		$db->raw_hash = hash( 'sha256', serialize( $value ) ); $db->heads[0]['revision'] = 2;
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Changed head accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $r ), $db->control );
	}
	public function test_completed_proof_metadata_can_resume_after_exact_source_delete_but_not_changed_head(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy();
		for ( $i = 0; $i < 3; ++$i ) { $r = $this->verify_batch( $db, $value, $r, $scale )['record']; }
		$r['phase'] = 'flipped'; $r['backend'] = 'table'; $r['epoch'] = 1; $db->control = json_encode( $r );
		$repo = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$this->assertTrue( $repo->conversion_verified_metadata( $r ) );
		$db->heads[0]['revision'] = 2;
		try { $repo->conversion_verified_metadata( $r ); $this->fail( 'Changed head reused a completed proof.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
	}
	public function test_stale_bound_proof_cursor_and_post_page_head_change_refuse_without_advancing(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy();
		$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
		$stale = $r; $stale['verify_cursor']['heads_digest'] = str_repeat( '0', 64 ); $db->control = json_encode( $stale );
		try { $this->verify_batch( $db, $value, $stale, $scale ); $this->fail( 'Stale bound proof accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $stale ), $db->control );
		$db->control = json_encode( $r ); $db->heads[0]['revision'] = 2;
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Post-page head mutation accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( json_encode( $r ), $db->control );
	}
	public function test_uncertain_verification_commit_requires_fresh_cursor_reconciliation_not_replay(): void {
		list( $db, $value, $r, $scale ) = $this->completed_copy(); $db->fault = 'commit';
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Uncertain verification commit was reported safe.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'uncertain', $e->getMessage() ); }
		$this->assertNotContains( 'ROLLBACK', array_slice( $db->queries, -2 ) );
		$durable = StorageControl::decode( $db->control, 1 ); $this->assertSame( 2, $durable['verify_cursor']['verified_rows'] );
		$fresh = clone $db; $fresh->fault = ''; $fresh->queries = array();
		$next = $this->verify_batch( $fresh, $value, $durable, $scale );
		$this->assertSame( 4, $next['record']['verify_cursor']['verified_rows'] );
	}
	public function test_row_boundary_cursor_and_head_are_committed_in_the_same_transaction_and_resume_exactly(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		for ( $i = 0; $i < 3; ++$i ) {
			$b = $this->batch( $db, $value, $r, $scale ); $r = $b['record'];
			$this->assertSame( ( $i + 1 ) * 2, $r['copy_cursor']['committed_rows'] );
			$this->assertSame( $r, StorageControl::decode( $db->control, 1 ) );
			$this->assertSame( $i === 2, $b['complete'] );
			$this->assertSame( $i === 2 ? $r['logical_digest'] : null, $b['proved_logical_digest'] );
		}
		$this->assertCount( 6, $db->records ); $this->assertSame( 3, count( array_filter( $db->queries, function ( $q ) { return 'COMMIT' === $q; } ) ) );
		$done = $this->batch( $db, $value, $r, $scale );
		$this->assertTrue( $done['complete'] ); $this->assertSame( $r['logical_digest'], $done['proved_logical_digest'] ); $this->assertCount( 6, $db->records );
	}
	public function test_resumed_collection_then_scalar_head_advances_without_physical_rows_and_is_verified(): void {
		$value = array( 'tree' => array( array( 'id' => 1, 'children' => array( false, 'x', null, 7, array( 'id' => 2 ) ) ) ), 'migrated-revision' => 7 );
		list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
		for ( $i = 0; $i < 3; ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
		$this->assertSame( 1, $r['copy_cursor']['state_order'] );
		$this->assertSame( 6, $r['copy_cursor']['committed_rows'] );
		$b = $this->batch( $db, $value, $r, $scale ); $r = $b['record'];
		$this->assertTrue( $b['complete'] );
		$this->assertSame( 2, $r['copy_cursor']['state_order'] );
		$this->assertSame( 6, $r['copy_cursor']['committed_rows'] );
		$this->assertCount( 2, $db->heads ); $this->assertCount( 6, $db->records );
		$this->assertSame( 'scalar', $db->heads[1]['state_kind'] );
		$this->assertSame( 0, $db->heads[1]['record_count'] );
		$this->assertSame( array( 'version' => 1, 'kind' => 'scalar', 'value' => 7 ), ( new LegacyStateCodec() )->decode( $db->heads[1]['root_payload'] ) );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( 7 ), bin2hex( $db->heads[1]['snapshot_digest'] ) );
		$this->assertTrue( $this->batch( $db, $value, $r, $scale )['complete'] );
		$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
		for ( $i = 0; $i < 3; ++$i ) { $r = $this->verify_batch( $db, $value, $r, $scale )['record']; }
		$this->assertSame( 2, $r['verify_cursor']['state_order'] );
		$this->assertSame( 6, $r['verify_cursor']['verified_rows'] );
		$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
	}
	public function test_scalar_only_and_leading_scalar_preserve_native_types_and_zero_row_proof(): void {
		foreach ( array( null, false, 0, '', "a\0b" ) as $scalar ) {
			foreach ( array( false, true ) as $leading ) {
				$value = $leading ? array( 'revision' => $scalar, 'tree' => array() ) : array( 'revision' => $scalar );
				list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
				for ( $i = 0; $i < count( $value ); ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
				$this->assertSame( count( $value ), $r['copy_cursor']['state_order'] );
				$this->assertSame( 0, $r['copy_cursor']['committed_rows'] );
				$this->assertSame( $scalar, ( new LegacyStateCodec() )->decode( $db->heads[0]['root_payload'] )['value'] );
				$this->assertSame( 0, $db->heads[0]['record_count'] );
				$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
				for ( $i = 0; $i < count( $value ); ++$i ) { $r = $this->verify_batch( $db, $value, $r, $scale )['record']; }
				$this->assertSame( count( $value ), $r['verify_cursor']['state_order'] );
				$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
			}
		}
	}
	public function test_array_checkpoints_are_scalar_heads_with_exact_zero_row_copy_and_verification(): void {
		foreach ( array( MigratorSettings::KEY_GALLERIES . '-state', MigratorSettings::KEY_ALBUMS . '-state', ImageTagQueueRepository::STATE_KEY ) as $key ) {
			$checkpoint = array( 'queued' => 1, 'completed' => 0, 'progress' => 0 );
			$value = array( 'galleries' => array( array( 'id' => 1 ) ), $key => $checkpoint );
			list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
			$r = $this->batch( $db, $value, $r, $scale )['record'];
			$this->assertSame( 1, $r['copy_cursor']['state_order'] );
			$r = $this->batch( $db, $value, $r, $scale )['record'];
			$this->assertSame( 2, $r['copy_cursor']['state_order'] );
			$this->assertSame( 1, $r['copy_cursor']['committed_rows'] );
			$this->assertSame( 'scalar', $db->heads[1]['state_kind'] );
			$this->assertSame( 0, $db->heads[1]['record_count'] );
			$this->assertSame( strlen( $db->heads[1]['root_payload'] ), $db->heads[1]['payload_bytes'] );
			$this->assertSame( array( 'version' => 1, 'kind' => 'scalar', 'value' => $checkpoint ),
				( new LegacyStateCodec() )->decode( $db->heads[1]['root_payload'] ) );
			$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $checkpoint ), bin2hex( $db->heads[1]['snapshot_digest'] ) );
			$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
			$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
			$this->assertSame( 2, $r['verify_cursor']['state_order'] );
			$this->assertSame( 1, $r['verify_cursor']['verified_rows'] );
			$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
		}
	}
	public function test_valid_saved_image_tag_queue_has_lossless_indexed_conversion_shape(): void {
		list( $db, $source, $record, $scale ) = $this->setup_copy();
		$snapshot = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$shape = new \ReflectionMethod( $snapshot, 'conversion_shape' ); $shape->setAccessible( true );
		$value = array( 'future' => "\xff\x00", 'items' => array( '09' => array( 'attachment_id' => 3 ),
			9 => array( 'attachment_id' => 3 ), 20 => false ), 'processed' => 7 );
		$result = $shape->invoke( $snapshot, ImageTagQueueRepository::STATE_KEY, $value );
		$this->assertSame( 'map', $result['kind'] );
		$this->assertSame( $value['items'], $result['items'] );
		$root = ( new LegacyStateCodec() )->decode_collection_root( $result['root'] );
		$this->assertSame( $value, ( new \FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope() )->rebuild( $root, $result['items'] ) );
		$this->assertSame( 'scalar', $shape->invoke( $snapshot, ImageTagQueueRepository::STATE_KEY, array( 'opaque' => false ) )['kind'] );
	}
	public function test_saved_queue_copy_and_exact_verification_keep_typed_sparse_items_as_rows(): void {
		$queue = array( 'future' => false, 'items' => array( '09' => false, 9 => null, 20 => 0 ), 'total' => 3 );
		list( $db, $value, $r, $scale ) = $this->setup_copy( '', array( ImageTagQueueRepository::STATE_KEY => $queue ) );
		for ( $i = 0; $i < 5 && $r['copy_cursor']['state_order'] < 1; ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
		$this->assertSame( 1, $r['copy_cursor']['state_order'] );
		$this->assertSame( 'map', $db->heads[0]['state_kind'] );
		$this->assertSame( 3, $db->heads[0]['record_count'] );
		$codec = new LegacyStateCodec(); $state_hash = $codec->key_hash( ImageTagQueueRepository::STATE_KEY );
		$this->assertSame( array_map( function ( $key ) use ( $codec, $state_hash ) { return $codec->node_hash( $state_hash, null, $key ); },
			array( '09', 9, 20 ) ), array_map( function ( $row ) { return bin2hex( $row['node_hash'] ); }, $db->records ) );
		$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
		for ( $i = 0; $i < 5; ++$i ) {
			$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
			if ( 1 === $r['verify_cursor']['state_order'] ) { break; }
		}
		$this->assertSame( 1, $r['verify_cursor']['state_order'] );
		$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
	}
	public function test_older_v4_scalar_queue_head_reproves_without_reinterpreting_saved_items(): void {
		$key = ImageTagQueueRepository::STATE_KEY;
		$queue = array( 'items' => array( array( 'attachment_id' => 7 ) ), 'total' => 1 );
		list( $db, $value, $r, $scale ) = $this->setup_copy( '', array( $key => $queue ) );
		$codec = new LegacyStateCodec();
		$root = $codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $queue ) );
		$db->heads[] = array( 'id' => 10, 'blog_id' => 1, 'state_key_hash' => hex2bin( $codec->key_hash( $key ) ),
			'state_key_value' => $codec->key_value( $key ), 'state_order' => 0, 'state_kind' => 'scalar',
			'active_generation' => 1, 'revision' => 1, 'codec_version' => LegacyStateCodec::VERSION,
			'record_count' => 0, 'payload_bytes' => strlen( $root ), 'digest_revision' => 1,
			'digest_status' => 'current', 'root_payload' => $root,
			'snapshot_digest' => hex2bin( $codec->snapshot_digest( $queue ) ) );
		$snapshot = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$heads = new \ReflectionMethod( $snapshot, 'copy_heads' ); $heads->setAccessible( true );
		$r['copy_cursor']['state_order'] = 1;
		$r['copy_cursor']['completed_heads_digest'] = hash( 'sha256',
			$r['copy_cursor']['completed_heads_digest'] . json_encode( $heads->invoke( $snapshot )[0] ) );
		$db->control = json_encode( $r );
		$this->assertTrue( $this->batch( $db, $value, $r, $scale )['complete'] );
		$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
		$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
		$this->assertSame( 1, $r['verify_cursor']['state_order'] );
		$this->assertSame( 0, $r['verify_cursor']['verified_rows'] );
		$this->assertTrue( $this->verify_batch( $db, $value, $r, $scale )['already_complete'] );
	}
	public function test_content_scan_array_and_false_sentinel_keep_distinct_existing_shapes(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		$repo = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$method = new \ReflectionMethod( $repo, 'conversion_shape' ); $method->setAccessible( true );
		$this->assertSame( 'map', $method->invoke( $repo, ContentScanRepository::STATE_KEY,
			array( 'items' => array(), 'progress' => array() ) )['kind'] );
		$this->assertSame( 'scalar', $method->invoke( $repo, ContentScanRepository::STATE_KEY, false )['kind'] );
	}
	public function test_scalar_head_root_digest_and_extra_row_corruption_refuse_without_cursor_advance(): void {
		foreach ( array( 'root', 'digest', 'row', 'row-and-head' ) as $fault ) {
			$value = array( 'tree' => array( 'item' => array( 'id' => 1 ) ), 'galleries-state' => array( 'queued' => 1 ) );
			list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
			for ( $i = 0; $i < 2; ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
			$this->assertSame( 2, $r['copy_cursor']['state_order'] );
			if ( 'root' === $fault ) { $db->heads[1]['root_payload'] .= 'X'; }
			elseif ( 'digest' === $fault ) { $db->heads[1]['snapshot_digest'] = str_repeat( 'x', 32 ); }
			else {
				$extra = $db->records[0]; $extra['state_id'] = $db->heads[1]['id']; $db->records[] = $extra;
				if ( 'row-and-head' === $fault ) { $db->heads[1]['record_count'] = 1; $db->heads[1]['payload_bytes'] += $extra['payload_bytes']; }
			}
			$before = $db->control;
			try { $this->batch( $db, $value, $r, $scale ); $this->fail( 'Corrupt scalar destination accepted.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			$this->assertSame( $before, $db->control );
		}
	}
	public function test_verified_scalar_head_refuses_changed_root_digest_or_extra_physical_row(): void {
		foreach ( array( 'root', 'digest', 'row', 'row-and-head' ) as $fault ) {
			$value = array( 'tree' => array( 'item' => array( 'id' => 1 ) ), 'albums-state' => array( 'queued' => 1 ) );
			list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
			for ( $i = 0; $i < 2; ++$i ) { $r = $this->batch( $db, $value, $r, $scale )['record']; }
			$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
			if ( 'root' === $fault ) { $db->heads[1]['root_payload'] .= 'X'; }
			elseif ( 'digest' === $fault ) { $db->heads[1]['snapshot_digest'] = str_repeat( 'x', 32 ); }
			else {
				$extra = $db->records[0]; $extra['state_id'] = $db->heads[1]['id']; $db->records[] = $extra;
				if ( 'row-and-head' === $fault ) { $db->heads[1]['record_count'] = 1; $db->heads[1]['payload_bytes'] += $extra['payload_bytes']; }
			}
			$before = $db->control;
			try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Corrupt scalar head was verified.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			$this->assertSame( $before, $db->control );
		}
	}
	public function test_object_scalar_is_not_converted(): void {
		list( $db, $value, $r, $scale ) = $this->setup_copy();
		$repo = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$method = new \ReflectionMethod( $repo, 'conversion_shape' ); $method->setAccessible( true );
		try { $method->invoke( $repo, 'revision', new \stdClass() ); $this->fail( 'Object state accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
	}
	public function test_scalar_verification_refuses_a_child_cursor_without_advancing(): void {
		$value = array( 'revision' => 0 );
		list( $db, $value, $r, $scale ) = $this->setup_copy( '', $value );
		$r = $this->batch( $db, $value, $r, $scale )['record'];
		$r['phase'] = 'verified'; $r['copy_cursor'] = null; $db->control = json_encode( $r );
		$r = $this->verify_batch( $db, $value, $r, $scale )['record'];
		$r['verify_cursor']['state_order'] = 0;
		$r['verify_cursor']['next_node_ordinal'] = 1;
		$db->control = json_encode( $r ); $before = $db->control;
		try { $this->verify_batch( $db, $value, $r, $scale ); $this->fail( 'Scalar child cursor accepted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->assertSame( $before, $db->control );
	}
	public function test_resume_walks_only_committed_prefix_and_new_batch_not_uncommitted_tail_or_append_replay(): void {
		list( $db, $unused, $r, $scale ) = $this->setup_copy();
		$value = array( 'tree' => array_fill( 0, 128, array( 'id' => 1 ) ) );
		$db->raw_hash = hash( 'sha256', serialize( $value ) );
		$r['source_digest'] = hash( 'sha256', 'present:' . $db->raw_hash );
		$r['logical_digest'] = ( new LegacyStateCodec() )->snapshot_digest( $value ); $db->control = json_encode( $r );
		$r = $this->batch( $db, $value, $r, $scale )['record'];
		$codec = new LegacyStateCodec( 1048576, 64, 10000, $scale );
		$walks = new CopyWalkCounter( $codec, 24576, 16777216, $scale );
		$repo = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
		$p = new \ReflectionProperty( $repo, 'records' ); $p->setAccessible( true ); $p->setValue( $repo, $walks );
		$b = $repo->conversion_batch( $r, $value, new StorageControl( $db, 1 ), json_encode( array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( 'a', 32 ), 'expires' => time() + 300 ) ) );
		$this->assertSame( 4, $b['record']['copy_cursor']['committed_rows'] );
		// Only the prior root's tail is proved; root zero is never re-encoded.
		$this->assertSame( array( 1, 1, 2, 3, 4 ), $walks->orders );
		$this->assertSame( array( 24576, 0, 0, 0, 0 ), $walks->skips );
	}
	public function test_prefix_corruption_and_foreign_rows_refuse_before_any_new_insert(): void {
		foreach ( array( 'payload', 'foreign', 'source', 'generation', 'revision', 'blog', 'stale-control', 'cursor-root', 'cursor-node' ) as $fault ) {
			list( $db, $value, $r, $scale ) = $this->setup_copy(); $r = $this->batch( $db, $value, $r, $scale )['record'];
			if ( 'payload' === $fault ) { $db->records[0]['payload'][0] = 'X'; }
			elseif ( 'foreign' === $fault ) { $extra = $db->records[0]; $extra['node_hash'] = str_repeat( 'x', 32 ); $db->records[] = $extra; }
			elseif ( 'source' === $fault ) { $db->raw_hash = str_repeat( '0', 64 ); }
			elseif ( 'generation' === $fault ) { $db->records[0]['generation'] = 2; }
			elseif ( 'revision' === $fault ) { $db->heads[0]['revision'] = 1; }
			elseif ( 'blog' === $fault ) { $db->records[0]['blog_id'] = 2; }
			elseif ( 'stale-control' === $fault ) { $r['updated'] = 2; }
			else { $r['copy_cursor'][ 'cursor-root' === $fault ? 'next_root_ordinal' : 'next_node_ordinal' ] = 99; $db->control = json_encode( $r ); }
			$before = count( $db->records ); $control_before = $db->control;
			try { $this->batch( $db, $value, $r, $scale ); $this->fail( 'Changed prefix/source admitted.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			$this->assertCount( $before, $db->records ); $this->assertSame( $control_before, $db->control );
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
		$repo = new TableSnapshot( $db, 1, 24576, 16777216, null, null, $scale );
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

class CopyWalkCounter extends \FooPlugins\FooGalleryMigrate\LegacyRecordCodec {
	public $skips = array(); public $orders = array();
	public function walk_conversion_tree_from( $state_key, $key, $record, $order, $skip, &$visited, $emit ) {
		$this->skips[] = $skip; $this->orders[] = $order;
		return parent::walk_conversion_tree_from( $state_key, $key, $record, $order, $skip, $visited, $emit );
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
			$index = null; foreach ( $this->heads as $n => $head ) { if ( $head['id'] === $a[6] ) { $index = $n; break; } }
			if ( null === $index ) { return 0; }
			$h = &$this->heads[$index]; $h['state_kind'] = $a[0]; $h['record_count'] = $a[1]; $h['payload_bytes'] = $a[2]; $h['snapshot_digest'] = hex2bin( $a[3] );
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
		if ( strpos( $q, 'SELECT root_payload FROM' ) === 0 ) {
			foreach ( $this->heads as $head ) { if ( $head['id'] === $a[1] ) { return array( array( 'root_payload' => $head['root_payload'] ) ); } }
			return array();
		}
		if ( strpos( $q, 'SELECT * FROM' ) === 0 ) {
			if ( 'verify-select' === $this->fault ) { throw new \RuntimeException( 'Injected exact-row read failure.' ); }
			return array_values( array_filter( $this->records, function ( $row ) use ( $a ) { return $row['state_id'] === $a[1] && bin2hex( $row['node_hash'] ) === $a[2]; } ) );
		}
		return parent::get_results( $q, $format );
	}
}
