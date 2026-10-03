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
	public $base_prefix;
	public $last_error = '';
	public $insert_id = 0;
	private $wpdb;
	private $handle;

	/** Only an existing mysqli WordPress connection is currently supported. */
	public function __construct( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! class_exists( 'mysqli', false ) || ! ( $wpdb->dbh instanceof \mysqli ) ) {
			throw new \RuntimeException( 'Legacy transaction connection: a pinned mysqli connection is required; retain current authority.' );
		}
		$this->wpdb = $wpdb;
		$this->handle = $wpdb->dbh;
		$this->base_prefix = $wpdb->base_prefix;
	}

	/** Use WordPress placeholder/escaping rules, never its query/reconnect path. */
	public function prepare( $sql, $args ) {
		try {
			$result = $this->wpdb->prepare( $sql, $args );
			if ( ! is_string( $result ) || '' === $result ) { throw new \RuntimeException( 'Invalid prepared query.' ); }
			return $result;
		} catch ( \Exception $error ) {
			throw new \RuntimeException( 'Legacy transaction connection: query preparation failed.' );
		} catch ( \Throwable $error ) {
			// PHP 7+ closed-handle/type errors; this catch is inert on PHP 5.4.
			throw new \RuntimeException( 'Legacy transaction connection: query preparation failed.' );
		}
	}

	public function esc_like( $value ) {
		return $this->wpdb->esc_like( $value );
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

	private function execute( $sql ) {
		$this->last_error = ''; $this->insert_id = 0;
		try {
			$result = @mysqli_query( $this->handle, $sql );
			if ( false === $result ) { $this->last_error = 'Pinned storage query failed; no replay.'; return false; }
			$this->insert_id = $this->handle->insert_id;
			return $result;
		} catch ( \Exception $error ) {
			$this->last_error = 'Pinned storage query failed; no replay.'; return false;
		} catch ( \Throwable $error ) {
			$this->last_error = 'Pinned storage query failed; no replay.'; return false;
		}
	}
}
