<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

class LegacyCollectionEnvelopeTest extends TestCase {
	private function wrapper() {
		return array( 9 => null, '09' => false, 'before' => "\xff\x00", 'items' => array( '09' => false, 9 => null ),
			MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'after' => array( 2 => -0.0 ) );
	}
	private function digest( $root, $items ) {
		$codec = new LegacyCollectionEnvelope();
		$hash = hash_init( 'sha256' ); hash_update( $hash, "legacy-logical-v1\0" );
		$codec->begin_digest( $hash, $root );
		foreach ( $items as $key => $value ) { $codec->digest_item( $hash, $root, $key, $value ); }
		$codec->end_digest( $hash, $root );
		return hash_final( $hash );
	}
	public function test_wrapper_headers_and_typed_items_round_trip_without_reordering(): void {
		$codec = new LegacyCollectionEnvelope(); $value = $this->wrapper();
		$split = $codec->split( 'migrated', 'map', $value );
		$this->assertSame( array( '09' => false, 9 => null ), $split['items'] );
		$this->assertSame( 2, $split['root']['root_count'] );
		$this->assertSame( array_keys( $value ), $split['root']['wrapper']['field_order'] );
		$this->assertArrayNotHasKey( 'items', $split['root']['wrapper']['values'] );
		$this->assertSame( serialize( $value ), serialize( $codec->rebuild( $split['root'], $split['items'] ) ) );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $value ), $this->digest( $split['root'], $split['items'] ) );
	}
	public function test_image_tag_queue_preserves_unknown_headers_sparse_typed_keys_and_duplicates(): void {
		$items = array( '09' => array( 'attachment_id' => 7, 'tags' => array( 'a' ) ),
			9 => array( 'attachment_id' => 7, 'tags' => array( 'b' ) ), 31 => false );
		$value = array( 'future-before' => "\xff\x00", 'items' => $items, 'processed' => 4,
			'errors' => array( 9 => 'retain' ), 'future-after' => array( '09' => null, 9 => false ) );
		$envelope = new LegacyCollectionEnvelope();
		$split = $envelope->split( ImageTagQueueRepository::STATE_KEY, 'map', $value );
		$this->assertSame( $items, $split['items'] );
		$this->assertSame( array_keys( $value ), $split['root']['wrapper']['field_order'] );
		$this->assertSame( serialize( $value ), serialize( $envelope->rebuild( $split['root'], $split['items'] ) ) );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $value ), $this->digest( $split['root'], $split['items'] ) );
		$bad = $split['root']; $bad['wrapper']['image_tag_queue'] = 0;
		try { $envelope->validate( $bad, ImageTagQueueRepository::STATE_KEY ); $this->fail( 'Malformed queue marker accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
	}
	public function test_large_saved_queue_preflights_as_independent_roots_without_deduping_legacy_duplicates(): void {
		$items = array(); for ( $i = 0; $i < 205; ++$i ) { $items[$i] = array( 'attachment_id' => 7, 'tags' => array( 'tag-' . $i ) ); }
		$value = array( 'items' => $items, 'total' => 205, 'unknown' => array( '09' => false, 9 => true ) );
		$db = new EnvelopeNoSqlConnection(); $snapshot = new LegacyTableSnapshot( $db, 1 );
		$preflight = new \ReflectionMethod( $snapshot, 'preflight' ); $preflight->setAccessible( true );
		$prepared = $preflight->invoke( $snapshot, array( array( 'key' => ImageTagQueueRepository::STATE_KEY,
			'order' => 0, 'kind' => 'map', 'value' => $value, 'expected_revision' => null ) ) );
		$entry = reset( $prepared );
		$this->assertSame( 205, $entry['stats']['record_count'] );
		$this->assertSame( 205, count( $entry['items'] ) );
		$this->assertSame( serialize( $value ), serialize( ( new LegacyCollectionEnvelope() )->rebuild(
			( new LegacyStateCodec() )->decode_collection_root( $entry['root_payload'] ), $entry['items'] ) ) );
		$this->assertSame( array(), $db->queries );
	}
	public function test_raw_maps_unknown_states_lists_and_empty_wrappers_keep_their_contract(): void {
		$codec = new LegacyCollectionEnvelope();
		foreach ( array( array( 'migrated', 'map', array( '09' => false, 9 => null ) ),
			array( 'unknown', 'map', $this->wrapper() ), array( 'queue', 'list', array( false, null ) ),
			array( 'albums', 'map', array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array() ) ) ) as $case ) {
			$split = $codec->split( $case[0], $case[1], $case[2] );
			$this->assertSame( serialize( $case[2] ), serialize( $codec->rebuild( $split['root'], $split['items'] ) ) );
			$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $case[2] ), $this->digest( $split['root'], $split['items'] ) );
		}
	}
	public function test_malformed_wrappers_and_metadata_refuse_lossy_reconstruction(): void {
		$codec = new LegacyCollectionEnvelope(); $value = $this->wrapper();
		foreach ( array( array_replace( $value, array( 'type' => 'plugins' ) ), array_replace( $value, array( 'items' => false ) ),
			array_replace( $value, array( MigratorSettings::COMPACT_MARKER => 2 ) ) ) as $bad ) {
			try { $codec->split( 'migrated', 'map', $bad ); $this->fail( 'Expected wrapper refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'wrapper', $error->getMessage() ); }
		}
		$root = $codec->split( 'migrated', 'map', $value )['root'];
		$bad = $root; $bad['wrapper']['field_order'][] = 'items';
		$other = $root; $other['wrapper']['values']['extra'] = false;
		foreach ( array( $bad, $other, array_replace( $root, array( 'root_count' => 3 ) ) ) as $invalid ) {
			try { $codec->rebuild( $invalid, $value['items'] ); $this->fail( 'Expected metadata refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$wrong_type = $root; $wrong_type['wrapper']['values']['type'] = 'plugins';
		try { $codec->validate( $wrong_type, 'migrated' ); $this->fail( 'Expected wrong state/type refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'state key', $error->getMessage() ); }
	}
	public function test_snapshot_preflight_counts_item_roots_not_wrapper_headers(): void {
		$db = new EnvelopeNoSqlConnection();
		$snapshot = new LegacyTableSnapshot( $db, 1 );
		$method = new \ReflectionMethod( $snapshot, 'preflight' );
		if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
		$value = $this->wrapper();
		$prepared = $method->invoke( $snapshot, array( array( 'key' => 'migrated', 'order' => 0, 'kind' => 'map',
			'value' => $value, 'expected_revision' => null ) ) );
		$entry = reset( $prepared );
		$this->assertSame( 2, $entry['stats']['record_count'] );
		$this->assertSame( $value['items'], $entry['items'] );
		$this->assertSame( $value, $entry['value'] );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $value ), $entry['digest'] );
		$this->assertSame( array(), $db->queries );
	}
	public function test_item_admission_accounts_for_the_wrapper_canonical_depth(): void {
		$codec = new LegacyCollectionEnvelope(); $value = false;
		for ( $i = 0; $i < 63; ++$i ) { $value = array( $value ); }
		$raw = $codec->split( 'migrated', 'map', array( 'id' => $value ) );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( array( 'id' => $value ) ), $this->digest( $raw['root'], $raw['items'] ) );
		$wrapper = $codec->split( 'migrated', 'map', array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array( 'id' => $value ) ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'depth budget' );
		$codec->digest_item( hash_init( 'sha256' ), $wrapper['root'], 'id', $value );
	}
}

class EnvelopeNoSqlConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $queries = array();
	public function __construct() {}
	public function get_results( $sql, $format ) { $this->queries[] = $sql; throw new \RuntimeException( 'Unexpected SQL.' ); }
}
