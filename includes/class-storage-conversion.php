<?php
/**
 * Explicit, bounded synchronous conversion coordinator. No hooks, UI, implicit
 * provisioning, default decode calibration, or expired-owner takeover.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageConversion {
	private $db;
	private $blog;
	private $prefix;
	private $policy;
	private $gate;
	private $owns_gate = false;
	private $controls;
	private $connection;
	private $snapshot;
	private $codec;
	private $deadline;
	private $incoming_memory;
	private $memory;

	/** Policy must come from trusted runtime calibration, never request fields. */
	public function __construct( $policy = null, $memory = null ) {
		global $wpdb;
		$this->db = $wpdb; $this->blog = get_current_blog_id(); $this->prefix = $wpdb->prefix;
		$this->policy = $policy; $this->controls = new StorageControl( $wpdb, $this->blog );
		$this->incoming_memory = $memory;
		$this->codec = new LegacyStateCodec();
	}

	/** Switch only from a validated stable backend; no source/destination merge. */
	public function switch_backend( $request ) {
		return $this->switch_storage( $request, false );
	}

	/** Explicitly replace a verified v2 reverse's inactive generations/backup. */
	public function replace_retained( $request ) {
		return $this->switch_storage( $request, true );
	}

	private function switch_storage( $request, $replacement ) {
		$this->start_memory();
		$authorized = self::authorize_request( $request, false, $this->blog, $replacement );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		$budget = StoragePreflight::decode_budget( 0, ini_get( 'memory_limit' ), $this->memory->sample(), $this->policy );
		if ( is_wp_error( $budget ) ) { return $budget; }
		try {
			$this->begin();
			$raw = $this->controls->read( StorageAuthority::CONTROL_OPTION );
			$previous = null === $raw ? null : StorageControl::decode( $raw, $this->blog );
			$sentinel = $this->controls->read( StorageAuthority::AUTHORITY_OPTION );
			if ( null === $previous ) {
				$this->require_true( ! is_wp_error( StorageAuthority::option_mode_error( $this->gate, true ) ) );
			} else { $this->require_true( StorageControl::runtime_ready( $previous, $sentinel ) ); }
			$from = null === $previous ? 'option' : $previous['backend'];
			$this->require_true( $from !== $request['target'] );
			$this->storage( 'table' === $request['target'] ); // Explicit selection only; recovery never provisions.
			$retirement = new StorageRetirement( $this->connection, $this->prefix, $this->blog, $this->policy, $this->deadline, $this->memory );
			if ( $replacement ) {
				$this->require_true( null !== $previous && 2 === $previous['version'] && 'table' === $previous['from'] && 'option' === $previous['to'] && 'option' === $from );
			}
			$source = 'option' === $from ? $this->option_snapshot() : $this->table_snapshot( true );
			if ( 'option' === $from && ! $replacement ) {
				// Include orphan/staged records, not only visible state heads.
				$retirement->assert_empty();
				$this->require_true( ! $this->option_present( StorageAuthority::STORAGE_BACKUP_OPTION ) );
			} elseif ( 'table' === $from ) { $this->require_true( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ); }
			$id = substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
			$now = time();
			$record = array( 'version' => null === $previous ? 2 : $previous['version'], 'blog_id' => $this->blog, 'backend' => $from,
				'epoch' => null === $previous ? 0 : $previous['epoch'], 'switch_id' => $id,
				'from' => $from, 'to' => $request['target'], 'phase' => 'copying',
				'table_id' => 'option' === $from ? $id : $previous['table_id'],
				'source_digest' => $source['physical'], 'logical_digest' => $this->codec->snapshot_digest( $source['value'] ),
				'schema' => 1, 'codec' => 1, 'created' => $now, 'updated' => $now );
			if ( 2 === $record['version'] ) { $record['retained_digest'] = 'table' === $from ? $retirement->capture() : null; }
			$this->ready(); $new = json_encode( $record ); StorageControl::decode( $new, $this->blog );
			if ( $replacement ) {
				$owner = $this->gate->maintenance_identity();
				$retirement->replace( $raw, $new, $source['physical'], $owner, function () {
					$this->context();
					$this->require_true( MigratorEngine::storage_maintenance_is_quiescent() );
					$this->require_true( MigratorEngine::storage_maintenance_is_quiescent() );
				} );
			} else { $this->controls->cas( StorageAuthority::CONTROL_OPTION, $raw, $new, null, $this->gate->maintenance_identity() ); }
			$result = $this->advance( $record, $source );
			$this->end(); return $result;
		} catch ( \Exception $error ) { return $this->failed( $error ); }
		catch ( \Throwable $error ) { return $this->failed( $error ); }
	}

	/** Recover a recorded phase, not an expired maintenance owner. An existing
	 * live foreign/expired marker is retained and requires separate safe proof.
	 */
	public function recover( $request ) {
		$this->start_memory();
		$authorized = self::authorize_request( $request, true, $this->blog );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		$budget = StoragePreflight::decode_budget( 0, ini_get( 'memory_limit' ), $this->memory->sample(), $this->policy );
		if ( is_wp_error( $budget ) ) { return $budget; }
		try {
			$this->begin(); $this->storage();
			$r = StorageControl::decode( $this->controls->read( StorageAuthority::CONTROL_OPTION ), $this->blog );
			$this->require_true( 'stable' !== $r['phase'] && $r['to'] === $request['target'] );
			$source = null;
			if ( in_array( $r['phase'], array( 'copying', 'verified' ), true ) ) {
				$source = 'option' === $r['from'] ? $this->option_snapshot() : $this->table_snapshot( false );
				$this->require_true( $source['physical'] === $r['source_digest'] && $this->codec->snapshot_digest( $source['value'] ) === $r['logical_digest'] );
			}
			$result = $this->advance( $r, $source ); $this->end(); return $result;
		} catch ( \Exception $error ) { return $this->failed( $error ); }
		catch ( \Throwable $error ) { return $this->failed( $error ); }
	}

	/** Shared receiver authorization; never reads or writes storage. */
	public static function authorize_request( $r, $recovery = false, $blog = null, $replacement = false ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden' ); }
		$blog = null === $blog ? get_current_blog_id() : $blog;
		if ( ! is_array( $r ) || count( $r ) !== 5 || ! isset( $r['blog_id'], $r['scope'], $r['target'], $r['confirmation'], $r['nonce'] ) ||
			! is_int( $blog ) || $blog < 1 || $blog !== get_current_blog_id() ||
			( $r['blog_id'] !== $blog && $r['blog_id'] !== (string) $blog ) || 'site' !== $r['scope'] ||
			! in_array( $r['target'], array( 'table', 'option' ), true ) || ! is_string( $r['nonce'] ) ||
			( $replacement && ( $recovery || 'table' !== $r['target'] ) ) ||
			$r['confirmation'] !== ( $replacement ? 'replace-retained-with-table' : ( $recovery ? 'recover-storage' : 'switch-to-' . $r['target'] ) ) ) { return self::error( 'invalid_request' ); }
		$purpose = $replacement ? 'option_reimport' : ( $recovery ? 'recover' : ( 'table' === $r['target'] ? 'option_import' : 'table_export' ) );
		$nonce = wp_verify_nonce( $r['nonce'], 'foogallery_migrate_storage_' . $purpose . '_' . $blog );
		if ( 1 !== $nonce && 2 !== $nonce ) { return self::error( 'invalid_nonce' ); }
		return true;
	}

	private function begin() {
		$this->memory->sample();
		$this->deadline = microtime( true ) + 10;
		$this->gate = StorageMaintenanceGate::current();
		$this->connection = $this->gate->connection();
		$this->controls = new StorageControl( $this->connection, $this->blog );
		$this->require_true( $this->gate->acquire_maintenance() ); $this->owns_gate = true; $this->ready();
	}

	private function ready() {
		$this->context();
		$this->require_true( $this->gate->renew_maintenance() );
		$this->require_true( $this->gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) );
	}

	private function context() {
		global $wpdb;
		$this->require_true( $wpdb === $this->db && $this->blog === get_current_blog_id() && $this->prefix === $wpdb->prefix && microtime( true ) <= $this->deadline );
		if ( null !== $this->connection ) { $this->require_true( $this->connection->is_current() ); }
	}

	private function storage( $provision = false ) {
		try { $this->require_true( ( new LegacyStorageSchema( $this->connection ) )->verify() ); }
		catch ( \RuntimeException $error ) {
			if ( ! $provision ) { throw $error; }
			( new StorageProvisioning( $this->gate, $this->deadline ) )->ensure();
			$this->require_true( ( new LegacyStorageSchema( $this->connection ) )->verify() );
		}
		$this->snapshot = new LegacyTableSnapshot( $this->connection, $this->blog, 10000, 16777216, $this->deadline,
			function ( $bytes ) { $this->budget( $bytes ); return true; } );
	}

	private function advance( $r, $source ) {
		// Record and source are already live here; keep their backing charged.
		$this->memory->sample();
		if ( 'copying' === $r['phase'] ) {
			$this->ready();
			if ( 'table' === $r['to'] ) {
				$heads = $this->heads();
				if ( ! count( $heads ) && count( $source['value'] ) ) {
					$changes = array(); $order = 0;
					foreach ( $source['value'] as $key => $value ) {
						// Existing runtime checkpoints/queues are bounded scalar heads,
						// even when their logical value is an array. Keep conversion
						// compatible with their readers and atomic companion writes.
						$checkpoint = in_array( $key, array( MigratorSettings::KEY_GALLERIES . '-state', MigratorSettings::KEY_ALBUMS . '-state', ContentScanRepository::STATE_KEY, ImageTagQueueRepository::STATE_KEY ), true );
						$kind = $checkpoint || ! is_array( $value ) ? 'scalar' : ( $value === array_values( $value ) ? 'list' : 'map' );
						$changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => $kind, 'value' => $value, 'expected_revision' => null );
					}
					$this->snapshot->replace_many( $changes );
				}
				$target = $this->table_snapshot( false );
			} else {
				$raw = ( new LegacyOptionDecoder( $this->policy['max_raw_bytes'] ) )->encode( $source['value'], function ( $bytes ) { $this->budget( $bytes ); } );
				if ( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) { $this->insert_option( FOOGALLERY_MIGRATE_OPTION_DATA, $raw ); }
				$target = $this->option_snapshot();
			}
			$this->require_true( $this->codec->snapshot_digest( $target['value'] ) === $r['logical_digest'] );
			$this->unchanged_source( $r ); $r = $this->phase( $r, 'verified' );
		}
		if ( 'verified' === $r['phase'] ) {
			$this->ready(); $this->unchanged_source( $r );
			$target = 'table' === $r['to'] ? $this->table_snapshot( false ) : $this->option_snapshot();
			$this->require_true( $this->codec->snapshot_digest( $target['value'] ) === $r['logical_digest'] );
			if ( 'table' === $r['to'] ) {
				$original = $this->option_raw();
				$backup = null === $original ? serialize( array() ) : $original;
				if ( ! $this->option_present( StorageAuthority::STORAGE_BACKUP_OPTION ) ) { $this->insert_option( StorageAuthority::STORAGE_BACKUP_OPTION, $backup ); }
				$this->require_true( $this->raw_option_digest( StorageAuthority::STORAGE_BACKUP_OPTION ) === hash( 'sha256', 'present:' . hash( 'sha256', $backup ) ) );
				$pending = $r; ++$pending['epoch'];
				$sentinel = json_encode( StorageControl::sentinel( $pending ) );
				$existing = $this->controls->read( StorageAuthority::AUTHORITY_OPTION );
				if ( null === $existing ) { $this->controls->cas( StorageAuthority::AUTHORITY_OPTION, null, $sentinel, null, $this->gate->maintenance_identity() ); }
				else { $this->require_true( $existing === $sentinel ); }
			} else {
				$this->require_true( $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) === json_encode( StorageControl::sentinel( $r ) ) );
			}
			// Last source proof and engine/drain verdict immediately before CAS.
			$this->unchanged_source( $r ); $this->ready(); $r = $this->phase( $r, 'flipped', $target );
		}
		if ( 'flipped' === $r['phase'] ) {
			$this->ready();
			// Post-flip recovery uses ONLY the recorded destination authority.
			$active = 'table' === $r['backend'] ? $this->table_snapshot( false ) : $this->option_snapshot();
			$this->require_true( $this->codec->snapshot_digest( $active['value'] ) === $r['logical_digest'] );
			if ( 'table' === $r['backend'] ) {
				$this->require_true( $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) === json_encode( StorageControl::sentinel( $r ) ) );
				if ( $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) {
					$this->require_true( $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) === $r['source_digest'] );
					$raw = $this->option_raw();
					$this->write( $this->connection->prepare( 'DELETE FROM `' . $this->prefix . 'options` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( FOOGALLERY_MIGRATE_OPTION_DATA, $raw ) ) . $this->maintenance_guard() );
					$this->invalidate( FOOGALLERY_MIGRATE_OPTION_DATA );
					$this->require_true( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) );
				}
			} else {
				$sentinel = $this->controls->read( StorageAuthority::AUTHORITY_OPTION );
				$old = $r; --$old['epoch'];
				if ( null !== $sentinel ) {
					$this->require_true( $sentinel === json_encode( StorageControl::sentinel( $old ) ) );
					$this->controls->cas( StorageAuthority::AUTHORITY_OPTION, $sentinel, null, null, $this->gate->maintenance_identity() );
				}
			}
			$this->ready(); $r = $this->phase( $r, 'stable', $active );
		}
		$this->require_true( StorageControl::runtime_ready( $r, $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) ) );
		return $r;
	}

	private function phase( $r, $phase, $destination = null ) {
		$old = json_encode( $r );
		$source_sentinel = 'table' === $r['from'] ? json_encode( StorageControl::sentinel( $r ) ) : null;
		$r['phase'] = $phase; $r['updated'] = max( $r['updated'], time() );
		if ( 'flipped' === $phase ) { $this->require_true( $r['epoch'] < PHP_INT_MAX ); ++$r['epoch']; $r['backend'] = $r['to']; }
		$new = json_encode( $r ); StorageControl::decode( $new, $this->blog );
		$guard = null;
		if ( 'flipped' === $phase ) {
			$guard = $this->snapshot_guard( $r['from'], $r['source_digest'] );
		}
		if ( 'flipped' === $phase || 'stable' === $phase ) {
			$this->require_true( is_array( $destination ) && isset( $destination['physical'] ) );
			$dependencies = array( $this->snapshot_guard( $r['to'], $destination['physical'] ) );
			if ( null !== $guard ) { $dependencies[] = $guard; }
			$sentinel = 'table' === $r['to'] ? json_encode( StorageControl::sentinel( $r ) ) : ( 'flipped' === $phase ? $source_sentinel : null );
			$dependencies[] = array( 'kind' => 'sentinel', 'value' => $sentinel );
			$guard = array( 'kind' => 'all', 'value' => $dependencies );
		}
		$this->ready(); $this->controls->cas( StorageAuthority::CONTROL_OPTION, $old, $new, $guard, $this->gate->maintenance_identity() ); return $r;
	}

	private function snapshot_guard( $backend, $physical ) {
		if ( 'option' === $backend ) {
			$raw = $this->option_raw(); $hash = null === $raw ? null : hash( 'sha256', $raw );
			$this->require_true( hash( 'sha256', null === $hash ? 'absent' : 'present:' . $hash ) === $physical );
			return array( 'kind' => 'option', 'value' => $hash );
		}
		$heads = $this->heads(); $this->require_true( hash( 'sha256', json_encode( $heads ) ) === $physical );
		$dependencies = array();
		foreach ( $heads as $head ) { $dependencies[] = array( 'id' => (int) $head['id'], 'revision' => (int) $head['revision'], 'generation' => (int) $head['active_generation'] ); }
		return array( 'kind' => 'table', 'value' => $dependencies );
	}

	private function unchanged_source( $r ) {
		$current = 'option' === $r['from'] ? $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) : $this->table_physical_digest();
		$this->require_true( $current === $r['source_digest'] );
	}

	private function option_snapshot() {
		$this->ready(); $before = $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ); $raw = $this->option_raw();
		$value = array();
		if ( null !== $raw ) {
			$this->budget( strlen( $raw ) );
			// Structural proof before native hydration also protects PHP5.4, which
			// lacks allowed_classes. This is NOT streaming conversion and does not
			// reduce the calibrated whole-option memory requirement.
			$value = ( new LegacyOptionDecoder( $this->policy['max_raw_bytes'] ) )->decode( $raw );
		}
		$this->require_true( count( $value ) <= 100 );
		$this->codec->snapshot_digest( $value ); // Reject objects, depth overflow and unsupported scalars.
		$this->require_true( $before === $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		return array( 'physical' => $before, 'value' => $value );
	}

	private function option_raw() {
		$bytes = $this->connection->get_var( $this->connection->prepare( 'SELECT LENGTH(option_value) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		$this->require_true( empty( $this->connection->last_error ) );
		if ( null === $bytes ) { $this->budget( 0 ); return null; }
		$this->require_true( is_numeric( $bytes ) && (string) (int) $bytes === (string) $bytes && (int) $bytes >= 0 );
		$bytes = (int) $bytes; $this->budget( $bytes );
		$raw = $this->connection->get_var( $this->connection->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->prefix . 'options` WHERE option_name = %s AND LENGTH(option_value) = %d LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA, $bytes ) ) );
		$this->require_true( empty( $this->connection->last_error ) && is_string( $raw ) && 0 === strpos( $raw, 'v:' ) && strlen( $raw ) === $bytes + 2 );
		return substr( $raw, 2 );
	}

	private function budget( $bytes ) {
		$result = StoragePreflight::decode_budget( $bytes, ini_get( 'memory_limit' ), $this->memory->sample(), $this->policy );
		$this->require_true( ! is_wp_error( $result ) && true === $result['decode_admitted'] );
	}

	/** Consume receiver context once; direct/reused coordinators get a fresh operation. */
	private function start_memory() {
		$this->memory = null === $this->incoming_memory ? new StorageMemoryBaseline() : $this->incoming_memory;
		$this->incoming_memory = null; $this->memory->sample();
	}

	private function heads() {
		$table = $this->db->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
		$sql = 'SELECT id, CASE WHEN LENGTH(state_key_value) <= 1024 THEN state_key_value ELSE NULL END AS key_value, state_order, state_kind, active_generation, revision, record_count, payload_bytes, digest_status FROM `' . $table . '` WHERE blog_id = %d ORDER BY state_order LIMIT 101';
		$heads = $this->connection->get_results( $this->connection->prepare( $sql, array( $this->blog ) ), ARRAY_A );
		$this->require_true( empty( $this->connection->last_error ) && is_array( $heads ) && count( $heads ) <= 100 );
		$rows = 0; $bytes = 0;
		foreach ( $heads as $head ) {
			$this->require_true( is_string( $head['key_value'] ) );
			$rows += (int) $head['record_count']; $bytes += (int) $head['payload_bytes'];
			$this->require_true( $rows <= 10000 && $bytes <= 16777216 );
		}
		return $heads;
	}

	private function table_physical_digest() { return hash( 'sha256', json_encode( $this->heads() ) ); }

	private function table_snapshot( $verify_dirty ) {
		$this->ready(); $heads = $this->heads(); $value = array();
		// Dirty-head maintenance is material snapshot work too, not a free
		// metadata pass before the coordinator's operation-owned admission.
		$encoded_bytes = 0;
		foreach ( $heads as $head ) { $encoded_bytes += (int) $head['payload_bytes']; }
		$this->budget( $encoded_bytes );
		if ( $verify_dirty ) {
			foreach ( $heads as $head ) {
				if ( 'dirty' === $head['digest_status'] ) {
					$this->ready(); $this->snapshot->verify_digest( $this->codec->decode( $head['key_value'] ), (int) $head['revision'], 10 );
				}
			}
			$heads = $this->heads();
		}
		$before = hash( 'sha256', json_encode( $heads ) );
		$encoded_bytes = 0;
		foreach ( $heads as $head ) { $encoded_bytes += (int) $head['payload_bytes']; }
		$this->budget( $encoded_bytes );
		foreach ( $heads as $head ) {
			$this->ready(); $key = $this->codec->decode( $head['key_value'] );
			$this->require_true( ( is_int( $key ) || is_string( $key ) ) && ! array_key_exists( $key, $value ) );
			$value[$key] = $this->snapshot->read_bounded( $key );
		}
		$this->require_true( $before === $this->table_physical_digest() );
		return array( 'physical' => $before, 'value' => $value );
	}

	private function raw_option_digest( $name ) {
		$digest = $this->connection->get_var( $this->connection->prepare( 'SELECT SHA2(option_value, 256) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( $name ) ) );
		$this->require_true( empty( $this->connection->last_error ) && ( null === $digest || ( is_string( $digest ) && preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) ) );
		return hash( 'sha256', null === $digest ? 'absent' : 'present:' . $digest );
	}

	private function option_present( $name ) {
		$found = $this->connection->get_var( $this->connection->prepare( 'SELECT 1 AS present FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( $name ) ) );
		$this->require_true( empty( $this->connection->last_error ) ); return null !== $found;
	}

	private function insert_option( $name, $raw ) {
		$this->ready();
		$this->write( $this->connection->prepare( 'INSERT INTO `' . $this->prefix . "options` ( option_name, option_value, autoload ) SELECT %s, %s, 'no' WHERE 1 = 1", array( $name, $raw ) ) . $this->maintenance_guard() );
		$this->invalidate( $name );
		$this->require_true( $this->raw_option_digest( $name ) === hash( 'sha256', 'present:' . hash( 'sha256', $raw ) ) );
	}

	private function maintenance_guard() { return $this->controls->maintenance_guard( $this->gate->maintenance_identity() ); }
	private function write( $sql ) { $this->require_true( 1 === $this->connection->query( $sql ) && empty( $this->connection->last_error ) ); }
	private function invalidate( $name ) { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	private function require_true( $value ) { if ( true !== $value ) { throw new \RuntimeException( 'Retain recorded authority and all conversion evidence; storage recovery is required.' ); } }
	private function end() { $this->require_true( $this->gate->release_maintenance() ); $this->owns_gate = false; $this->gate = null; }
	private function failed( $error ) {
		// Only this object's owner may release. An uncertain phase/CAS is never
		// rolled back by guessing which backend won; its raw evidence remains.
		if ( $this->owns_gate && $this->gate instanceof StorageMaintenanceGate ) { $this->gate->release_maintenance(); $this->owns_gate = false; $this->gate = null; }
		return self::error( 'recovery_required' );
	}
	private static function error( $suffix ) { return new \WP_Error( 'foogallery_migrate_storage_' . $suffix, __( 'Migration storage conversion was refused. Retain the recorded authority and all storage evidence; no fallback or expired-owner takeover was attempted.', 'foogallery-migrate' ) ); }
}
