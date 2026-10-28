<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCleanup;
use FooPlugins\FooGalleryMigrate\StorageDiscoverySummary;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

class StorageCleanupTest extends TestCase {
	private $db;
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_clear_1', 'value' => 'clear-nonce', 'result' => 1 );
		$GLOBALS['foogallery_migrate_test_cache_deletes'] = array();
		$GLOBALS['foogallery_migrate_test_deleted_transients'] = array();
		$GLOBALS['foogallery_migrate_test_transients'] = array();
		unset( $GLOBALS['foogallery_migrate_engine_instance'] );
		$this->db = new CleanupTestWpdb();
		$GLOBALS['wpdb'] = $this->db;
	}
	protected function tearDown(): void {
		unset( $GLOBALS['foogallery_migrate_test_nonce'], $GLOBALS['foogallery_migrate_test_capabilities'],
			$GLOBALS['foogallery_migrate_test_is_multisite'], $GLOBALS['foogallery_migrate_test_transients'] );
	}
	private function request( $action ) {
		return array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'clear' === $action ? 'state' : 'all',
			'confirmation' => 'clear' === $action ? 'clear-current-state' : 'remove-all-internal-data',
			'nonce' => 'clear-nonce' );
	}
	private function authorize( $action ) {
		$GLOBALS['foogallery_migrate_test_nonce']['action'] = 'foogallery_migrate_storage_' . $action . '_1';
	}
	public function test_remove_all_only_drops_current_tables_and_leaves_prototype_resources_alone(): void {
		$this->authorize( 'remove_all' );
		$ignored = array('wp_foogallery_migrate_objects'=>true, 'wp_foogallery_migrate_legacy_state'=>true, 'wp_foogallery_migrate_legacy_records'=>true);
		$this->db->tables = $ignored + array('wp_foogallery_migrate_state'=>true, 'wp_foogallery_migrate_records'=>true);
		$GLOBALS['foogallery_migrate_test_options'] = array('foogallery-migrate-store-schema-version'=>1, 'unrelated'=>'keep');
		$r = StorageCleanup::dispatch( 'remove_all', $this->request('remove_all') );
		$this->assertTrue($r['complete'],json_encode($r));
		$this->assertSame($ignored,$this->db->tables);
		$this->assertSame(array('foogallery-migrate-store-schema-version'=>1,'unrelated'=>'keep'),$GLOBALS['foogallery_migrate_test_options']);
	}
	public function test_clear_keeps_preferences_mode_and_output_but_drops_current_state_and_cache_only_transient(): void {
		$control = array( 'version' => 1, 'blog_id' => 1, 'backend' => 'option', 'epoch' => 1,
			'switch_id' => str_repeat( 'a', 32 ), 'from' => 'table', 'to' => 'option', 'phase' => 'stable',
			'table_id' => str_repeat( 'a', 32 ), 'source_digest' => str_repeat( 'b', 64 ),
			'logical_digest' => str_repeat( 'c', 64 ), 'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1 );
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::CONTROL_OPTION] = json_encode( $control );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = serialize( array( 'queue' => array( 8 ) ) );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_SETTINGS] = serialize( array( 'images_per_turn' => 100 ) );
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::STORAGE_BACKUP_OPTION] = 'stale';
		$GLOBALS['foogallery_migrate_test_options'][StorageDiscoverySummary::OPTION] = array( 'old' );
		$GLOBALS['foogallery_migrate_test_transients'][StorageCleanup::TRANSIENT_PREFIX . '17'] = array( 'old' );
		$this->db->users = array( 17 );
		$this->db->output = array( 41 => 'unchanged FooGallery', 42 => 'unchanged attachment' );
		$receipt = StorageCleanup::dispatch( 'clear', $this->request( 'clear' ) );
		$this->assertIsArray( $receipt ); $this->assertTrue( $receipt['complete'], json_encode( $receipt ) );
		$this->assertSame( serialize( array() ), $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] );
		$this->assertSame( serialize( array( 'images_per_turn' => 100 ) ), $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_SETTINGS] );
		$this->assertSame( json_encode( $control ), $GLOBALS['foogallery_migrate_test_options'][StorageAuthority::CONTROL_OPTION] );
		$this->assertArrayNotHasKey( StorageAuthority::STORAGE_BACKUP_OPTION, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertArrayNotHasKey( StorageDiscoverySummary::OPTION, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertContains( StorageDiscoverySummary::OPTION, $receipt['removed'] );
		$this->assertArrayNotHasKey( StorageCleanup::TRANSIENT_PREFIX . '17', $GLOBALS['foogallery_migrate_test_transients'] );
		$this->assertContains( StorageCleanup::TRANSIENT_PREFIX . '17', $GLOBALS['foogallery_migrate_test_deleted_transients'] );
		$this->assertArrayNotHasKey( StorageCleanup::MARKER, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( array( 41 => 'unchanged FooGallery', 42 => 'unchanged attachment' ), $this->db->output );
	}
	public function test_remove_all_partial_schema_failure_blocks_then_same_scope_finishes_idempotently(): void {
		$this->authorize( 'remove_all' );
		$this->db->tables = array( 'wp_foogallery_migrate_state' => true, 'wp_foogallery_migrate_records' => true );
		$this->db->output = array( 101 => 'source post', 202 => 'migrated attachment' );
		$this->db->fail_drop_once = 'wp_foogallery_migrate_records';
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'old history';
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_SETTINGS] = 'preferences';
		$GLOBALS['foogallery_migrate_test_options'][StorageDiscoverySummary::OPTION] = array( 'old' );
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::AUTHORITY_OPTION] = 'partial sentinel';
		$first = StorageCleanup::dispatch( 'remove_all', $this->request( 'remove_all' ) );
		$this->assertFalse( $first['complete'] );
		$this->assertArrayHasKey( StorageCleanup::MARKER, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertArrayHasKey( StorageAuthority::AUTHORITY_OPTION, $GLOBALS['foogallery_migrate_test_options'], 'Sentinel is last, not removed before tables.' );
		$this->assertArrayNotHasKey( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertArrayNotHasKey( StorageDiscoverySummary::OPTION, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertArrayHasKey( 'wp_foogallery_migrate_records', $this->db->tables );
		$this->assertContains( StorageCleanup::MARKER, $first['residual'] );
		$this->assertContains( StorageAuthority::AUTHORITY_OPTION, $first['residual'] );
		$this->assertContains( 'wp_foogallery_migrate_records', $first['residual'] );
		$second = StorageCleanup::dispatch( 'remove_all', $this->request( 'remove_all' ) );
		$this->assertTrue( $second['complete'], json_encode( $second ) );
		$this->assertSame( array(), $this->db->tables );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( array( 101 => 'source post', 202 => 'migrated attachment' ), $this->db->output );
		$third = StorageCleanup::dispatch( 'remove_all', $this->request( 'remove_all' ) );
		$this->assertTrue( $third['complete'], json_encode( $third ) );
	}
	public function test_invalid_authority_and_live_writer_refuse_before_any_cleanup_deletes(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $this->request( 'clear' ) ) );
		$this->assertSame( 0, $this->db->writes );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = true;
		$bad = $this->request( 'clear' ); $bad['nonce'] = 'bad';
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $bad ) );
		$this->assertSame( 0, $this->db->writes );
		$bad = $this->request( 'clear' ); $bad['confirmation'] = 'yes';
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $bad ) );
		$this->assertSame( 0, $this->db->writes );
		$GLOBALS['foogallery_migrate_test_options'][StorageMaintenanceGate::WRITER_PREFIX . str_repeat( 'a', 32 )] = 'ambiguous old worker';
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'source';
		$r = StorageCleanup::dispatch( 'clear', $this->request( 'clear' ) );
		$this->assertFalse( $r['complete'] );
		$this->assertSame( 'source', $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] );
		$this->assertArrayNotHasKey( StorageCleanup::MARKER, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertNotContains( StorageCleanup::MARKER, $r['residual'] );
		$this->assertContains( StorageMaintenanceGate::WRITER_PREFIX . '*', $r['residual'] );
		$this->assertSame( 1, $this->db->deletes, 'Only this request’s temporary maintenance claim was released.' );
	}
	public function test_schema_lock_blocks_remove_all_and_orphan_transient_pair_is_removed_on_safe_retry(): void {
		$this->authorize( 'remove_all' );
		$GLOBALS['foogallery_migrate_test_options'][\FooPlugins\FooGalleryMigrate\LegacyStorageSchema::LOCK_OPTION] = 'unknown schema owner';
		$blocked = StorageCleanup::dispatch( 'remove_all', $this->request( 'remove_all' ) );
		$this->assertFalse( $blocked['complete'] );
		$this->assertArrayHasKey( \FooPlugins\FooGalleryMigrate\LegacyStorageSchema::LOCK_OPTION, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertArrayNotHasKey( StorageCleanup::MARKER, $GLOBALS['foogallery_migrate_test_options'] );
		unset( $GLOBALS['foogallery_migrate_test_options'][\FooPlugins\FooGalleryMigrate\LegacyStorageSchema::LOCK_OPTION] );
		$key = StorageCleanup::TRANSIENT_PREFIX . '88';
		$GLOBALS['foogallery_migrate_test_options']['_transient_' . $key] = 'orphan';
		$GLOBALS['foogallery_migrate_test_options']['_transient_timeout_' . $key] = '1';
		$receipt = StorageCleanup::dispatch( 'remove_all', $this->request( 'remove_all' ) );
		$this->assertTrue( $receipt['complete'], json_encode( $receipt ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertContains( $key, $GLOBALS['foogallery_migrate_test_deleted_transients'] );
	}
	public function test_multisite_option_engine_clear_stays_on_its_prior_path_and_new_site_cleanup_refuses(): void {
		$GLOBALS['foogallery_migrate_test_is_multisite'] = true;
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'queue' => 'before' );
		$this->assertNull( StorageCleanup::pending_scope() );
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $this->request( 'clear' ) ) );
		$this->assertSame( 0, $this->db->writes, 'New single-site cleanup does not mutate multisite.' );
		$engine = new \FooPlugins\FooGalleryMigrate\MigratorEngine();
		$this->assertTrue( $engine->clear_migrator_setting() );
		$this->assertSame( array(), get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}
	public function test_uninstall_bootstrap_has_direct_safe_dependencies_and_no_vendor_or_admin_engine(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $source );
		$this->assertStringContainsString( "includes/class-storage-cleanup.php", $source );
		$this->assertStringContainsString( 'StorageCleanup::uninstall()', $source );
		$this->assertStringNotContainsString( 'vendor/autoload.php', $source );
		$this->assertStringNotContainsString( 'includes/functions.php', $source );
		$this->assertStringNotContainsString( 'class-migrator-engine.php', $source );
	}
}

/** SQL-shape fake for fixed-manifest cleanup only; no production fixture is touched. */
class CleanupTestWpdb extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $options = 'wp_options'; public $last_error = '';
	public $locks = array();
	public $users = array(); public $tables = array(); public $output = array();
	public $writes = 0; public $deletes = 0; public $fail_drop_once = null;
	public function __construct() {}
	public function is_current() { return true; }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, ...$values ) {
		if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
		foreach ( $values as $value ) { $sql = preg_replace_callback( '/%[sdi]/', function ( $match ) use ( $value ) {
			return '%i' === $match[0] ? '`' . $value . '`' : ( '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", $value ) . "'" );
		}, $sql, 1 ); }
		return $sql;
	}
	private function quoted( $sql ) { preg_match_all( "/'((?:''|[^'])*)'/", $sql, $m ); return array_map( function ( $v ) { return str_replace( "''", "'", $v ); }, $m[1] ); }
	private function option( $sql ) { preg_match( "/option_name\\s*=\\s*'([^']+)'/", $sql, $m ); return isset( $m[1] ) ? $m[1] : null; }
	public function get_var( $sql ) {
		$this->last_error = '';
		if ( 'SELECT DATABASE()' === $sql ) { return 'test_database'; }
		if ( preg_match( "/^SELECT (GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK)\\('([^']+)'/", $sql, $lock ) ) {
			if ( ! isset( $this->locks ) ) { $this->locks = array(); }
			if ( 'GET_LOCK' === $lock[1] ) { $this->locks[$lock[2]] = true; return '1'; }
			if ( 'RELEASE_LOCK' === $lock[1] ) { $held = isset( $this->locks[$lock[2]] ); unset( $this->locks[$lock[2]] ); return $held ? '1' : null; }
			return ( 'IS_FREE_LOCK' === $lock[1] ? ! isset( $this->locks[$lock[2]] ) : isset( $this->locks[$lock[2]] ) ) ? '1' : '0';
		}
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			$name = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $this->quoted( $sql )[0] );
			return isset( $this->tables[$name] ) ? $name : null;
		}
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return '0'; }
		if ( false !== strpos( $sql, 'option_name IN' ) ) {
			foreach ( $this->quoted( $sql ) as $name ) { if ( array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] ) ) { return '1'; } }
			return null;
		}
		if ( false !== strpos( $sql, 'option_name LIKE' ) ) {
			$plain = str_replace( '\\', '', $sql );
			if ( false !== strpos( $plain, StorageMaintenanceGate::WRITER_PREFIX ) ) {
				foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) { if ( 0 === strpos( $name, StorageMaintenanceGate::WRITER_PREFIX ) ) { return '1'; } }
			}
			if ( false !== strpos( $plain, StorageCleanup::TRANSIENT_PREFIX ) ) {
				foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) {
					if ( 0 === strpos( $name, '_transient_' . StorageCleanup::TRANSIENT_PREFIX ) ||
						0 === strpos( $name, '_transient_timeout_' . StorageCleanup::TRANSIENT_PREFIX ) ) { return '1'; }
				}
			}
			return null;
		}
		$name = $this->option( $sql );
		if ( null === $name || ! array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] ) ) { return null; }
		$raw = $GLOBALS['foogallery_migrate_test_options'][$name];
		if ( false !== strpos( $sql, 'SHA2(' ) ) { return hash( 'sha256', $raw ); }
		if ( false !== strpos( $sql, "CONCAT('v:'" ) ) { return 'v:' . $raw; }
		return false !== strpos( $sql, 'option_value' ) ? $raw : '1';
	}
	public function get_results( $sql, $format = null ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'AS marker_value' ) ) {
			$rows = array();
			foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) {
				if ( 0 === strpos( $name, StorageMaintenanceGate::WRITER_PREFIX ) ) { $rows[] = array( 'option_name' => $name, 'marker_value' => strlen( $raw ) <= 512 ? $raw : '' ); }
			}
			usort( $rows, function ( $a, $b ) { return strcmp( $a['option_name'], $b['option_name'] ); } );
			return array_slice( $rows, 0, 101 );
		}
		if ( false !== strpos( $sql, 'FROM `wp_users`' ) ) {
			preg_match( '/ID > ([0-9]+)/', $sql, $m ); $cursor = (int) $m[1]; $rows = array();
			foreach ( $this->users as $id ) { if ( $id > $cursor && count( $rows ) < 100 ) { $rows[] = array( 'ID' => (string) $id ); } }
			return $rows;
		}
		if ( false !== strpos( $sql, 'SELECT option_name' ) ) {
			$rows = array();
			foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) {
				if ( 0 === strpos( $name, '_transient_' . StorageCleanup::TRANSIENT_PREFIX ) ||
					0 === strpos( $name, '_transient_timeout_' . StorageCleanup::TRANSIENT_PREFIX ) ) { $rows[] = array( 'option_name' => $name ); }
			}
			sort( $rows ); return array_slice( $rows, 0, 101 );
		}
		throw new \RuntimeException( 'Unexpected cleanup result query.' );
	}
	public function query( $sql ) {
		$this->last_error = ''; ++$this->writes;
		if ( preg_match( '/^DROP TABLE `([^`]+)`/', $sql, $m ) ) {
			if ( $this->fail_drop_once === $m[1] ) { $this->fail_drop_once = null; $this->last_error = 'injected DDL failure'; return false; }
			unset( $this->tables[$m[1]] ); ++$this->deletes; return 1;
		}
		if ( preg_match( '/^DELETE FROM `wp_foogallery_migrate_/', $sql ) ) { ++$this->deletes; return 0; }
		if ( 0 === strpos( $sql, 'INSERT' ) ) {
			$q = $this->quoted( $sql ); if ( array_key_exists( $q[0], $GLOBALS['foogallery_migrate_test_options'] ) ) { return 0; }
			$GLOBALS['foogallery_migrate_test_options'][$q[0]] = $q[1]; return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			$q = $this->quoted( $sql ); $name = $this->option( $sql );
			if ( null === $name || ! array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] ) ) { return 0; }
			if ( false !== strpos( $sql, 'BINARY option_value' ) && end( $q ) !== $GLOBALS['foogallery_migrate_test_options'][$name] ) { return 0; }
			$GLOBALS['foogallery_migrate_test_options'][$name] = $q[0]; return 1;
		}
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$name = $this->option( $sql ); if ( null === $name || ! array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] ) ) { return 0; }
			$q = $this->quoted( $sql ); if ( false !== strpos( $sql, 'BINARY option_value' ) && end( $q ) !== $GLOBALS['foogallery_migrate_test_options'][$name] ) { return 0; }
			unset( $GLOBALS['foogallery_migrate_test_options'][$name] ); ++$this->deletes; return 1;
		}
		throw new \RuntimeException( 'Unexpected cleanup write query.' );
	}
}
