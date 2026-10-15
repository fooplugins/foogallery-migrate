<?php
/**
 * Explicit inactive-copy replacement, not a purge or stale-owner takeover.
 * The caller owns maintenance, drain and the connection transaction boundary.
 * Old evidence and the new copying control change in ONE pinned transaction.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageRetirement {
	private $db;
	private $options;
	private $states;
	private $records;
	private $blog;
	private $policy;
	private $deadline;
	private $memory;

	public function __construct( $connection, $prefix, $blog, $policy, $deadline, $memory = null ) {
		if ( ! ( $connection instanceof LegacyTransactionConnection ) || ! is_int( $blog ) || $blog < 1 ||
			! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/D', $connection->base_prefix ) ) { $this->fail(); }
		$this->db = $connection; $this->options = $prefix . 'options'; $this->blog = $blog;
		$this->states = $connection->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
		$this->records = $connection->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX;
		$this->policy = $policy; $this->deadline = $deadline;
		$this->memory = null === $memory ? new StorageMemoryBaseline() : $memory;
	}

	/** Uncontrolled/proposed/orphan state is evidence, never a first-import target. */
	public function assert_empty() {
		foreach ( array( $this->states, $this->records ) as $table ) {
			if ( count( $this->rows( $this->db->prepare( 'SELECT id FROM `' . $table . '` WHERE blog_id = %d LIMIT 1', array( $this->blog ) ) ) ) ) { $this->fail(); }
		}
	}

	/** All generations, dirty metadata, orphan rows and exact backup identity.
	 * SQL returns fixed-size per-column hashes, never hydrated payloads. Limits
	 * are refusal bounds, not a claimed production calibration/scale receipt.
	 */
	public function capture( $lock = false ) {
		$manifest = array(); $total = 0;
		foreach ( ( new LegacyStorageSchema( $this->db ) )->definitions() as $table => $definition ) {
			$names = array_keys( $definition['columns'] ); $actual = array();
			foreach ( $this->rows( 'SHOW COLUMNS FROM `' . $table . '`' ) as $column ) { $actual[] = $column['Field']; }
			// An extended/proposed table shape needs separate classification; its
			// unknown columns cannot be silently omitted from destructive evidence.
			if ( $actual !== $names ) { $this->fail(); }
			$hashes = array(); $lengths = array();
			foreach ( $names as $name ) {
				$hashes[] = "IFNULL(CONCAT('v', SHA2(CAST(`" . $name . "` AS BINARY), 256)), 'n')";
				$lengths[] = 'IFNULL(OCTET_LENGTH(`' . $name . '`), 0)';
			}
			$count = $this->rows( $this->db->prepare( 'SELECT COUNT(*) AS n FROM `' . $table . '` WHERE blog_id = %d', array( $this->blog ) ) );
			if ( 1 !== count( $count ) || (int) $count[0]['n'] > 10000 ) { $this->fail(); }
			$limit = (int) $count[0]['n'] + 1;
			$budget = StoragePreflight::decode_budget( $limit * 256, ini_get( 'memory_limit' ), $this->memory->sample(), $this->policy );
			if ( is_wp_error( $budget ) ) { $this->fail(); }
			$sql = 'SELECT id, (' . implode( ' + ', $lengths ) . ') AS bytes, SHA2(CONCAT(' . implode( ', ', $hashes ) . '), 256) AS digest FROM `' . $table . '` WHERE blog_id = %d ORDER BY id LIMIT ' . $limit;
			$rows = $this->rows( $this->db->prepare( $sql . ( $lock ? ' FOR UPDATE' : '' ), array( $this->blog ) ) );
			if ( count( $rows ) !== (int) $count[0]['n'] ) { $this->fail(); }
			foreach ( $rows as $row ) {
				if ( ! isset( $row['bytes'], $row['digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['digest'] ) ) { $this->fail(); }
				$total += (int) $row['bytes'];
				if ( $total > 16777216 ) { $this->fail(); }
			}
			$manifest[] = $rows;
		}
		$manifest[] = $this->option_identity( StorageAuthority::STORAGE_BACKUP_OPTION, $lock );
		return hash( 'sha256', json_encode( $manifest ) );
	}

	/** Retire ONLY a bound stable reverse; never replace missing/corrupt/v1 control.
	 * No ordinary writer can observe stable control with partially retired state:
	 * rollback restores both, commit exposes the new copying recovery record.
	 */
	public function replace( $old, $new, $source_digest, $owner, $ready ) {
		$previous = StorageControl::decode( $old, $this->blog );
		$next = StorageControl::decode( $new, $this->blog );
		if ( 2 !== $previous['version'] || 'stable' !== $previous['phase'] || 'option' !== $previous['backend'] || 'table' !== $previous['from'] ||
			2 !== $next['version'] || 'copying' !== $next['phase'] || 'option' !== $next['from'] || 'table' !== $next['to'] ||
			$next['epoch'] !== $previous['epoch'] || $next['source_digest'] !== $source_digest || ! is_string( $owner ) || ! is_callable( $ready ) ) { $this->fail(); }
		$engine = $this->rows( $this->db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $this->options ) ) );
		if ( 1 !== count( $engine ) || 'innodb' !== strtolower( $engine[0]['ENGINE'] ) ) { $this->fail(); }
		// SET without SESSION/GLOBAL refuses ambient transactions rather than
		// implicitly committing someone else's work with START TRANSACTION.
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		try {
			$this->write( 'START TRANSACTION' );
			$this->owner( $owner );
			$writers = $this->rows( $this->db->prepare( 'SELECT option_id FROM `' . $this->options . '` WHERE option_name LIKE %s LIMIT 1 FOR UPDATE', array( $this->db->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) ) );
			if ( count( $writers ) ) { $this->fail(); }
			if ( $this->control( true ) !== $old || count( $this->option_identity( StorageAuthority::AUTHORITY_OPTION, true ) ) ) { $this->fail(); }
			$source = $this->option_identity( FOOGALLERY_MIGRATE_OPTION_DATA, true );
			$physical = hash( 'sha256', count( $source ) ? 'present:' . $source[0]['digest'] : 'absent' );
			if ( $physical !== $source_digest || $this->capture( true ) !== $previous['retained_digest'] ) { $this->fail(); }
			// Actual table reads hold transaction-duration metadata locks. Recheck
			// engines now, not only before entry (an intervening ALTER may differ).
			foreach ( array( $this->options, $this->states, $this->records ) as $table ) {
				$engine = $this->rows( $this->db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $table ) ) );
				if ( 1 !== count( $engine ) || 'innodb' !== strtolower( $engine[0]['ENGINE'] ) ) { $this->fail(); }
			}
			call_user_func( $ready );
			foreach ( array( $this->records, $this->states ) as $table ) {
				$this->write( $this->db->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', array( $this->blog ) ) );
			}
			$this->write( $this->db->prepare( 'DELETE FROM `' . $this->options . '` WHERE option_name = %s', array( StorageAuthority::STORAGE_BACKUP_OPTION ) ) );
			$changed = $this->write( $this->db->prepare( 'UPDATE `' . $this->options . "` SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = BINARY %s", array( $new, StorageAuthority::CONTROL_OPTION, $old ) ) );
			if ( 1 !== $changed || $this->control( false ) !== $new ) { $this->fail(); }
			$this->assert_empty();
			if ( count( $this->option_identity( StorageAuthority::STORAGE_BACKUP_OPTION, false ) ) ) { $this->fail(); }
			call_user_func( $ready );
			$this->owner( $owner );
			$this->write( 'COMMIT' );
		} catch ( \Exception $error ) {
			$this->db->query( 'ROLLBACK' ); $this->invalidate(); throw $error;
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' ); $this->invalidate(); throw $error;
		}
		$this->invalidate();
		// An uncertain COMMIT/readback is a refusal, never a retry/replay.
		if ( $this->control( false ) !== $new ) { $this->fail(); }
	}

	private function control( $lock ) {
		$sql = "SELECT IF(OCTET_LENGTH(option_value) <= 2048, option_value, '') AS raw FROM `" . $this->options . '` WHERE option_name = %s LIMIT 1';
		$rows = $this->rows( $this->db->prepare( $sql . ( $lock ? ' FOR UPDATE' : '' ), array( StorageAuthority::CONTROL_OPTION ) ) );
		return 1 === count( $rows ) ? $rows[0]['raw'] : null;
	}

	private function owner( $expected ) {
		$sql = "SELECT IF(OCTET_LENGTH(option_value) <= 512, option_value, '') AS raw FROM `" . $this->options . '` WHERE option_name = %s LIMIT 1 FOR UPDATE';
		$rows = $this->rows( $this->db->prepare( $sql, array( StorageAuthority::MAINTENANCE_OPTION ) ) );
		$marker = json_decode( $expected, true );
		if ( 1 !== count( $rows ) || $expected !== $rows[0]['raw'] || ! is_array( $marker ) ||
			! isset( $marker['blog_id'], $marker['expires'] ) || $this->blog !== $marker['blog_id'] ||
			! is_int( $marker['expires'] ) || $marker['expires'] <= time() ) { $this->fail(); }
	}

	private function option_identity( $name, $lock ) {
		$sql = 'SELECT option_id, OCTET_LENGTH(option_value) AS bytes, SHA2(option_value, 256) AS digest, autoload FROM `' . $this->options . '` WHERE option_name = %s LIMIT 1';
		$rows = $this->rows( $this->db->prepare( $sql . ( $lock ? ' FOR UPDATE' : '' ), array( $name ) ) );
		if ( count( $rows ) && ( (int) $rows[0]['bytes'] > 16777216 || ! preg_match( '/^[a-f0-9]{64}$/D', $rows[0]['digest'] ) ) ) { $this->fail(); }
		return $rows;
	}

	private function rows( $sql ) {
		$this->time(); $rows = $this->db->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) { $this->fail(); }
		$this->time(); return $rows;
	}

	private function write( $sql ) {
		$this->time(); $result = $this->db->query( $sql );
		if ( false === $result || ! empty( $this->db->last_error ) ) { $this->fail(); }
		$this->time(); return $result;
	}

	private function invalidate() {
		foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION ) as $name ) { wp_cache_delete( $name, 'options' ); }
		wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' );
	}

	private function time() { if ( microtime( true ) > $this->deadline ) { $this->fail(); } }
	private function fail() { throw new \RuntimeException( 'Retained migration storage cannot be safely replaced; retain evidence and authority.' ); }
}
