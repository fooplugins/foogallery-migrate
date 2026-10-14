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

	/** Policy must come from trusted runtime calibration, never request fields. */
	public function __construct( $policy = null ) {
		global $wpdb;
		$this->db = $wpdb; $this->blog = get_current_blog_id(); $this->prefix = $wpdb->prefix;
		$this->policy = $policy; $this->controls = new StorageControl( $wpdb, $this->blog );
		$this->codec = new LegacyStateCodec();
	}

	/** Switch only from a validated stable backend; no source/destination merge. */
	public function switch_backend( $request ) {
		$authorized = $this->authorize( $request, false );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
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
			$this->storage(); // Verified existing schema only; never DDL here.
			$source = 'option' === $from ? $this->option_snapshot() : $this->table_snapshot( true );
			if ( 'option' === $from ) {
				// Existing/inactive table generations need a separate explicit cleanup
				// or replacement protocol; this bounded first-import never erases them.
				$this->require_true( 0 === count( $this->heads() ) );
				$this->require_true( ! $this->option_present( StorageAuthority::STORAGE_BACKUP_OPTION ) );
			} else { $this->require_true( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ); }
			$id = substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
			$now = time();
			$record = array( 'version' => 1, 'blog_id' => $this->blog, 'backend' => $from,
				'epoch' => null === $previous ? 0 : $previous['epoch'], 'switch_id' => $id,
				'from' => $from, 'to' => $request['target'], 'phase' => 'copying',
				'table_id' => 'option' === $from ? $id : $previous['table_id'],
				'source_digest' => $source['physical'], 'logical_digest' => $this->codec->snapshot_digest( $source['value'] ),
				'schema' => 1, 'codec' => 1, 'created' => $now, 'updated' => $now );
			$this->ready(); $new = json_encode( $record ); StorageControl::decode( $new, $this->blog );
			$this->controls->cas( StorageAuthority::CONTROL_OPTION, $raw, $new );
			$result = $this->advance( $record, $source );
			$this->end(); return $result;
		} catch ( \Exception $error ) { return $this->failed( $error ); }
		catch ( \Throwable $error ) { return $this->failed( $error ); }
	}

	/** Recover a recorded phase, not an expired maintenance owner. An existing
	 * live foreign/expired marker is retained and requires separate safe proof.
	 */
	public function recover( $request ) {
		$authorized = $this->authorize( $request, true );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
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

	private function authorize( $r, $recovery ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden' ); }
		if ( ! is_array( $r ) || count( $r ) !== 5 || ! isset( $r['blog_id'], $r['scope'], $r['target'], $r['confirmation'], $r['nonce'] ) ||
			( $r['blog_id'] !== $this->blog && $r['blog_id'] !== (string) $this->blog ) || 'site' !== $r['scope'] ||
			! in_array( $r['target'], array( 'table', 'option' ), true ) || ! is_string( $r['nonce'] ) ||
			$r['confirmation'] !== ( $recovery ? 'recover-storage' : 'switch-to-' . $r['target'] ) ) { return self::error( 'invalid_request' ); }
		$purpose = $recovery ? 'recover' : ( 'table' === $r['target'] ? 'option_import' : 'table_export' );
		$nonce = wp_verify_nonce( $r['nonce'], 'foogallery_migrate_storage_' . $purpose . '_' . $this->blog );
		if ( 1 !== $nonce && 2 !== $nonce ) { return self::error( 'invalid_nonce' ); }
		return true;
	}

	private function begin() {
		$this->deadline = microtime( true ) + 10;
		$this->gate = StorageMaintenanceGate::current();
		$this->require_true( $this->gate->acquire_maintenance() ); $this->owns_gate = true; $this->ready();
	}

	private function ready() {
		global $wpdb;
		$this->require_true( $wpdb === $this->db && $this->blog === get_current_blog_id() && $this->prefix === $wpdb->prefix && microtime( true ) <= $this->deadline );
		$this->require_true( $this->gate->renew_maintenance() );
		$this->require_true( $this->gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) );
	}

	private function storage() {
		$this->connection = new LegacyTransactionConnection( $this->db );
		$this->require_true( ( new LegacyStorageSchema( $this->connection ) )->verify() );
		$this->snapshot = new LegacyTableSnapshot( $this->connection, $this->blog, 10000, 16777216, $this->deadline );
	}

	private function advance( $r, $source ) {
		if ( 'copying' === $r['phase'] ) {
			$this->ready();
			if ( 'table' === $r['to'] ) {
				$heads = $this->heads();
				if ( ! count( $heads ) && count( $source['value'] ) ) {
					$changes = array(); $order = 0;
					foreach ( $source['value'] as $key => $value ) {
						$kind = ! is_array( $value ) ? 'scalar' : ( $value === array_values( $value ) ? 'list' : 'map' );
						$changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => $kind, 'value' => $value, 'expected_revision' => null );
					}
					$this->snapshot->replace_many( $changes );
				}
				$target = $this->table_snapshot( false );
			} else {
				$raw = serialize( $source['value'] ); $this->budget( strlen( $raw ) );
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
				if ( null === $existing ) { $this->controls->cas( StorageAuthority::AUTHORITY_OPTION, null, $sentinel ); }
				else { $this->require_true( $existing === $sentinel ); }
			} else {
				$this->require_true( $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) === json_encode( StorageControl::sentinel( $r ) ) );
			}
			// Last source proof and engine/drain verdict immediately before CAS.
			$this->unchanged_source( $r ); $this->ready(); $r = $this->phase( $r, 'flipped' );
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
					$this->write( $this->db->prepare( 'DELETE FROM `' . $this->prefix . 'options` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( FOOGALLERY_MIGRATE_OPTION_DATA, $raw ) ) );
					$this->invalidate( FOOGALLERY_MIGRATE_OPTION_DATA );
					$this->require_true( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) );
				}
			} else {
				$sentinel = $this->controls->read( StorageAuthority::AUTHORITY_OPTION );
				$old = $r; --$old['epoch'];
				if ( null !== $sentinel ) {
					$this->require_true( $sentinel === json_encode( StorageControl::sentinel( $old ) ) );
					$this->controls->cas( StorageAuthority::AUTHORITY_OPTION, $sentinel, null );
				}
			}
			$this->ready(); $r = $this->phase( $r, 'stable' );
		}
		$this->require_true( StorageControl::runtime_ready( $r, $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) ) );
		return $r;
	}

	private function phase( $r, $phase ) {
		$old = json_encode( $r );
		$r['phase'] = $phase; $r['updated'] = max( $r['updated'], time() );
		if ( 'flipped' === $phase ) { $this->require_true( $r['epoch'] < PHP_INT_MAX ); ++$r['epoch']; $r['backend'] = $r['to']; }
		$new = json_encode( $r ); StorageControl::decode( $new, $this->blog );
		$guard = null;
		if ( 'flipped' === $phase ) {
			if ( 'option' === $r['from'] ) {
				$raw = $this->option_raw();
				$hash = null === $raw ? null : hash( 'sha256', $raw );
				$this->require_true( hash( 'sha256', null === $hash ? 'absent' : 'present:' . $hash ) === $r['source_digest'] );
				$guard = array( 'kind' => 'option', 'value' => $hash );
			} else {
				$heads = $this->heads(); $this->require_true( hash( 'sha256', json_encode( $heads ) ) === $r['source_digest'] );
				$dependencies = array();
				foreach ( $heads as $head ) { $dependencies[] = array( 'id' => (int) $head['id'], 'revision' => (int) $head['revision'], 'generation' => (int) $head['active_generation'] ); }
				$guard = array( 'kind' => 'table', 'value' => $dependencies );
			}
		}
		$this->controls->cas( StorageAuthority::CONTROL_OPTION, $old, $new, $guard ); return $r;
	}

	private function unchanged_source( $r ) {
		$current = 'option' === $r['from'] ? $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) : $this->table_physical_digest();
		$this->require_true( $current === $r['source_digest'] );
	}

	private function option_snapshot() {
		$this->ready(); $before = $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ); $raw = $this->option_raw();
		$value = array();
		if ( null !== $raw ) {
			// No custom PHP serialization parser and no object wakeup/autoload.
			// Literal PHP5.4 decoding remains unaccepted; refuse rather than invoke
			// arbitrary historical objects on runtimes lacking allowed_classes.
			$this->require_true( PHP_VERSION_ID >= 70000 );
			$this->budget( strlen( $raw ) );
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
			$this->require_true( is_array( $value ) && serialize( $value ) === $raw );
		}
		$this->require_true( count( $value ) <= 100 );
		$this->codec->snapshot_digest( $value ); // Reject objects, depth overflow and unsupported scalars.
		$this->require_true( $before === $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		return array( 'physical' => $before, 'value' => $value );
	}

	private function option_raw() {
		$bytes = $this->db->get_var( $this->db->prepare( 'SELECT LENGTH(option_value) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		$this->require_true( empty( $this->db->last_error ) );
		if ( null === $bytes ) { $this->budget( 0 ); return null; }
		$this->require_true( is_numeric( $bytes ) && (string) (int) $bytes === (string) $bytes && (int) $bytes >= 0 );
		$bytes = (int) $bytes; $this->budget( $bytes );
		$raw = $this->db->get_var( $this->db->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->prefix . 'options` WHERE option_name = %s AND LENGTH(option_value) = %d LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA, $bytes ) ) );
		$this->require_true( empty( $this->db->last_error ) && is_string( $raw ) && 0 === strpos( $raw, 'v:' ) && strlen( $raw ) === $bytes + 2 );
		return substr( $raw, 2 );
	}

	private function budget( $bytes ) {
		$result = StoragePreflight::decode_budget( $bytes, ini_get( 'memory_limit' ), memory_get_usage( true ), $this->policy );
		$this->require_true( ! is_wp_error( $result ) && true === $result['decode_admitted'] );
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
		$digest = $this->db->get_var( $this->db->prepare( 'SELECT SHA2(option_value, 256) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( $name ) ) );
		$this->require_true( empty( $this->db->last_error ) && ( null === $digest || ( is_string( $digest ) && preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) ) );
		return hash( 'sha256', null === $digest ? 'absent' : 'present:' . $digest );
	}

	private function option_present( $name ) {
		$found = $this->db->get_var( $this->db->prepare( 'SELECT 1 AS present FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( $name ) ) );
		$this->require_true( empty( $this->db->last_error ) ); return null !== $found;
	}

	private function insert_option( $name, $raw ) {
		$this->ready();
		$this->write( $this->db->prepare( 'INSERT INTO `' . $this->prefix . "options` ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", array( $name, $raw ) ) );
		$this->invalidate( $name );
		$this->require_true( $this->raw_option_digest( $name ) === hash( 'sha256', 'present:' . hash( 'sha256', $raw ) ) );
	}

	private function write( $sql ) { $this->require_true( 1 === $this->db->query( $sql ) && empty( $this->db->last_error ) ); }
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
