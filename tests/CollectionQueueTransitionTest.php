<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Migrators\MigratorBase;
use PHPUnit\Framework\TestCase;

/** Queue decisions are shared with the option loop; table persistence receives compact patches. */
class CollectionQueueTransitionTest extends TestCase {
	public function test_shared_summary_uses_selected_status_error_instead_of_error_projection(): void {
		$this->assertSame( array( 'queued' => 0, 'completed' => 0, 'progress' => 0 ), MigratorBase::queue_summary( 0, 0, 0 ) );
		$this->assertSame( array( 'queued' => 121, 'completed' => 1, 'progress' => 200 / 121 ), MigratorBase::queue_summary( 121, 1, 1 ) );
	}
	public function test_false_like_and_missing_fields_match_hydrated_option_transition_for_typed_gallery_and_album_roots(): void {
		$settings = new QueueTransitionSettings();
		foreach ( array( 'galleries' => 'gallery', 'albums' => 'album' ) as $bucket => $type ) {
			foreach ( array( 0, null, '', 'missing' ) as $part ) {
				$raw = array( 'object_type' => $type, 'plugin_name' => 'Fixture', 'ID' => '9', 'opaque' => 'retain',
					'children' => array( '09' => array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => 13, 'opaque_child' => 'retain-child' ) ) );
				if ( 'missing' !== $part ) { $raw['part_of_migration'] = $part; }
				$option = $settings->hydrate_migrated_object( $raw );
				$this->assertTrue( is_object( $option ) );
				MigratorBase::queue_transition( $option, false, null );
				$backend = new QueueTransitionBackend( array( 'key' => '09', 'value' => $raw ), false );
				$table = new MigratableCollectionRepository( $backend, $settings );
				$result = $table->queue_selected( $bucket, array(), array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_transition' ),
					array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_summary' ) );
				$this->assertSame( false, $result['selected'] );
				$this->assertSame( false, $result['value']['part_of_migration'] );
				$this->assertSame( $option->migration_status, $result['value']['migration_status'] );
				$this->assertFalse( $option->migrated );
				$this->assertSame( 0, $option->migrated_id );
				$this->assertArrayNotHasKey( 'migrated', $result['value'] );
				$this->assertArrayNotHasKey( 'migrated_id', $result['value'] );
				$this->assertSame( 'retain', $result['value']['opaque'] );
				$this->assertSame( $raw['children'], $result['value']['children'] );
				$this->assertSame( '09', $backend->row['key'] );
			}
		}
	}
	public function test_selected_missing_defaults_use_constructor_zero_id_and_title(): void {
		$settings = new QueueTransitionSettings();
		$raw = array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => 12 );
		$backend = new QueueTransitionBackend( array( 'key' => 9, 'value' => $raw ), true );
		$result = ( new MigratableCollectionRepository( $backend, $settings ) )->queue_selected( 'galleries',
			array( 'gallery_Fixture_12' => array( 'title' => 'Chosen' ) ),
			array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_transition' ),
			array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_summary' ) );
		$this->assertTrue( $result['selected'] );
		$this->assertFalse( $result['completed'] );
		$this->assertSame( 'queued', $result['value']['migration_status'] );
		$this->assertSame( 'Chosen', $result['value']['migrated_title'] );
		$this->assertArrayNotHasKey( 'migrated_id', $result['value'] );
		$this->assertSame( 9, $backend->row['key'] );
	}
	public function test_existing_null_status_is_not_normalized_for_migrated_match_or_cancel(): void {
		$settings = new QueueTransitionSettings();
		$raw = array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => 12,
			'migration_status' => null, 'migrated' => true, 'part_of_migration' => true, 'migrated_id' => 90 );
		$backend = new QueueTransitionBackend( array( 'key' => '09', 'value' => $raw ), true );
		$table = new MigratableCollectionRepository( $backend, $settings );
		$transition = array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_transition' );
		$summary = array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_summary' );
		$matched = $table->queue_selected( 'galleries', array( 'gallery_Fixture_12' => array( 'title' => 'Unneeded' ) ), $transition, $summary );
		$this->assertTrue( $matched['selected'] ); $this->assertTrue( $matched['completed'] );
		$this->assertNull( $matched['value']['migration_status'] );
		$backend->row['value']['migrated'] = false; $backend->matched = false;
		$cancelled = $table->queue_selected( 'galleries', array(), $transition, $summary );
		$this->assertFalse( $cancelled['selected'] ); $this->assertNull( $cancelled['value']['migration_status'] );
	}
}

class QueueTransitionSettings extends MigratorSettings {
	protected function get_available_plugin_by_name( $name ) { return 'Fixture' === $name ? new QueueTransitionPlugin() : false; }
}
class QueueTransitionPlugin {
	public function name() { return 'Fixture'; }
}
class QueueTransitionBackend extends LegacyTableBackend {
	public $row;
	public $matched;
	public function __construct( $row, $matched ) { $this->row = $row; $this->matched = $matched; }
	public function queue_collection( $key, $selection, $transition, $summarize ) {
		$entry = $this->matched ? reset( $selection ) : null;
		return call_user_func( $transition, $this->row, $this->matched, $entry );
	}
}
