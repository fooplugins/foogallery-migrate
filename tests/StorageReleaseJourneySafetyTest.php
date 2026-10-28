<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Migrators\MigratorBase;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageReleaseJourney.php';

/** Host-only regressions. No native qualification or positive teardown claim. */
class StorageReleaseJourneySafetyTest extends TestCase {
	public function test_first_turn_discovers_real_sources_despite_the_cached_empty_collection(): void {
		$engine = new JourneySafetyEngine();
		$migrator = $engine->get_gallery_migrator();
		$this->assertSame( array(), $migrator->get_objects_to_migrate() );
		$this->assertSame( 0, $engine->plugin->reads );

		$objects = StorageReleaseJourney::discover_first_turn( $engine );
		$this->assertSame( array( $engine->plugin->source ), $objects );
		$this->assertSame( 1, $engine->detections );
		$this->assertSame( 1, $engine->plugin->reads );

		// Queueing and later turns must use ordinary cached reads. Forcing again
		// would discard queued/progress state. A newly visible source is not read.
		$objects[0]->queued = true;
		$engine->plugin->source = (object) array( 'id' => 'later-unrelated-source' );
		$this->assertSame( $objects, $migrator->get_objects_to_migrate() );
		$this->assertTrue( $migrator->get_objects_to_migrate()[0]->queued );
		$this->assertSame( 1, $engine->plugin->reads );
	}

	public function test_cleanup_has_no_destructive_path_without_independent_quiescence(): void {
		// Do not substitute an advisory file lock, env flag, PID/age check,
		// unchanged control, or POST_OWNER for exclusion of DB/filesystem/hooks.
		try {
			StorageReleaseJourney::cleanup();
			$this->fail( 'Destructive cleanup must remain unavailable.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'independently verified DB/filesystem/hook quiescence', $error->getMessage() );
			$this->assertStringContainsString( 'Preserve journal, CURRENT options, markers and files', $error->getMessage() );
		}
	}

	public function test_a_queued_first_turn_is_not_forced_again_after_reentry(): void {
		$this->assertTrue( StorageReleaseJourney::needs_first_discovery( array( 'turns' => 0 ) ) );
		$this->assertFalse( StorageReleaseJourney::needs_first_discovery( array( 'turns' => 0, 'uid' => 'owned-source' ) ) );
		$this->assertFalse( StorageReleaseJourney::needs_first_discovery( array( 'turns' => 1, 'uid' => 'owned-source' ) ) );
	}

	public function test_an_interrupted_discovery_is_preserved_instead_of_automatically_repeated(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Incomplete first discovery' );
		StorageReleaseJourney::needs_first_discovery( array( 'turns' => 0, 'discovery_pending' => true ) );
	}

	public function test_cli_cleanup_delegates_only_to_the_fail_closed_boundary(): void {
		$source = file_get_contents( __DIR__ . '/integration/storage-release-journey.php' );
		$this->assertSame( 1, preg_match( '/\} elseif \( \'cleanup\' === \$step \) \{(.*?)\n\t\}/s', $source, $match ) );
		$this->assertSame( 'J::cleanup();', trim( $match[1] ) );
		// No fallback, failure-finally purge, raw restoration or WP deletion hooks.
		foreach ( array( 'DELETE FROM', 'wp_delete_attachment(', 'wp_delete_post(', 'rmdir(', 'Native::value()', 'delete_option(' ) as $destructive ) {
			$this->assertStringNotContainsString( $destructive, $source );
		}
	}

	public function test_cli_forces_discovery_only_at_the_first_turn_after_source_ownership_check(): void {
		$source = file_get_contents( __DIR__ . '/integration/storage-release-journey.php' );
		$this->assertSame( 1, substr_count( $source, 'J::discover_first_turn( $engine )' ) );
		$this->assertSame( 1, preg_match( '/if \( J::needs_first_discovery\( \$journal \) \) \{\s*J::check\(.*?get_post_type\( \$journal\[\'source\'\] \).*?\$journal\[\'discovery_pending\'\] = true; \$save\( \$journal \);\s*\$objects = J::discover_first_turn\( \$engine \)/s', $source ) );
		$this->assertStringContainsString( "unset( \$journal['discovery_pending'] ); \$save( \$journal );", $source );
		$this->assertStringContainsString( '$objects = $migrator->get_objects_to_migrate();', $source );
	}
}

/** The REAL MigratorBase above exercises its false-vs-empty caching contract. */
class JourneySafetyMigrator extends MigratorBase {}
class JourneySafetyEngine {
	public $plugin;
	public $detections = 0;
	private $migrator;
	private $objects = array();
	public function __construct() {
		$this->plugin = new JourneySafetyPlugin();
		$this->migrator = new JourneySafetyMigrator( $this, 'galleries' );
	}
	public function assert_storage_writer() {}
	public function get_migrator_setting( $name, $default = false ) { return $this->objects; }
	public function set_migrator_setting( $name, $value ) { $this->objects = $value; return true; }
	public function get_plugins() { return array( $this->plugin ); }
	public function get_gallery_migrator() { return $this->migrator; }
	public function run_detection() { ++$this->detections; $this->plugin->is_detected = true; }
}
class JourneySafetyPlugin {
	public $is_detected = false;
	public $reads = 0;
	public $source;
	public function __construct() { $this->source = (object) array( 'id' => 'owned-real-source' ); }
	public function find_objects( $type ) { ++$this->reads; return array( $this->source ); }
}
