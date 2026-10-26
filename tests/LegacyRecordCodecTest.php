<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use PHPUnit\Framework\TestCase;

class LegacyRecordCodecTest extends TestCase {
	private function fixture() {
		return array( 'migration_status' => 'queued', 'object_type' => 'album', 'ID' => '007',
			'unknown' => array( 9 => false, '009' => null, -2 => "\xff\x00雪" ), 'migrated' => false,
			'children' => array( '009' => array( 'object_type' => 'gallery', 'plugin_name' => 'MixedCase', 'ID' => 7,
				'part_of_migration' => false, 'children' => array( -2 => array( 'object_type' => 'image', 'ID' => '-007',
					'source_url' => 'https://example.test/A.JPG?x=1', 'plugin_name' => 'MixedCase', 'migrated' => true,
					'migrated_id' => 204, 'error' => array( 'code' => 'x', 'message' => "\xff" ), 'children' => array() ) ) ),
				9 => null ), 'progress' => -0.0, 'settings' => array(), 'children_count' => 2 );
	}

	private function rows( $record, $key = 'root', $codec = null ) {
		$codec = $codec ?: new LegacyRecordCodec();
		$rows = array();
		$stats = $codec->flatten_tree( 'albums', $key, $record, 7, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		return array( $rows, $stats );
	}

	public function test_nested_split_round_trip_preserves_every_key_field_byte_and_order(): void {
		$codec = new LegacyRecordCodec();
		list( $rows, $stats ) = $this->rows( $this->fixture(), '009', $codec );
		$this->assertSame( 4, $stats['record_count'] );
		$this->assertSame( 7, $rows[0]['item_order'] );
		$this->assertSame( 0, $rows[1]['item_order'] );
		$this->assertSame( 1, $rows[3]['item_order'] );
		$record = $codec->rebuild_tree( 'albums', $rows[0]['node_hash'], array_reverse( $rows ) );
		$this->assertSame( $this->fixture(), $record );
		$this->assertSame( pack( 'd', -0.0 ), pack( 'd', $record['progress'] ) );
		$envelope = ( new LegacyStateCodec() )->decode( $rows[0]['payload'] );
		$this->assertArrayNotHasKey( 'children', $envelope['values'] );
		$this->assertArrayNotHasKey( 'migration_status', $envelope['values'] );
		$this->assertSame( array( 'migration_status' => 'queued', 'migrated' => false, 'progress' => -0.0, 'children_count' => 2 ),
			( new LegacyStateCodec() )->decode( $rows[0]['mutable_payload'] ) );
		$this->assertSame( array_sum( array_column( $rows, 'payload_bytes' ) ), $stats['payload_bytes'] );
	}

	/** @dataProvider childrenValues */
	public function test_missing_empty_null_false_and_scalar_children_remain_distinct( $record ): void {
		list( $rows ) = $this->rows( $record );
		$this->assertSame( $record, ( new LegacyRecordCodec() )->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows ) );
	}

	public static function childrenValues(): array {
		return array( array( array() ), array( array( 'children' => array() ) ), array( array( 'children' => null ) ),
			array( array( 'children' => false ) ), array( array( 'children' => 7 ) ), array( false ), array( null ), array( "\xff" ) );
	}

	public function test_source_projections_preserve_exact_and_numeric_legacy_comparisons(): void {
		list( $rows ) = $this->rows( $this->fixture() );
		$codec = new LegacyStateCodec();
		$this->assertSame( 'MixedCase', $codec->decode( $rows[2]['source_plugin_value'] ) );
		$this->assertSame( '-007', $codec->decode( $rows[2]['source_id_value'] ) );
		$this->assertSame( 7, $rows[2]['source_id_numeric'] );
		$this->assertSame( 204, $rows[2]['migrated_id'] );
		$this->assertSame( 1, $rows[2]['has_error'] );
		$this->assertSame( 0, $rows[1]['is_selected'] );
		$variant = $this->fixture();
		$variant['children']['009']['children'][-2]['ID'] = '7';
		list( $other ) = $this->rows( $variant );
		$this->assertNotSame( $rows[2]['source_identity_hash'], $other[2]['source_identity_hash'] );
		$this->assertSame( $rows[2]['source_numeric_identity_hash'], $other[2]['source_numeric_identity_hash'] );
	}

	public function test_error_projection_uses_runtime_false_contract_not_php_empty(): void {
		foreach ( array( 0, '', array(), false, null ) as $error ) {
			list( $rows ) = $this->rows( array( 'object_type' => 'image', 'error' => $error ) );
			$this->assertSame( null === $error || false === $error ? 0 : 1, $rows[0]['has_error'] );
		}
	}

	/** @dataProvider corruptions */
	public function test_tree_corruption_fails_closed( $fault ): void {
		list( $rows ) = $this->rows( $this->fixture() );
		$root = $rows[0]['node_hash'];
		$scalar = new LegacyStateCodec();
		switch ( $fault ) {
			case 'missing-child': array_pop( $rows ); break;
			case 'missing-descendant': unset( $rows[2] ); break;
			case 'duplicate-node': $rows[] = $rows[1]; break;
			case 'wrong-state': $this->expectException( \RuntimeException::class );
				( new LegacyRecordCodec() )->rebuild_tree( 'galleries', $root, $rows ); return;
			case 'orphan': $rows[1]['parent_node_hash'] = str_repeat( 'a', 64 ); break;
			case 'cycle': $rows[0]['parent_node_hash'] = $rows[2]['node_hash']; break;
			case 'depth': $rows[1]['depth'] = 3; break;
			case 'order': $rows[3]['item_order'] = 0; break;
			case 'key': $rows[1]['key_value'] = base64_encode( '9' ); break;
			case 'logical-hash': $rows[0]['logical_hash'] = str_repeat( 'a', 64 ); break;
			case 'mutable': $rows[0]['mutable_payload'] = $scalar->encode( array( 'migrated' => true ) );
				$rows[0]['payload_bytes'] = strlen( $rows[0]['payload'] ) + strlen( $rows[0]['mutable_payload'] ); break;
			case 'payload-hash': $rows[0]['payload_hash'] = str_repeat( 'a', 64 ); break;
			case 'bytes': $rows[0]['payload_bytes']++; break;
			default: $rows[2][ $fault ] = in_array( $fault, array( 'is_selected', 'is_migrated', 'has_error', 'migrated_id', 'source_id_numeric' ), true ) ? 99 : 'wrong';
		}
		$this->expectException( \RuntimeException::class );
		( new LegacyRecordCodec() )->rebuild_tree( 'albums', $root, $rows );
	}

	public static function corruptions(): array {
		return array_map( function ( $fault ) { return array( $fault ); }, array( 'missing-child', 'missing-descendant',
			'duplicate-node', 'wrong-state', 'orphan', 'cycle', 'depth', 'order', 'key', 'logical-hash', 'mutable',
			'payload-hash', 'bytes', 'migration_status', 'object_type', 'is_selected', 'is_migrated', 'has_error', 'migrated_id',
			'source_plugin_value', 'source_plugin_hash', 'source_id_value', 'source_id_numeric', 'source_identity_hash', 'source_numeric_identity_hash' ) );
	}

	public function test_untrusted_objects_are_refused_without_lossy_compaction_or_hydration(): void {
		$record = $this->fixture();
		$record['unknown'] = new \stdClass();
		$this->expectException( \RuntimeException::class );
		$this->rows( $record );
	}

	public function test_stream_callback_failure_propagates_and_limits_never_truncate(): void {
		try {
			( new LegacyRecordCodec() )->flatten_tree( 'albums', 'root', $this->fixture(), 0,
				function () { throw new \RuntimeException( 'fixture write failure' ); } );
			$this->fail();
		} catch ( \RuntimeException $error ) { $this->assertSame( 'fixture write failure', $error->getMessage() ); }
		foreach ( array( new LegacyRecordCodec( null, 2 ), new LegacyRecordCodec( null, 100, 200 ) ) as $codec ) {
			try { $this->rows( $this->fixture(), 'root', $codec ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		}
	}

	public function test_rebuild_accepts_db_hex_and_unsigned_decimal_types_but_not_physical_identity_in_digest(): void {
		list( $rows ) = $this->rows( $this->fixture() );
		foreach ( $rows as &$row ) {
			$row['blog_id'] = '7'; $row['generation'] = '999'; $row['id'] = '123';
			foreach ( $row as $field => &$value ) {
				if ( is_int( $value ) ) { $value = (string) $value; }
				if ( null !== $value && substr( $field, -5 ) === '_hash' ) { $value = strtoupper( $value ); }
			}
			unset( $value );
		}
		unset( $row );
		$this->assertSame( $this->fixture(), ( new LegacyRecordCodec() )->rebuild_tree( 'albums', strtolower( $rows[0]['node_hash'] ), $rows ) );
	}

	public function test_existing_compact_whitelist_lazy_children_adapter_and_error_hydration_are_unchanged(): void {
		$settings = new RecordCodecSettingsFixture();
		$plugin = new RecordCodecPluginFixture();
		$image = new Image( $plugin ); $image->ID = 7; $image->source_url = 'https://example.test/A.JPG';
		$image->error = new \WP_Error( 'failure', 'Legacy failure' );
		$gallery = new Gallery( $plugin ); $gallery->ID = '007'; $gallery->title = 'Original';
		$gallery->children = array( $image ); $gallery->children_count = 1;
		$gallery->data = (object) array( 'old' => 'not in compact-v1' );
		$album = new Album( $plugin ); $album->children = array( $gallery ); $album->children_count = 1;
		$codec = new LegacyRecordCodec();
		foreach ( array( 'galleries' => array( $gallery ), 'albums' => array( $album ),
			'migrated' => array( 'custom_legacy_key' => $image ) ) as $bucket => $objects ) {
			$compact = $settings->compact_value( $bucket, $objects ); $rebuilt = $compact; $rebuilt['items'] = array();
			foreach ( $compact['items'] as $key => $item ) {
				$rows = array();
				$codec->flatten_tree( $bucket, $key, $item, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
				$rebuilt['items'][ $key ] = $codec->rebuild_tree( $bucket, $rows[0]['node_hash'], $rows );
			}
			$this->assertSame( $compact, $rebuilt );
			$expected = $settings->hydrate_value( $bucket, $compact );
			$actual = $settings->hydrate_value( $bucket, $rebuilt );
			$this->assertEquals( $expected, $actual );
		}
		$hydrated_gallery = $settings->hydrate_value( 'galleries', $settings->compact_value( 'galleries', array( $gallery ) ) )[0];
		$this->assertSame( array(), $hydrated_gallery->children );
		$this->assertSame( 1, $hydrated_gallery->children_count );
		$this->assertNull( $hydrated_gallery->data );
		$this->assertSame( 'gallery_MixedCase_007', $hydrated_gallery->unique_identifier() );
		$missing_plugin = $settings->compact_migrated_object( $gallery ); $missing_plugin['plugin_name'] = 'missing';
		$this->assertSame( $missing_plugin, $settings->hydrate_migrated_object( $missing_plugin ) );
	}

	public function test_tree_limits_are_checked_before_logical_hashes_emission_or_decode(): void {
		$spy = new RecordCodecBudgetSpy(); $emitted = 0;
		$codec = new LegacyRecordCodec( $spy, 1 );
		try { $codec->flatten_tree( 'galleries', 9, array( 'children' => array_fill( 0, 5000, array() ) ), 0,
			function () use ( &$emitted ) { $emitted++; } ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'record budget', $error->getMessage() ); }
		$this->assertSame( 0, $emitted ); $this->assertSame( 0, $spy->digests );
		list( $rows, $stats ) = $this->rows( $this->fixture() );
		$exact = new LegacyRecordCodec( $spy, count( $rows ), $stats['encoded_bytes'] );
		$this->assertSame( $this->fixture(), $exact->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows ) );
		foreach ( array( new LegacyRecordCodec( $spy, count( $rows ) - 1 ),
			new LegacyRecordCodec( $spy, count( $rows ), $stats['encoded_bytes'] - 1 ) ) as $limited ) {
			$spy->decodes = 0;
			try { $limited->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
			$this->assertSame( 0, $spy->decodes );
		}
	}

	public function test_root_key_validation_is_symmetric_and_consumer_refusal_aborts(): void {
		foreach ( array( 9, '009', '', "\xff\x00" ) as $key ) {
			list( $rows ) = $this->rows( array(), $key );
			$this->assertSame( array(), ( new LegacyRecordCodec() )->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows ) );
		}
		foreach ( array( '9', true, null, 0.5 ) as $key ) {
			$emitted = 0;
			try { ( new LegacyRecordCodec() )->flatten_tree( 'albums', $key, array(), 0, function () use ( &$emitted ) { $emitted++; } ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertSame( 0, $emitted ); }
		}
		$this->expectException( \RuntimeException::class );
		( new LegacyRecordCodec() )->flatten_tree( 'albums', 9, $this->fixture(), 0, function () { return false; } );
	}

	/** @dataProvider splitFaults */
	public function test_malformed_envelope_refusal_after_valid_checksum( $fault ): void {
		list( $rows ) = $this->rows( $this->fixture() ); $codec = new LegacyStateCodec();
		$base = $codec->decode( $rows[0]['payload'] ); $mutable = $codec->decode( $rows[0]['mutable_payload'] );
		switch ( $fault ) {
			case 'version': $base['version'] = 999; break;
			case 'kind': $base['kind'] = true; break;
			case 'mutable-scalar': $mutable = false; break;
			case 'duplicate-field': $base['field_order'][] = 'object_type'; break;
			case 'coercible-field': $base['field_order'][] = '9'; break;
			case 'missing-field': unset( $base['values']['ID'] ); break;
			case 'extra-field': $base['values']['extra'] = true; break;
			case 'overlap': $base['values']['migration_status'] = 'queued'; break;
			case 'wrong-child-count': $base['child_count']++; break;
			case 'missing-child-marker': $base['field_order'] = array_values( array_diff( $base['field_order'], array( 'children' ) ) ); break;
		}
		$rows[0]['payload'] = $codec->encode( $base ); $rows[0]['mutable_payload'] = $codec->encode( $mutable );
		$rows[0]['payload_hash'] = hash( 'sha256', $rows[0]['payload'] );
		$rows[0]['payload_bytes'] = strlen( $rows[0]['payload'] ) + strlen( $rows[0]['mutable_payload'] );
		$this->expectException( \RuntimeException::class );
		( new LegacyRecordCodec() )->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows );
	}

	public static function splitFaults(): array {
		return array_map( function ( $fault ) { return array( $fault ); }, array( 'version', 'kind', 'mutable-scalar', 'duplicate-field',
			'coercible-field', 'missing-field', 'extra-field', 'overlap', 'wrong-child-count', 'missing-child-marker' ) );
	}

	public function test_valid_identities_do_not_allow_orphans_unrelated_roots_or_sibling_gaps(): void {
		foreach ( array( 'orphan', 'extra-root', 'gap' ) as $fault ) {
			list( $rows ) = $this->rows( $this->fixture() ); $codec = new LegacyStateCodec();
			if ( 'gap' === $fault ) { $rows[2]['item_order'] = 9; }
			else {
				list( $extra ) = $this->rows( array(), 'extra' );
				if ( 'orphan' === $fault ) {
					$extra[0]['parent_node_hash'] = str_repeat( 'a', 64 ); $extra[0]['depth'] = 1; $extra[0]['row_kind'] = 'child';
					$extra[0]['node_hash'] = $codec->node_hash( $codec->key_hash( 'albums' ), $extra[0]['parent_node_hash'], 'extra' );
				}
				$rows[] = $extra[0];
			}
			try { ( new LegacyRecordCodec() )->rebuild_tree( 'albums', $rows[0]['node_hash'], $rows ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'gap' === $fault ? 'sibling order' : 'Orphan', $error->getMessage() ); }
		}
	}

	public function test_all_known_option_keys_and_unknown_values_preserve_logical_snapshot_and_are_not_rewritten(): void {
		$state = array( 'plugins' => array( '_foogallery_migrate_compact' => 1, 'type' => 'plugins', 'items' => array() ),
			'galleries' => array(), 'galleries-state' => array( 'queued' => 1, 'completed' => 0, 'progress' => 0 ),
			'albums' => array(), 'albums-state' => false, 'migrated' => array(), 'migrated-revision' => 17,
			'image-tag-sync' => array( 'queue' => array( 204 => array( 'tags' => array( '雪' ), 'done' => false ) ), 'cursor' => 0 ),
			'wordpress-core-mode' => true, 'block-shortcode_scan_state' => array( 'items' => array( '009' => array( 'selector' => "\xff", 'status' => null ) ), 'progress' => array( 'last_id' => 42 ) ),
			'block-shortcode' => array( array( 'post_id' => 42, 'original' => '[example id="007"]' ) ),
			'block-shortcode_scan_progress' => null, 'unknown' => array( 9 => false, '009' => null, 'bytes' => "\xff\x00" ) );
		$GLOBALS['foogallery_migrate_test_options'][ FOOGALLERY_MIGRATE_OPTION_DATA ] = $state;
		$codec = new LegacyStateCodec(); $rebuilt = array();
		foreach ( $state as $key => $value ) { $rebuilt[ $key ] = $codec->decode( $codec->encode( $value ) ); }
		$this->assertSame( $state, $rebuilt );
		$this->assertSame( $codec->snapshot_digest( $state ), $codec->snapshot_digest( $rebuilt ) );
		$this->assertSame( $state, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$legacy = new Gallery( new RecordCodecPluginFixture() ); $legacy->extra = 'observable old property';
		$GLOBALS['foogallery_migrate_test_options'][ FOOGALLERY_MIGRATE_OPTION_DATA ] = array( 'galleries' => array( $legacy ) );
		$this->assertSame( array( $legacy ), ( new MigratorSettings() )->get_migrator_setting( 'galleries' ) );
		try { $this->rows( $legacy ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Retain option authority', $error->getMessage() ); }
		$this->assertSame( $legacy, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['galleries'][0] );
	}
}

class RecordCodecSettingsFixture extends MigratorSettings {
	public function compact_value( $key, $value ) { return $this->compact_migrator_setting( $key, $value ); }
	public function hydrate_value( $key, $value ) { return $this->hydrate_migrator_setting( $key, $value ); }
	protected function get_available_plugin_by_name( $name ) { return 'MixedCase' === $name ? new RecordCodecPluginFixture() : false; }
}

class RecordCodecPluginFixture {
	public function name() { return 'MixedCase'; }
}

class RecordCodecBudgetSpy extends LegacyStateCodec {
	public $digests = 0;
	public $decodes = 0;
	public function snapshot_digest( $value ) { $this->digests++; return parent::snapshot_digest( $value ); }
	public function decode( $value ) { $this->decodes++; return parent::decode( $value ); }
}
