<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

class StorageMaintenanceGateTest extends TestCase {
	private $gate;

	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_cache_deletes'] = array();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$this->gate = StorageMaintenanceGate::current();
	}

	private function marker() {
		foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $value ) {
			if ( 0 === strpos( $name, StorageMaintenanceGate::WRITER_PREFIX ) ) { return $name; }
		}
		$this->fail( 'Expected an owned writer marker.' );
	}

	public function test_request_scope_is_reentrant_and_only_outer_release_removes_marker(): void {
		$this->assertSame( $this->gate, StorageMaintenanceGate::current() );
		$this->assertTrue( $this->gate->begin_writer() );
		$name = $this->marker();
		$this->assertTrue( $this->gate->begin_writer() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertTrue( $this->gate->end_writer() );
		$this->assertArrayHasKey( $name, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertTrue( $this->gate->end_writer() );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertContains( array( $name, 'options' ), $GLOBALS['foogallery_migrate_test_cache_deletes'] );
	}

	public function test_every_existing_gate_blocks_new_writers_including_expired_or_corrupt(): void {
		foreach ( array( '', 'false', str_repeat( 'x', 513 ), '{"expires":1}' ) as $value ) {
			$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = $value;
			$this->assertInstanceOf( \WP_Error::class, $this->gate->begin_writer() );
			$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_options'] );
		}
	}

	public function test_gate_race_after_first_check_removes_own_marker_and_refuses_writer(): void {
		$GLOBALS['wpdb']->after_read = function ( $query ) {
			if ( false !== strpos( $query, StorageAuthority::MAINTENANCE_OPTION ) ) {
				$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'racing-owner';
				$GLOBALS['wpdb']->after_read = null;
			}
		};
		$this->assertInstanceOf( \WP_Error::class, $this->gate->begin_writer() );
		$this->assertSame( array( StorageAuthority::MAINTENANCE_OPTION => 'racing-owner' ), $GLOBALS['foogallery_migrate_test_options'] );
	}

	public function test_preexisting_writer_can_finish_but_maintenance_waits_without_calling_engine(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$calls = 0;
		$verdict = function () use ( &$calls ) { $calls++; return true; };
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( $verdict ) );
		$this->assertSame( 0, $calls );
		$this->assertTrue( $this->gate->assert_writer() );
		$this->assertTrue( $this->gate->end_writer() );
		$this->assertTrue( $this->gate->maintenance_ready( $verdict ) );
		$this->assertSame( 2, $calls );
		$this->assertTrue( $this->gate->release_maintenance() );
	}

	public function test_expired_foreign_marker_is_recovery_evidence_not_permission_to_delete(): void {
		$name = StorageMaintenanceGate::WRITER_PREFIX . str_repeat( 'a', 32 );
		$GLOBALS['foogallery_migrate_test_options'][$name] = '{"expires":1}';
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () { return true; } ) );
		$this->assertSame( '{"expires":1}', get_option( $name ) );
	}

	public function test_quiescence_requires_two_strict_affirmative_verdicts_and_catches_exception(): void {
		$this->assertTrue( $this->gate->acquire_maintenance() );
		foreach ( array( null, false, 1, 'yes' ) as $answer ) {
			$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () use ( $answer ) { return $answer; } ) );
		}
		$calls = 0;
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () use ( &$calls ) { return ++$calls === 1; } ) );
		$this->assertSame( 2, $calls );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () { throw new \RuntimeException( 'private SQL or payload' ); } ) );
		$this->assertTrue( $this->gate->release_maintenance() );
	}

	public function test_read_failure_blocks_acquisition_without_mutation(): void {
		$GLOBALS['wpdb']->fail_read = true;
		$this->assertInstanceOf( \WP_Error::class, $this->gate->begin_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->acquire_maintenance() );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
	}

	public function test_add_failure_never_grants_writer_or_maintenance_ownership(): void {
		$GLOBALS['wpdb']->fail_write = true;
		$this->assertInstanceOf( \WP_Error::class, $this->gate->acquire_maintenance() );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->assert_writer() );
	}

	public function test_exact_raw_ownership_cas_prevents_releasing_or_renewing_replaced_markers(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$name = $this->marker();
		$GLOBALS['foogallery_migrate_test_options'][$name] = 'replaced';
		$this->assertInstanceOf( \WP_Error::class, $this->gate->end_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->renew_writer() );
		$this->assertSame( 'replaced', get_option( $name ) );
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'different-owner';
		$this->assertInstanceOf( \WP_Error::class, $this->gate->release_maintenance() );
		$this->assertSame( 'different-owner', get_option( StorageAuthority::MAINTENANCE_OPTION ) );
	}

	public function test_release_and_renewal_failures_preserve_evidence_and_can_retry(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$before = $GLOBALS['foogallery_migrate_test_options'];
		$GLOBALS['wpdb']->fail_write = true;
		$this->assertInstanceOf( \WP_Error::class, $this->gate->end_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->renew_writer() );
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
		$GLOBALS['wpdb']->fail_write = false;
		$this->assertTrue( $this->gate->renew_writer() );
		$this->assertTrue( $this->gate->end_writer() );
	}

	public function test_context_switch_refuses_cached_handles_and_restored_context_can_finish(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 2;
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		$this->assertInstanceOf( \WP_Error::class, $this->gate->assert_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->end_writer() );
		$this->assertNotSame( $this->gate, StorageMaintenanceGate::current() );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['wpdb']->prefix = 'wp_';
		$this->assertTrue( $this->gate->end_writer() );
	}

	public function test_shutdown_release_is_owner_checked_and_does_not_release_maintenance(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$this->assertTrue( $this->gate->begin_writer() );
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$this->gate->shutdown_writer();
		$this->assertSame( array( StorageAuthority::MAINTENANCE_OPTION ), array_keys( $GLOBALS['foogallery_migrate_test_options'] ) );
		$this->assertTrue( $this->gate->release_maintenance() );
	}

	public function test_gate_is_rechecked_after_engine_callback(): void {
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () {
			$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'stolen';
			return true;
		} ) );
	}

	public function test_failed_post_insertion_verification_cannot_become_a_reentrant_admission(): void {
		$GLOBALS['wpdb']->after_read = function ( $query ) {
			if ( false !== strpos( $query, StorageMaintenanceGate::WRITER_PREFIX ) ) {
				$GLOBALS['wpdb']->last_error = 'post-insertion verification failed';
				$GLOBALS['wpdb']->after_read = null;
			}
		};
		$this->assertInstanceOf( \WP_Error::class, $this->gate->begin_writer() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_options'] );
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'later gate';
		$this->assertInstanceOf( \WP_Error::class, $this->gate->begin_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->assert_writer() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->renew_writer() );
		$this->assertTrue( $this->gate->end_writer() );
		$this->assertSame( array( StorageAuthority::MAINTENANCE_OPTION => 'later gate' ), $GLOBALS['foogallery_migrate_test_options'] );
	}

	public function test_maintenance_renewal_and_release_failure_retain_owned_gate_for_retry(): void {
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$this->assertTrue( $this->gate->renew_maintenance() );
		$GLOBALS['wpdb']->fail_write = true;
		$this->assertInstanceOf( \WP_Error::class, $this->gate->renew_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->release_maintenance() );
		$this->assertArrayHasKey( StorageAuthority::MAINTENANCE_OPTION, $GLOBALS['foogallery_migrate_test_options'] );
		$GLOBALS['wpdb']->fail_write = false;
		$this->assertTrue( $this->gate->release_maintenance() );
	}

	public function test_same_value_cas_is_not_a_false_failure_and_sql_is_binary_exact(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$this->assertTrue( $this->gate->renew_writer() );
		$this->assertTrue( $this->gate->end_writer() );
		$this->assertStringContainsString( 'BINARY option_value = BINARY', implode( "\n", $GLOBALS['wpdb']->queries ) );
		$this->assertStringContainsString( 'LENGTH(option_value) <= 512', implode( "\n", $GLOBALS['wpdb']->queries ) );
	}

	public function test_insert_collision_never_overwrites_competing_gate_owner(): void {
		$GLOBALS['wpdb']->before_write = function ( $query ) {
			if ( 0 === strpos( $query, 'INSERT' ) ) {
				$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'racing gate owner';
			}
		};
		$this->assertInstanceOf( \WP_Error::class, $this->gate->acquire_maintenance() );
		$this->assertSame( 'racing gate owner', get_option( StorageAuthority::MAINTENANCE_OPTION ) );
	}

	public function test_successful_renewal_then_failed_read_retains_current_raw_owner_for_release(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		// Make renewal change the raw expiry, not only exercise a same-second no-op.
		sleep( 1 );
		$GLOBALS['wpdb']->before_write = function ( $query ) {
			if ( 0 === strpos( $query, 'UPDATE' ) ) {
				$GLOBALS['wpdb']->fail_read = true;
			}
		};
		$this->assertInstanceOf( \WP_Error::class, $this->gate->renew_writer() );
		$GLOBALS['wpdb']->fail_read = false;
		$GLOBALS['wpdb']->before_write = null;
		$this->assertTrue( $this->gate->end_writer() );
	}

	public function test_engine_verdict_that_changes_blog_context_cannot_prove_readiness(): void {
		$this->assertTrue( $this->gate->acquire_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $this->gate->maintenance_ready( function () {
			$GLOBALS['foogallery_migrate_test_blog_id'] = 2;
			$GLOBALS['wpdb']->prefix = 'wp_2_';
			return true;
		} ) );
	}

	public function test_shutdown_temporarily_restores_owned_blog_and_returns_to_exit_context(): void {
		$this->assertTrue( $this->gate->begin_writer() );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 2;
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		$this->gate->shutdown_writer();
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( 2, get_current_blog_id() );
		$this->assertSame( 'wp_2_', $GLOBALS['wpdb']->prefix );
	}
}

class MaintenanceTestWpdb extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public $current = true;
	public function __construct() {}
	public function is_current() { return $this->current; }
	public $prefix = 'wp_';
	public $last_error = '';
	public $fail_read = false;
	public $fail_write = false;
	public $after_read;
	public $before_write;
	public $queries = array();

	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $query, ...$values ) {
		if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
		foreach ( $values as $value ) {
			$query = preg_replace_callback( '/%[sd]/', function ( $match ) use ( $value ) {
				return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", $value ) . "'";
			}, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		$this->queries[] = $query;
		$this->last_error = $this->fail_read ? 'private read error' : '';
		$value = null;
		if ( false !== strpos( $query, 'option_name IN' ) ) {
			preg_match_all( "/'([^']+)'/", $query, $names );
			foreach ( $names[1] as $name ) {
				if ( array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] ) ) { return '1'; }
			}
			return null;
		}
		if ( preg_match( "/option_name = '([^']+)'/", $query, $match ) ) {
			if ( array_key_exists( $match[1], $GLOBALS['foogallery_migrate_test_options'] ) ) {
				$value = $GLOBALS['foogallery_migrate_test_options'][$match[1]];
				$value = strlen( $value ) <= 512 ? $value : '';
				$value = false !== strpos( $query, "CONCAT('v:'" ) ? 'v:' . $value : ( '' === $value ? null : $value );
			}
		} elseif ( false !== strpos( $query, 'option_name LIKE' ) ) {
			foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) {
				if ( 0 === strpos( $name, StorageMaintenanceGate::WRITER_PREFIX ) ) { $value = '1'; break; }
			}
		}
		if ( is_callable( $this->after_read ) ) { call_user_func( $this->after_read, $query ); }
		return $value;
	}
	public function query( $query ) {
		$this->queries[] = $query;
		if ( is_callable( $this->before_write ) ) { call_user_func( $this->before_write, $query ); }
		$this->last_error = $this->fail_write ? 'private write error' : '';
		if ( $this->fail_write ) { return false; }
		if ( 0 === strpos( $query, 'INSERT' ) ) {
			if ( ! preg_match( "/VALUES \\( '([^']+)', '([^']*)', 'no' \\)/", $query, $insert ) ) { throw new \RuntimeException( 'Unexpected insert SQL.' ); }
			if ( array_key_exists( $insert[1], $GLOBALS['foogallery_migrate_test_options'] ) ) { return 0; }
			$GLOBALS['foogallery_migrate_test_options'][$insert[1]] = $insert[2];
			return 1;
		}
		if ( ! preg_match( "/option_name = '([^']+)' AND BINARY option_value = BINARY '([^']*)'/", $query, $match ) ) { throw new \RuntimeException( 'Unexpected CAS SQL.' ); }
		if ( ! isset( $GLOBALS['foogallery_migrate_test_options'][$match[1]] ) || $GLOBALS['foogallery_migrate_test_options'][$match[1]] !== $match[2] ) { return 0; }
		if ( 0 === strpos( $query, 'DELETE' ) ) { unset( $GLOBALS['foogallery_migrate_test_options'][$match[1]] ); return 1; }
		preg_match( "/SET option_value = '([^']*)'/", $query, $next );
		$changed = $next[1] !== $match[2];
		$GLOBALS['foogallery_migrate_test_options'][$match[1]] = $next[1];
		return $changed ? 1 : 0;
	}
}
