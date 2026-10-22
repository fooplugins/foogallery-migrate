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
	private $scale;
	private $scale_started = false;
	private $capacity_phase_depth = 0;

	/** Policy must come from trusted runtime calibration, never request fields. */
	public function __construct( $policy = null, $memory = null, $scale = null ) {
		global $wpdb;
		$this->db = $wpdb; $this->blog = get_current_blog_id(); $this->prefix = $wpdb->prefix;
		$this->policy = $policy; $this->controls = new StorageControl( $wpdb, $this->blog );
		$this->incoming_memory = $memory;
		if ( null !== $scale && ! $scale instanceof StorageScaleAdmission ) { $this->require_true( false ); }
		$this->scale = $scale;
		$this->codec = new LegacyStateCodec( 1048576, 64, 10000, $scale );
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
			$retirement = new StorageRetirement( $this->connection, $this->prefix, $this->blog, $this->policy, $this->deadline, $this->memory, $this->scale );
			if ( $replacement ) {
				$this->require_true( null !== $previous && in_array( $previous['version'], array( 2, 4 ), true ) && 'table' === $previous['from'] && 'option' === $previous['to'] && 'option' === $from );
			}
			$source = $this->source_metadata( $from, true );
			if ( 'option' === $from && ! $replacement ) {
				// Include orphan/staged records, not only visible state heads.
				$retirement->assert_empty();
				$this->require_true( ! $this->option_present( StorageAuthority::STORAGE_BACKUP_OPTION ) );
			} elseif ( 'table' === $from ) { $this->require_true( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ); }
			$id = substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
			$now = time();
			$record = array( 'version' => null !== $this->capacity() ? 4 : ( null === $previous ? 2 : $previous['version'] ), 'blog_id' => $this->blog, 'backend' => $from,
				'epoch' => null === $previous ? 0 : $previous['epoch'], 'switch_id' => $id,
				'from' => $from, 'to' => $request['target'], 'phase' => 'copying',
				'table_id' => 'option' === $from ? $id : $previous['table_id'],
				'source_digest' => $source['physical'], 'logical_digest' => $source['logical'],
				'schema' => 1, 'codec' => 1, 'created' => $now, 'updated' => $now );
			if ( in_array( $record['version'], array( 2, 4 ), true ) ) { $record['retained_digest'] = 'table' === $from ? $retirement->capture() : null; }
			if ( 4 === $record['version'] ) { $record['copy_cursor'] = 'table' === $request['target'] ? StorageCopyCursor::initial( $record ) : null; }
			StorageScalarObserver::milestone( 'source-verified' );
			$this->ready(); $new = json_encode( $record ); StorageControl::decode( $new, $this->blog );
			if ( $replacement ) {
				$owner = $this->gate->maintenance_identity();
				$retirement->replace( $raw, $new, $source['physical'], $owner, function () {
					$this->context();
					$this->require_true( MigratorEngine::storage_maintenance_is_quiescent() );
					$this->require_true( MigratorEngine::storage_maintenance_is_quiescent() );
				} );
			} else { $this->controls->cas( StorageAuthority::CONTROL_OPTION, $raw, $new, null, $this->gate->maintenance_identity() ); }
			StorageScalarObserver::milestone( 'control-copying' );
			unset( $source );
			// A finite operation owns one expensive lifetime. The durable copying
			// intent is already source-verified; continue on a fresh public recovery.
			$result = null !== $this->capacity() ? $record : $this->advance( $record );
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
			// copy_source_owned checks the exact physical AND logical source before
			// either direction can mutate. A verified v4 forward source is still
			// byte-bound to its original option; its fresh destination verification
			// below must fit without a redundant whole-option decode/digest.
			if ( 'verified' === $r['phase'] && 4 === $r['version'] && 'option' === $r['from'] ) { $this->unchanged_source( $r ); }
			elseif ( 'verified' === $r['phase'] ) {
				$source = $this->source_metadata( $r['from'], false );
				$this->require_true( $source['physical'] === $r['source_digest'] && $source['logical'] === $r['logical_digest'] );
				unset( $source );
			}
			$result = $this->advance( $r ); $this->end(); return $result;
		} catch ( \Exception $error ) { return $this->failed( $error ); }
		catch ( \Throwable $error ) { return $this->failed( $error ); }
	}

	/** Shared receiver authorization; never reads or writes storage. */
	public function discard_copy( $request ) {
		$this->start_memory();
		$authorized = self::authorize_request( $request, false, $this->blog, false, true );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		try {
			$this->begin(); $this->storage();
			$r = StorageControl::decode( $this->controls->read( StorageAuthority::CONTROL_OPTION ), $this->blog );
			$this->require_true( null !== $r && 4 === $r['version'] && 'copying' === $r['phase'] && 'option' === $r['backend'] && 'table' === $r['to'] );
			$this->discard_source( $r ); $this->unchanged_source( $r ); $this->ready();
			$this->require_true( null === $this->controls->read( StorageAuthority::CONTROL_OPTION ) && null === $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) );
			( new StorageRetirement( $this->connection, $this->prefix, $this->blog, $this->policy, $this->deadline, $this->memory, $this->scale ) )->assert_empty();
			$this->end(); return array( 'blog_id' => $this->blog, 'backend' => 'option', 'epoch' => $r['epoch'], 'phase' => 'stable' );
		} catch ( \Exception $error ) { $this->end(); return self::error( 'recovery_required' ); }
		catch ( \Throwable $error ) { $this->end(); return self::error( 'recovery_required' ); }
	}
	private function discard_source( $r ) {
		$token = $this->open_capacity( 'commit_cleanup_response' );
		$this->discard_source_owned( $r ); $this->close_capacity( $token );
	}
	private function discard_source_owned( $r ) {
		$source = $this->option_snapshot();
		$this->require_true( $source['physical'] === $r['source_digest'] && $source['logical'] === $r['logical_digest'] );
		$this->snapshot->conversion_batch( $r, $source['value'], $this->controls, $this->gate->maintenance_identity(), true );
	}

	public static function authorize_request( $r, $recovery = false, $blog = null, $replacement = false, $discard = false ) {
		StorageScalarObserver::refusal_scope();
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden' ); }
		$blog = null === $blog ? get_current_blog_id() : $blog;
		if ( ! is_array( $r ) || count( $r ) !== 5 || ! isset( $r['blog_id'], $r['scope'], $r['target'], $r['confirmation'], $r['nonce'] ) ||
			! is_int( $blog ) || $blog < 1 || $blog !== get_current_blog_id() ||
			( $r['blog_id'] !== $blog && $r['blog_id'] !== (string) $blog ) || 'site' !== $r['scope'] ||
			! in_array( $r['target'], array( 'table', 'option' ), true ) || ! is_string( $r['nonce'] ) ||
			( $replacement && ( $recovery || $discard || 'table' !== $r['target'] ) ) || ( $discard && ( $recovery || 'table' !== $r['target'] ) ) ||
			$r['confirmation'] !== ( $discard ? 'discard-copy' : ( $replacement ? 'replace-retained-with-table' : ( $recovery ? 'recover-storage' : 'switch-to-' . $r['target'] ) ) ) ) { return self::error( 'invalid_request' ); }
		$purpose = $discard ? 'discard' : ( $replacement ? 'option_reimport' : ( $recovery ? 'recover' : ( 'table' === $r['target'] ? 'option_import' : 'table_export' ) ) );
		$nonce = wp_verify_nonce( $r['nonce'], 'foogallery_migrate_storage_' . $purpose . '_' . $blog );
		if ( 1 !== $nonce && 2 !== $nonce ) { return self::error( 'invalid_nonce' ); }
		$site = StorageAuthority::table_mode_error();
		if ( is_wp_error( $site ) ) { return $site; }
		StorageScalarObserver::refusal_scope( true );
		return true;
	}

	private function begin() {
		$this->memory->sample();
		if ( null !== $this->scale ) {
			$this->require_true( ! $this->scale_started ); $this->scale_started = true;
			$this->deadline = $this->scale->deadline(); $this->scale->admit_memory( 0 );
		} else { $this->deadline = microtime( true ) + 10; }
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
		if ( null !== $this->scale ) { $this->scale->tick(); }
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
		$this->snapshot = new LegacyTableSnapshot( $this->connection, $this->blog, $this->limit( 'max_rows', 10000 ), $this->limit( 'max_bytes', 16777216 ), $this->deadline,
			function ( $bytes ) { $this->table_budget( $bytes ); return true; }, $this->scale );
	}

	private function advance( $r ) {
		$this->memory->sample();
		if ( 'copying' === $r['phase'] ) {
			$this->ready(); $r = $this->copy_source( $r );
			if ( 4 === $r['version'] && ! $this->copy_complete ) { return $r; }
			$target = null !== $this->copy_proved_logical ? array( 'physical' => $this->table_physical_digest(), 'logical' => $this->copy_proved_logical ) : $this->source_metadata( $r['to'], false );
			$this->require_true( $target['logical'] === $r['logical_digest'] );
			$this->unchanged_source( $r ); $r = $this->phase( $r, 'verified' );
			// The copying transaction compared each destination row to its exact
			// source. Independently prove all rows in fresh bounded verified turns,
			// not on top of the final copy transaction's lifetime.
			if ( null !== $this->capacity() ) { return $r; }
		}
		if ( 'verified' === $r['phase'] ) {
			$this->ready(); $this->unchanged_source( $r );
			if ( 4 === $r['version'] && 'option' === $r['from'] && 'table' === $r['to'] ) {
				$proof = $this->verify_forward( $r ); $r = $proof['record'];
				if ( ! $proof['already_complete'] ) { return $r; }
				$target = array( 'physical' => $this->table_physical_digest(), 'logical' => $r['logical_digest'] );
			} else { $target = $this->source_metadata( $r['to'], false ); }
			$this->require_true( $target['logical'] === $r['logical_digest'] );
			$this->retain_and_select( $r );
			$this->unchanged_source( $r ); $this->ready(); $r = $this->phase( $r, 'flipped', $target );
			if ( null !== $this->capacity() ) { return $r; }
		}
		if ( 'flipped' === $r['phase'] ) {
			$this->ready();
			if ( 4 === $r['version'] && 'option' === $r['from'] && 'table' === $r['to'] &&
				( isset( $r['verify_cursor'] ) || $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) ) {
				$proof = $this->verify_forward( $r ); $r = $proof['record'];
				if ( ! $proof['already_complete'] ) { return $r; }
				$active = array( 'physical' => $this->table_physical_digest(), 'logical' => $r['logical_digest'] );
			} else { $active = $this->source_metadata( $r['backend'], false ); }
			$this->require_true( $active['logical'] === $r['logical_digest'] );
			$this->cleanup_source( $r );
			$this->ready(); $r = $this->phase( $r, 'stable', $active );
		}
		$this->require_true( StorageControl::runtime_ready( $r, $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) ) );
		return $r;
	}
	private function verify_forward( $r ) {
		$token = $this->open_capacity( 'stage' );
		try {
			if ( 'flipped' === $r['phase'] && ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) {
				// Cleanup's exact-byte DELETE can succeed before stable control CAS.
				// The retained backup replaces absent source bytes, but only a fully
				// completed prior proof may be reused in this narrow recovery state.
				$expected_backup = hash( 'sha256', 'absent' ) === $r['source_digest'] ?
					hash( 'sha256', 'present:' . hash( 'sha256', serialize( array() ) ) ) : $r['source_digest'];
				$this->require_true( $this->raw_option_digest( StorageAuthority::STORAGE_BACKUP_OPTION ) === $expected_backup );
				$this->snapshot->conversion_verified_metadata( $r );
				$result = array( 'record' => $r, 'already_complete' => true );
			} else {
				$source = $this->option_snapshot();
				$this->require_true( $source['physical'] === $r['source_digest'] && $source['logical'] === $r['logical_digest'] );
				$result = $this->snapshot->conversion_verify_batch( $r, $source['value'], $this->controls, $this->gate->maintenance_identity() );
			}
		} catch ( \Exception $error ) { $this->abort_capacity(); throw $error; }
		catch ( \Throwable $error ) { $this->abort_capacity(); throw $error; }
		$this->close_capacity( $token ); return $result;
	}

	/** This helper must UNWIND before independent destination verification. */
	private $copy_complete = true;
	private $copy_proved_logical = null;
	private function copy_source( $r ) {
		$this->copy_complete = true; $this->copy_proved_logical = null;
		$token = $this->open_capacity( 'table' === $r['to'] ? 'stage' : 'reverse_encode_write' );
		$result = $this->copy_source_owned( $r );
		$this->close_capacity( $token ); return $result;
	}
	private function copy_source_owned( $r ) {
		$source = 'option' === $r['from'] ? $this->option_snapshot() : $this->table_snapshot( false );
		$logical = isset( $source['logical'] ) ? $source['logical'] : $this->codec->snapshot_digest( $source['value'] );
		$this->require_true( $source['physical'] === $r['source_digest'] && $logical === $r['logical_digest'] );
		if ( 'table' === $r['to'] ) {
			if ( 4 === $r['version'] ) {
				$batch = $this->snapshot->conversion_batch( $r, $source['value'], $this->controls, $this->gate->maintenance_identity() );
				$this->copy_complete = $batch['complete'];
				if ( $this->copy_complete ) { $this->require_true( $batch['proved_logical_digest'] === $r['logical_digest'] ); $this->copy_proved_logical = $batch['proved_logical_digest']; }
				return $batch['record'];
			}
				$heads = $this->heads();
				if ( ! count( $heads ) && count( $source['value'] ) ) {
					$changes = array(); $order = 0;
					foreach ( $source['value'] as $key => $value ) {
						// Existing runtime checkpoints/queues are bounded scalar heads,
						// even when their logical value is an array. Keep conversion
						// compatible with their readers and atomic companion writes.
						$checkpoint = in_array( $key, array( MigratorSettings::KEY_GALLERIES . '-state', MigratorSettings::KEY_ALBUMS . '-state', ImageTagQueueRepository::STATE_KEY ), true );
						// Preserve the false invalidation sentinel as scalar for exact roundtrip.
						// Scalar table heads still require explicit reset before bounded access.
						$kind = ContentScanRepository::STATE_KEY === $key && is_array( $value ) ? 'map' : ( $checkpoint || ! is_array( $value ) ? 'scalar' : ( $value === array_values( $value ) ? 'list' : 'map' ) );
						$changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => $kind, 'value' => $value, 'expected_revision' => null );
					}
					$this->snapshot->replace_many( $changes );
				}
		} else {
			// Full CURRENT source graph plus a fresh option serialization are live.
			$raw = $this->option_decoder()->encode( $source['value'], function ( $bytes ) { $this->budget( $bytes ); } );
			StorageScalarObserver::milestone( 'reverse-serialized' );
			if ( ! $this->option_present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) { $this->insert_option( FOOGALLERY_MIGRATE_OPTION_DATA, $raw ); }
			StorageScalarObserver::milestone( 'reverse-written' );
		}
		return $r;
	}

	private function retain_and_select( $r ) {
		$token = $this->open_capacity( 'commit_cleanup_response' );
		try { $this->retain_and_select_owned( $r ); }
		catch ( \Exception $error ) { $this->abort_capacity(); throw $error; }
		catch ( \Throwable $error ) { $this->abort_capacity(); throw $error; }
		// The owned helper's raw/backup/SQL buffers have unwound before release.
		$this->close_capacity( $token );
	}
	private function retain_and_select_owned( $r ) {
			if ( 'table' === $r['to'] ) {
				$original = $this->option_raw();
				$backup = null === $original ? serialize( array() ) : $original;
				if ( ! $this->option_present( StorageAuthority::STORAGE_BACKUP_OPTION ) ) { $this->insert_option( StorageAuthority::STORAGE_BACKUP_OPTION, $backup ); }
				$this->require_true( $this->raw_option_digest( StorageAuthority::STORAGE_BACKUP_OPTION ) === hash( 'sha256', 'present:' . hash( 'sha256', $backup ) ) );
				StorageScalarObserver::milestone( 'retained-backup' );
				$pending = $r; ++$pending['epoch'];
				$sentinel = json_encode( StorageControl::sentinel( $pending ) );
				$existing = $this->controls->read( StorageAuthority::AUTHORITY_OPTION );
				if ( null === $existing ) { $this->controls->cas( StorageAuthority::AUTHORITY_OPTION, null, $sentinel, null, $this->gate->maintenance_identity() ); }
				else { $this->require_true( $existing === $sentinel ); }
			} else {
				$this->require_true( $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) === json_encode( StorageControl::sentinel( $r ) ) );
			}
	}
	private function cleanup_source( $r ) {
		$token = $this->open_capacity( 'commit_cleanup_response' );
		try { $this->cleanup_source_owned( $r ); }
		catch ( \Exception $error ) { $this->abort_capacity(); throw $error; }
		catch ( \Throwable $error ) { $this->abort_capacity(); throw $error; }
		// In particular, keep the exact-byte DELETE's escaped SQL forecast live.
		$this->close_capacity( $token );
	}
	private function cleanup_source_owned( $r ) {
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
	}


	private function phase( $r, $phase, $destination = null ) {
		$token = $this->open_capacity( 'commit_cleanup_response' );
		$old = json_encode( $r );
		$source_sentinel = 'table' === $r['from'] ? json_encode( StorageControl::sentinel( $r ) ) : null;
		$r['phase'] = $phase; if ( 4 === $r['version'] && 'copying' !== $phase ) { $r['copy_cursor'] = null; }
		if ( 4 === $r['version'] && 'option' === $r['from'] && 'table' === $r['to'] ) {
			if ( 'verified' === $phase ) { $r['verify_cursor'] = StorageCopyCursor::initial_verify(); }
			if ( 'stable' === $phase ) { unset( $r['verify_cursor'] ); }
		}
		$r['updated'] = max( $r['updated'], time() );
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
		$this->ready(); $this->controls->cas( StorageAuthority::CONTROL_OPTION, $old, $new, $guard, $this->gate->maintenance_identity() );
		StorageScalarObserver::milestone( 'control-' . $phase ); $this->close_capacity( $token ); return $r;
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
			StorageScalarObserver::milestone( 'source-read' );
			// Structural proof before native hydration also protects PHP5.4, which
			// lacks allowed_classes. Decode still requires the whole option graph
			// and its exact serialized roundtrip; this is NOT streaming conversion.
			$value = $this->option_decoder()->decode( $raw );
			unset( $raw ); // Exact decode is complete; hashing needs only the graph.
			StorageScalarObserver::milestone( 'source-decoded' );
		}
		$this->require_true( count( $value ) <= $this->limit( 'max_heads', 100 ) );
		$logical = $this->codec->snapshot_digest( $value ); // Reject objects, depth overflow and unsupported scalars once.
		$this->require_true( $before === $this->raw_option_digest( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		return array( 'physical' => $before, 'logical' => $logical, 'value' => $value );
	}

	private function capacity() { return null === $this->scale ? null : $this->scale->capacity(); }
	private function open_capacity( $phase ) {
		$c = $this->capacity(); if ( null === $c ) { return null; }
		$token = $c->open( $this, $phase, array( 'R' => $c->limit( 'max_raw_bytes' ), 'T' => $c->limit( 'max_tokens' ),
			'N' => $c->limit( 'max_rows' ), 'E' => $c->limit( 'max_bytes' ), 'S' => $c->limit( 'max_scalar_bytes' ),
			'M' => $c->limit( 'max_row_bytes' ), 'H' => $c->limit( 'max_heads' ), 'D' => $c->limit( 'max_depth' ), 'W' => $c->limit( 'copy_batch_bytes' ) ) );
		++$this->capacity_phase_depth; return $token;
	}
	private function close_capacity( $token ) { if ( null !== $token ) { $this->capacity()->close( $this, $token ); --$this->capacity_phase_depth; } }
	private function abort_capacity() { if ( null !== $this->capacity() ) { $this->capacity()->abort(); $this->capacity_phase_depth = 0; } }
	private function source_metadata( $backend, $refresh ) {
		$token = $this->open_capacity( 'table' === $backend ? 'table_verify' : 'option_verify' );
		try { $result = $this->source_metadata_owned( $backend, $refresh ); }
		catch ( \Exception $error ) { $this->abort_capacity(); throw $error; }
		catch ( \Throwable $error ) { $this->abort_capacity(); throw $error; }
		$this->close_capacity( $token ); return $result;
	}
	private function source_metadata_owned( $backend, $refresh ) {
		if ( 'option' === $backend ) {
			$s = $this->option_snapshot();
			return array( 'physical' => $s['physical'], 'logical' => $s['logical'] );
		}
		$heads = $this->table_heads( $refresh ); $physical = hash( 'sha256', json_encode( $heads ) ); $seen = array();
		$logical = $this->codec->snapshot_digest_reader( count( $heads ), function ( $i ) use ( $heads, &$seen ) {
			$this->ready();
			$head = $heads[$i]; $this->require_true( $this->number( $head['state_order'] ) === $i );
			$key = $this->codec->decode( $head['key_value'] );
			$this->require_true( ( is_int( $key ) || is_string( $key ) ) && ! array_key_exists( $key, $seen ) ); $seen[$key] = true;
			return array( 'key' => $key, 'value' => $this->snapshot->read_bounded( $key ) );
		} );
		$this->require_true( $physical === $this->table_physical_digest() );
		return array( 'physical' => $physical, 'logical' => $logical );
	}

	private function option_raw() {
		$this->require_true( null === $this->capacity() || $this->capacity_phase_depth > 0 );
		$bytes = $this->connection->get_var( $this->connection->prepare( 'SELECT LENGTH(option_value) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		$this->require_true( empty( $this->connection->last_error ) );
		if ( null === $bytes ) { $this->budget( 0 ); return null; }
		$this->require_true( is_numeric( $bytes ) && (string) (int) $bytes === (string) $bytes && (int) $bytes >= 0 );
		$bytes = (int) $bytes; $this->budget( $bytes );
		// Every production caller is already inside stage, option_verify or
		// commit_cleanup_response. That phase owns the raw SQL buffer; opening
		// another identical fixed headroom here double-charges the same lifetime.
		$raw = $this->connection->get_var( $this->connection->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->prefix . 'options` WHERE option_name = %s AND LENGTH(option_value) = %d LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA, $bytes ) ) );
		$this->require_true( empty( $this->connection->last_error ) && is_string( $raw ) && 0 === strpos( $raw, 'v:' ) && strlen( $raw ) === $bytes + 2 );
		$result = substr( $raw, 2 ); unset( $raw ); return $result;
	}

	private function limit( $name, $unscaled ) { return null === $this->scale ? $unscaled : $this->scale->limit( $name ); }

	private function option_decoder() {
		return null === $this->scale ? new LegacyOptionDecoder( $this->policy['max_raw_bytes'] ) : $this->scale->decoder( $this->policy['max_raw_bytes'] );
	}

	/** Serialized option reads/decoding/reverse encoding keep the exact raw cap. */
	private function budget( $bytes ) { $this->memory_budget( $bytes, $this->policy, true ); }

	/** Derived envelopes use the existing finite table cap, not the raw source cap. */
	private function table_budget( $bytes ) {
		$policy = $this->policy;
		$policy['max_raw_bytes'] = $this->limit( 'max_bytes', 16777216 );
		$this->memory_budget( $bytes, $policy, false );
	}

	/** Full option decode needs the raw forecast; table pages use their bounded workspace. */
	private function memory_budget( $bytes, $policy, $full_decode ) {
		if ( $full_decode || null === $this->capacity() ) {
			$result = StoragePreflight::decode_budget( $bytes, ini_get( 'memory_limit' ), $this->memory->sample(), $policy, 'conversion-budget' );
			$this->require_true( ! is_wp_error( $result ) && true === $result['decode_admitted'] );
		}
		if ( null !== $this->scale ) { $this->scale->admit_memory( $bytes ); }
		if ( null !== $this->capacity() ) {
			$this->require_true( is_int( $bytes ) && $bytes >= 0 && $bytes <= $policy['max_raw_bytes'] ); return;
		}
	}

	/** Consume receiver context once; direct/reused coordinators get a fresh operation. */
	private function start_memory() {
		$this->memory = null === $this->incoming_memory ? new StorageMemoryBaseline() : $this->incoming_memory;
		$this->incoming_memory = null; $this->memory->sample();
	}

	private function heads() {
		$table = $this->db->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
		$sql = 'SELECT id, CASE WHEN LENGTH(state_key_value) <= 1024 THEN state_key_value ELSE NULL END AS key_value, state_order, state_kind, active_generation, revision, record_count, payload_bytes, digest_status FROM `' . $table . '` WHERE blog_id = %d ORDER BY state_order LIMIT ' . ( $this->limit( 'max_heads', 100 ) + 1 );
		$heads = $this->connection->get_results( $this->connection->prepare( $sql, array( $this->blog ) ), ARRAY_A );
		$this->require_true( empty( $this->connection->last_error ) && is_array( $heads ) && count( $heads ) <= $this->limit( 'max_heads', 100 ) );
		$rows = 0; $bytes = 0;
		foreach ( $heads as $head ) {
			$this->context();
			$this->require_true( is_string( $head['key_value'] ) );
			$rows = StorageScaleAdmission::checked_add( $rows, $this->number( $head['record_count'] ) );
			$bytes = StorageScaleAdmission::checked_add( $bytes, $this->number( $head['payload_bytes'] ) );
			$this->require_true( $rows <= $this->limit( 'max_rows', 10000 ) && $bytes <= $this->limit( 'max_bytes', 16777216 ) );
		}
		return $heads;
	}

	private function number( $value ) {
		$this->require_true( ( is_int( $value ) || is_string( $value ) ) && (string) (int) $value === (string) $value && (int) $value >= 0 );
		return (int) $value;
	}

	private function table_physical_digest() { return hash( 'sha256', json_encode( $this->heads() ) ); }

	/** Maintenance may refresh heads, but never retains a complete state graph. */
	private function table_heads( $verify_dirty ) {
		$this->ready(); $heads = $this->heads();
		// Dirty-head maintenance is material snapshot work too, not a free
		// metadata pass before the coordinator's operation-owned admission.
		$encoded_bytes = 0;
		foreach ( $heads as $head ) { $encoded_bytes += (int) $head['payload_bytes']; }
		$this->table_budget( $encoded_bytes );
		if ( $verify_dirty ) {
			foreach ( $heads as $head ) {
				if ( 'dirty' === $head['digest_status'] ) {
					$this->ready(); $this->snapshot->verify_digest( $this->codec->decode( $head['key_value'] ), (int) $head['revision'], 10 );
				}
			}
			$heads = $this->heads();
		}
		$encoded_bytes = 0;
		foreach ( $heads as $head ) { $encoded_bytes += (int) $head['payload_bytes']; }
		$this->table_budget( $encoded_bytes );
		return $heads;
	}

	private function table_snapshot( $verify_dirty ) {
		$heads = $this->table_heads( $verify_dirty ); $value = array();
		$before = hash( 'sha256', json_encode( $heads ) );
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
	private function end() { $this->require_true( $this->gate->release_maintenance() ); $this->owns_gate = false; $this->gate = null; StorageScalarObserver::milestone( 'released' ); }
	private function failed( $error ) {
		$this->abort_capacity();
		// Only this object's owner may release. An uncertain phase/CAS is never
		// rolled back by guessing which backend won; its raw evidence remains.
		if ( $this->owns_gate && $this->gate instanceof StorageMaintenanceGate ) { $this->gate->release_maintenance(); $this->owns_gate = false; $this->gate = null; }
		return self::error( 'recovery_required' );
	}
	private static function error( $suffix ) { return new \WP_Error( 'foogallery_migrate_storage_' . $suffix, __( 'Migration storage conversion was refused. Retain the recorded authority and all storage evidence; no fallback or expired-owner takeover was attempted.', 'foogallery-migrate' ) ); }
}
