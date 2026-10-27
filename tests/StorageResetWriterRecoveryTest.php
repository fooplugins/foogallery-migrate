<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCleanup;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageCleanupTest.php';

/** Reset's explicit expired-admission recovery; ordinary maintenance never reclaims. */
class StorageResetWriterRecoveryTest extends TestCase {
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
		$this->db = new ResetWriterTestWpdb(); $GLOBALS['wpdb'] = $this->db;
	}
	protected function tearDown(): void {
		unset( $GLOBALS['foogallery_migrate_test_nonce'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_is_multisite'], $GLOBALS['foogallery_migrate_test_transients'], $GLOBALS['foogallery_migrate_engine_instance'] );
	}
	private function writer( $token = 'a', $expiry = null ): array {
		$record = array( 'version' => 1, 'blog_id' => 1, 'token' => str_repeat( $token, 32 ), 'expires' => null === $expiry ? time() - 600 : $expiry );
		$name = StorageMaintenanceGate::WRITER_PREFIX . $record['token'];
		$GLOBALS['foogallery_migrate_test_options'][$name] = wp_json_encode( $record );
		return array( $name, $record );
	}
	private function request(): array { return array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'state', 'confirmation' => 'clear-current-state', 'nonce' => 'clear-nonce' ); }
	public function test_existing_reset_clears_three_expired_markers_and_history_preserving_preferences_and_output(): void {
		$names = array(); foreach ( array( 'a', 'b', 'c' ) as $token ) { $names[] = $this->writer( $token )[0]; }
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'old history';
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_SETTINGS] = 'preferences';
		$GLOBALS['foogallery_migrate_test_options']['unrelated_option'] = 'keep';
		$this->db->output = array( 17 => 'gallery', 23 => 'media' );
		$result = StorageCleanup::dispatch( 'clear', $this->request() );
		$this->assertTrue( $result['complete'], json_encode( $result ) );
		foreach ( $names as $name ) {
			$this->assertArrayNotHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
			$this->assertContains( $name, $result['removed'] );
			$this->assertContains( array( $name, 'options' ), $GLOBALS['foogallery_migrate_test_cache_deletes'] );
		}
		$this->assertSame( 'preferences', get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertSame( serialize( array() ), $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] );
		$this->assertSame( array( 17 => 'gallery', 23 => 'media' ), $this->db->output );
		$this->assertTrue( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
	}
	/** @dataProvider invalidWriters */
	public function test_ambiguous_or_live_markers_keep_all_writers_and_history( $change ): void {
		$this->writer(); list( $name, $record ) = $this->writer( 'b' );
		if ( 'live' === $change ) { $record['expires'] = time() + 300; }
		elseif ( 'foreign' === $change ) { $record['blog_id'] = 2; }
		elseif ( 'version' === $change ) { $record['version'] = 2; }
		elseif ( 'token' === $change ) { $record['token'] = str_repeat( 'c', 32 ); }
		elseif ( 'expiry-type' === $change ) { $record['expires'] = '1'; }
		elseif ( 'expiry-zero' === $change ) { $record['expires'] = 0; }
		elseif ( 'extra' === $change ) { $record['extra'] = true; }
		$raw = wp_json_encode( $record );
		if ( 'malformed' === $change ) { $raw = 'broken'; }
		elseif ( 'oversized' === $change ) { $raw = str_repeat( 'x', 513 ); }
		elseif ( 'noncanonical' === $change ) { $raw .= ' '; }
		$GLOBALS['foogallery_migrate_test_options'][$name] = $raw;
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'history';
		$before = $GLOBALS['foogallery_migrate_test_options'];
		$r = StorageCleanup::dispatch( 'clear', $this->request() );
		$this->assertFalse( $r['complete'] );
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public static function invalidWriters(): array { return array_map( function ( $s ) { return array( $s ); }, array( 'live', 'foreign', 'version', 'token', 'expiry-type', 'expiry-zero', 'extra', 'malformed', 'oversized', 'noncanonical' ) ); }
	public function test_recovery_requires_an_owned_gate_and_two_strict_quiescent_verdicts(): void {
		list( $name ) = $this->writer(); $gate = StorageMaintenanceGate::current();
		$this->assertInstanceOf( \WP_Error::class, $gate->reset_expired_writers( function () { return true; } ) );
		$this->assertTrue( $gate->acquire_maintenance() );
		foreach ( array( false, null, 1 ) as $verdict ) { $this->assertInstanceOf( \WP_Error::class, $gate->reset_expired_writers( function () use ( $verdict ) { return $verdict; } ) ); }
		$calls = 0;
		$this->assertInstanceOf( \WP_Error::class, $gate->reset_expired_writers( function () use ( &$calls ) { return ++$calls === 1; } ) );
		$this->assertSame( 2, $calls ); $this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertTrue( $gate->release_maintenance() );
	}
	public function test_read_error_and_oversized_set_refuse_without_deleting_any_writer(): void {
		for ( $i = 0; $i < 101; ++$i ) { $token = str_pad( (string) $i, 32, 'a' ); $r = array( 'version'=>1, 'blog_id'=>1, 'token'=>$token, 'expires'=>1 ); $GLOBALS['foogallery_migrate_test_options'][StorageMaintenanceGate::WRITER_PREFIX.$token] = wp_json_encode( $r ); }
		$before = $GLOBALS['foogallery_migrate_test_options'];
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
		$this->db->fail_enumeration = true;
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_changed_marker_cannot_be_deleted_using_an_earlier_expired_value(): void {
		list( $name, $record ) = $this->writer(); $record['expires'] = time() + 300; $fresh = wp_json_encode( $record );
		$this->db->before_writer_delete = function () use ( $name, $fresh ) { $GLOBALS['foogallery_migrate_test_options'][$name] = $fresh; };
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'history';
		$r = StorageCleanup::dispatch( 'clear', $this->request() );
		$this->assertFalse( $r['complete'] ); $this->assertSame( $fresh, get_option( $name ) );
		$this->assertSame( 'history', get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}
	public function test_a_partial_marker_recovery_reports_only_confirmed_removals_and_keeps_history(): void {
		list( $first ) = $this->writer(); list( $second, $record ) = $this->writer( 'b' );
		$record['expires'] = time() + 300; $fresh = wp_json_encode( $record );
		$this->db->before_writer_delete = function ( $sql ) use ( $second, $fresh ) { if ( false !== strpos( $sql, $second ) ) { $GLOBALS['foogallery_migrate_test_options'][$second] = $fresh; } };
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'history';
		$r = StorageCleanup::dispatch( 'clear', $this->request() );
		$this->assertFalse( $r['complete'] ); $this->assertContains( $first, $r['removed'] ); $this->assertNotContains( $second, $r['removed'] );
		$this->assertArrayNotHasKey( $first, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( $fresh, get_option( $second ) ); $this->assertSame( 'history', get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( array(), $this->db->locks );
	}
	public function test_ordinary_maintenance_and_remove_all_do_not_reclaim_expired_writers(): void {
		list( $name ) = $this->writer(); $gate = StorageMaintenanceGate::current();
		$this->assertTrue( $gate->acquire_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $gate->maintenance_ready( function () { return true; } ) );
		$this->assertTrue( $gate->release_maintenance() );
		$GLOBALS['foogallery_migrate_test_nonce']['action'] = 'foogallery_migrate_storage_remove_all_1';
		$request = $this->request(); $request['target'] = 'all'; $request['confirmation'] = 'remove-all-internal-data';
		$this->assertFalse( StorageCleanup::dispatch( 'remove_all', $request )['complete'] );
		$this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_same_request_writer_is_not_reclaimed_even_if_its_saved_lease_expired(): void {
		$gate = StorageMaintenanceGate::current(); $this->assertTrue( $gate->begin_writer() );
		$r = new \ReflectionClass( $gate ); $raw = $r->getProperty( 'writer_raw' )->getValue( $gate ); $record = json_decode( $raw, true ); $record['expires'] = 1;
		$name = StorageMaintenanceGate::WRITER_PREFIX . $record['token']; $expired = wp_json_encode( $record );
		$r->getProperty( 'writer_raw' )->setValue( $gate, $expired ); $GLOBALS['foogallery_migrate_test_options'][$name] = $expired;
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertInstanceOf( \WP_Error::class, $gate->assert_writer() );
		$this->assertTrue( $gate->end_writer() );
	}
	public function test_an_expired_marker_with_a_live_connection_lock_is_not_reclaimed(): void {
		list( $name ) = $this->writer();
		$lock = 'foogm-write-' . substr( hash( 'sha256', 'test_database:wp_options:' . $name ), 0, 48 );
		$this->db->locks[$lock] = true;
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = 'history';
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( 'history', get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		unset( $this->db->locks[$lock] );
		$this->assertTrue( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
	}
	public function test_lost_writer_connection_lock_refuses_later_mutations(): void {
		$gate = StorageMaintenanceGate::current(); $this->assertTrue( $gate->begin_writer() );
		$this->db->locks = array();
		$this->assertInstanceOf( \WP_Error::class, $gate->assert_writer() );
		$this->assertInstanceOf( \WP_Error::class, $gate->renew_writer() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_lock_probe_failure_refuses_reset_without_deleting_history_or_markers(): void {
		list( $name ) = $this->writer(); $this->db->fail_lock_probe = true;
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_unauthorized_or_schema_locked_reset_never_reclaims_a_writer(): void {
		list( $name ) = $this->writer(); $raw = get_option( $name );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $this->request() ) );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = true;
		$r = $this->request(); $r['nonce'] = 'invalid';
		$this->assertInstanceOf( \WP_Error::class, StorageCleanup::dispatch( 'clear', $r ) );
		$GLOBALS['foogallery_migrate_test_options'][\FooPlugins\FooGalleryMigrate\LegacyStorageSchema::LOCK_OPTION] = 'schema-owner';
		$this->assertFalse( StorageCleanup::dispatch( 'clear', $this->request() )['complete'] );
		$this->assertSame( $raw, get_option( $name ) );
	}
}

class ResetWriterTestWpdb extends CleanupTestWpdb {
	public $fail_enumeration = false;
	public $fail_lock_probe = false;
	public function get_var( $sql ) {
		if ( $this->fail_lock_probe && false !== strpos( $sql, "IS_FREE_LOCK" ) ) { $this->last_error = "failed liveness probe"; return null; }
		return parent::get_var( $sql );
	}
	public $before_writer_delete;
	public function get_results( $sql, $format = null ) {
		if ( $this->fail_enumeration && false !== strpos( $sql, 'AS marker_value' ) ) { $this->last_error = 'failed marker enumeration'; return null; }
		return parent::get_results( $sql, $format );
	}
	public function query( $sql ) {
		if ( 0 === strpos( $sql, 'DELETE' ) && false !== strpos( $sql, StorageMaintenanceGate::WRITER_PREFIX ) && is_callable( $this->before_writer_delete ) ) { call_user_func( $this->before_writer_delete, $sql ); }
		return parent::query( $sql );
	}
}
