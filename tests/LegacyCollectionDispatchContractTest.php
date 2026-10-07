<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Translation contracts; guarded SQL harness separately exercises the repository. */
class LegacyCollectionDispatchContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_option_writes'] ); }
	public function test_option_and_table_collection_snapshot_and_companion_parity(): void {
		$object = new \FooPlugins\FooGalleryMigrate\Objects\Image();
		$object->name = 'typed collection'; $object->unknown = "\xff\x00";
		$values = array( '09' => $object, 9 => clone $object );
		$settings = new MigratorSettings();
		$table = new CollectionDispatchBackend();
		$option = new MigratableCollectionRepository( new LegacyOptionBackend(), $settings );
		$repo = new MigratableCollectionRepository( $table, $settings );
		$this->assertTrue( $option->update_with_state( 'galleries', $values, 'queued' ) );
		$writes = $GLOBALS['foogallery_migrate_test_option_writes'];
		$this->assertTrue( $repo->update_with_state( 'galleries', $values, 'queued' ) );
		$this->assertSame( serialize( $option->read_snapshot( 'galleries' ) ), serialize( $repo->read_snapshot( 'galleries' ) ) );
		$this->assertSame( array( 'galleries-state' => 'queued' ), $table->companions );
		$this->assertSame( $writes, $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	public function test_table_nested_update_encodes_only_the_requested_replacement(): void {
		$backend = new CollectionDispatchBackend();
		$repo = new MigratableCollectionRepository( $backend, new MigratorSettings() );
		$image = new \FooPlugins\FooGalleryMigrate\Objects\Image(); $image->source_url = 'child';
		$locator = array( 'root' => '09', 'path' => array( 9 ) );
		$this->assertTrue( $repo->update_record( 'galleries', $locator, $image ) );
		$this->assertSame( $locator, $backend->locator );
		$this->assertSame( 'image', $backend->record['object_type'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	public function test_injected_engine_uses_bounded_collection_dispatch(): void {
		$backend = new CollectionDispatchBackend();
		$settings = new MigratorSettings();
		$engine = new \FooPlugins\FooGalleryMigrate\MigratorEngine( new \FooPlugins\FooGalleryMigrate\MigratedHistoryRepository( $backend, $settings ) );
		$image = new \FooPlugins\FooGalleryMigrate\Objects\Image(); $image->source_url = 'queued-child';
		$this->assertTrue( $engine->set_migrator_setting( 'galleries', array( '09' => $image ) ) );
		$this->assertSame( array( '09' ), array_keys( $engine->get_migrator_setting( 'galleries' ) ) );
		$this->assertSame( 'queued-child', $engine->get_migrator_setting( 'galleries' )['09']->source_url );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
}

class CollectionDispatchBackend extends LegacyTableBackend {
	public $value; public $companions; public $locator; public $record;
	public function __construct() {}
	public function collection_snapshot( $key ) { return $this->value; }
	public function replace_collection( $key, $value, $companions = array() ) { $this->value = $value; $this->companions = $companions; return true; }
	public function update_collection_record( $key, $locator, $value ) { $this->locator = $locator; $this->record = $value; return true; }
}
