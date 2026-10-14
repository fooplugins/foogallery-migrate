<?php
/**
 * Pinned mysqli adapter: never reconnects or replays storage SQL.
 * wpdb's automatic reconnect/replay is unsafe inside an owned transaction.
 * This adapter does not open/close connections or select/provision a backend.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyTransactionConnection {
	public $prefix;
	public $base_prefix;
	public $last_error = '';
	public $insert_id = 0;
	private $wpdb;
	private $handle;
	private $thread;
	private $tainted = false;

	/** Only an existing mysqli WordPress connection is currently supported. */
	public function __construct( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! class_exists( 'mysqli', false ) || ! ( $wpdb->dbh instanceof \mysqli ) ) {
			throw new \RuntimeException( 'Legacy transaction connection: a pinned mysqli connection is required; retain current authority.' );
		}
		$this->wpdb = $wpdb;
		$this->handle = $wpdb->dbh;
		$this->thread = $this->handle->thread_id;
		$this->prefix = $wpdb->prefix;
		$this->base_prefix = $wpdb->base_prefix;
	}

	/** Use WordPress placeholder/escaping rules, never its query/reconnect path. */
	public function prepare( $sql, $args ) {
		try {
			if ( ! is_array( $args ) ) { $args = array_slice( func_get_args(), 1 ); }
			if ( ! $this->is_current() ) { throw new \RuntimeException( 'Storage connection changed.' ); }
			$result = $this->wpdb->prepare( $sql, $args );
			if ( ! $this->is_current() || ! is_string( $result ) || '' === $result ) { throw new \RuntimeException( 'Invalid prepared query.' ); }
			return $result;
		} catch ( \Exception $error ) {
			$this->tainted = true;
			throw new \RuntimeException( 'Legacy transaction connection: query preparation failed.' );
		} catch ( \Throwable $error ) {
			// PHP 7+ closed-handle/type errors; this catch is inert on PHP 5.4.
			$this->tainted = true;
			throw new \RuntimeException( 'Legacy transaction connection: query preparation failed.' );
		}
	}

	public function esc_like( $value ) {
		return $this->wpdb->esc_like( $value );
	}

	/** The coordinator must not continue on a superseded WordPress connection. */
	public function is_current() {
		try {
			if ( ! is_object( $this->wpdb ) || $this->wpdb->dbh !== $this->handle || ! ( $this->handle instanceof \mysqli ) ||
				$this->thread !== $this->handle->thread_id || $this->thread < 1 ) { $this->tainted = true; }
		} catch ( \Exception $error ) { $this->tainted = true; }
		catch ( \Throwable $error ) { $this->tainted = true; }
		return ! $this->tainted;
	}

	public function query( $sql ) {
		$result = $this->execute( $sql );
		if ( false === $result ) { return false; }
		if ( $result instanceof \mysqli_result ) {
			$rows = $result->num_rows; $result->free(); return $rows;
		}
		return $this->handle->affected_rows;
	}

	public function get_results( $sql, $format ) {
		if ( ARRAY_A !== $format ) { throw new \RuntimeException( 'Legacy transaction connection: only associative storage rows are supported.' ); }
		$result = $this->execute( $sql );
		if ( ! ( $result instanceof \mysqli_result ) ) {
			$this->last_error = 'Pinned storage read failed.'; return null;
		}
		$rows = array();
		while ( $row = $result->fetch_assoc() ) { $rows[] = $row; }
		$result->free();
		return $rows;
	}

	/** No wpdb query/get_var path: preserve empty values as present SQL data. */
	public function get_var( $sql ) {
		$rows = $this->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! count( $rows ) ) { return null; }
		$first = array_values( $rows[0] );
		return count( $first ) ? $first[0] : null;
	}

	private function execute( $sql ) {
		$this->last_error = ''; $this->insert_id = 0;
		try {
			if ( ! $this->is_current() ) { $this->last_error = 'Pinned storage connection changed; no replay.'; return false; }
			$result = @mysqli_query( $this->handle, $sql );
			if ( false === $result ) {
				// Client/transport errors and failed COMMIT have uncertain outcomes.
				// Server rejections (e.g. a constraint) still permit same-handle rollback.
				if ( $this->handle->errno >= 2000 || 'COMMIT' === strtoupper( trim( $sql ) ) ) { $this->tainted = true; }
				$this->last_error = 'Pinned storage query failed; no replay.'; return false;
			}
			$this->insert_id = $this->handle->insert_id;
			return $result;
		} catch ( \Exception $error ) {
			if ( ! ( $error instanceof \mysqli_sql_exception ) || $error->getCode() >= 2000 || $error->getCode() < 1 ||
				'COMMIT' === strtoupper( trim( $sql ) ) ) { $this->tainted = true; }
			$this->last_error = 'Pinned storage query failed; no replay.'; return false;
		} catch ( \Throwable $error ) {
			$this->tainted = true;
			$this->last_error = 'Pinned storage query failed; no replay.'; return false;
		}
	}
}
