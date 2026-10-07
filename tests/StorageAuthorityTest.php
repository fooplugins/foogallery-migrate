<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

class StorageAuthorityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_dbdelta'] = array();
		$GLOBALS['wpdb'] = new AuthorityTestWpdb();
	}

	public function test_virgin_site_uses_options_without_writing_a_control_or_table(): void {
		$this->assertFalse( StorageAuthority::option_mode_error() );
		$engine = new MigratorEngine();
		$this->assertTrue( $engine->set_migrator_setting( 'custom', array( 'retained' => true ) ) );
		$this->assertSame( array( 'custom' => array( 'retained' => true ) ), get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertFalse( get_option( StorageAuthority::CONTROL_OPTION ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	public function test_any_existing_plugin_table_blocks_missing_control_instead_of_falling_back(): void {
		foreach ( array( 'foogallery_migrate_objects', 'foogallery_migrate_state' ) as $suffix ) {
			$GLOBALS['wpdb']->existing = 'wp_' . $suffix;
			$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error() );
			$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		}
	}

	public function test_old_installation_markers_block_even_when_a_table_is_missing(): void {
		update_option( MigratedStore::MIGRATION_OPTION, 1 );
		$error = StorageAuthority::option_mode_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $error->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	public function test_control_states_are_not_silently_treated_as_option_mode(): void {
		foreach ( array( array( 'mode' => 'table' ), array( 'mode' => 'converting' ), array( 'version' => 999 ), 'corrupt', false, array() ) as $control ) {
			update_option( StorageAuthority::CONTROL_OPTION, $control );
			$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error() );
		}
	}

	public function test_database_probe_failure_blocks_without_installing_or_mutating(): void {
		$GLOBALS['wpdb']->fail_probe = true;
		$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error() );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_dbdelta'] );
	}

	public function test_default_engine_refuses_to_mutate_when_authority_is_ambiguous(): void {
		update_option( MigratedStore::SCHEMA_OPTION, 1 );
		$before = $GLOBALS['foogallery_migrate_test_options'];
		try {
			new MigratorEngine();
			$this->fail( 'Engine construction must refuse uncertain authority.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'recovery', $error->getMessage() );
		}
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}

	public function test_authority_probes_use_the_current_site_prefix_and_exact_names(): void {
		$GLOBALS['wpdb']->prefix = 'wp_7_';
		$this->assertFalse( StorageAuthority::option_mode_error() );
		$this->assertCount( 4, $GLOBALS['wpdb']->reads );
		$this->assertStringContainsString( 'wp\\_7\\_foogallery\\_migrate\\_objects', $GLOBALS['wpdb']->reads[0] );
		$this->assertStringContainsString( 'wp\\_7\\_foogallery\\_migrate\\_state', $GLOBALS['wpdb']->reads[1] );
		$this->assertStringContainsString( 'wp\\_foogallery\\_migrate\\_legacy\\_state', $GLOBALS['wpdb']->reads[2] );
		$this->assertStringContainsString( 'wp\\_foogallery\\_migrate\\_legacy\\_records', $GLOBALS['wpdb']->reads[3] );
	}

	public function test_new_control_sentinel_and_pending_switch_markers_block_options(): void {
		foreach ( array( 'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_maintenance' ) as $name ) {
			foreach ( array( false, null, array(), array( 'active_backend' => 'table' ) ) as $value ) {
				$GLOBALS['foogallery_migrate_test_options'] = array( $name => $value );
				$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error(), $name );
			}
		}
	}

	public function test_shared_tables_without_this_sites_rows_do_not_adopt_table_authority(): void {
		$GLOBALS['wpdb']->existing = 'wp_foogallery_migrate_legacy_state';
		$this->assertFalse( StorageAuthority::option_mode_error() );
		$this->assertStringContainsString( 'SELECT 1 AS present FROM', $GLOBALS['wpdb']->reads[3] );
		$this->assertStringContainsString( 'WHERE blog_id = 1 LIMIT 1', $GLOBALS['wpdb']->reads[3] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
	}

	public function test_shared_state_or_orphan_records_for_this_site_require_recovery(): void {
		foreach ( array( 'wp_foogallery_migrate_legacy_state', 'wp_foogallery_migrate_legacy_records' ) as $table ) {
			$GLOBALS['wpdb']->existing = $table;
			$GLOBALS['wpdb']->site_rows = true;
			$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error() );
		}
	}
}

class AuthorityTestWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $existing = '';
	public $site_rows = false;
	public $last_error = '';
	public $fail_probe = false;
	public $reads = array();
	public $writes = array();

	public function esc_like( $value ) {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( $query, $value ) {
		return false !== strpos( $query, '%d' ) ? str_replace( '%d', (string) (int) $value, $query ) : str_replace( '%s', "'" . $value . "'", $query );
	}

	public function get_var( $query ) {
		$this->reads[] = $query;
		$this->last_error = $this->fail_probe ? 'probe failed' : '';
		if ( 0 === strpos( $query, 'SELECT 1' ) ) {
			// PHP 5.4 mysqlnd object results lose an unaliased numeric column.
			return $this->site_rows && 0 === strpos( $query, 'SELECT 1 AS present FROM' ) ? '1' : null;
		}
		return false !== strpos( stripslashes( $query ), $this->existing ) && '' !== $this->existing ? $this->existing : null;
	}

	public function query( $query ) {
		$this->writes[] = $query;
		return 1;
	}
}
