<?php
/** Fixed, single-site migration-storage cleanup. Never touches posts or media. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageCleanup {
	const MARKER = 'foogallery_migrate_storage_cleanup';
	const TRANSIENT_PREFIX = 'foogallery_migrate_content_result_';
	private $db;
	private $gate;
	private $blog;
	private $options;
	private $marker;
	private $receipt;

	public static function dispatch( $action, $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden' ); }
		if ( is_multisite() ) { return self::error( 'invalid_request' ); }
		if ( ! in_array( $action, array( 'clear', 'remove_all' ), true ) || ! is_array( $request ) ||
			array_keys( $request ) !== array( 'blog_id', 'scope', 'target', 'confirmation', 'nonce' ) ||
			! is_int( get_current_blog_id() ) || get_current_blog_id() < 1 ||
			( $request['blog_id'] !== get_current_blog_id() && $request['blog_id'] !== (string) get_current_blog_id() ) ||
			'site' !== $request['scope'] || $request['target'] !== ( 'clear' === $action ? 'state' : 'all' ) ||
			$request['confirmation'] !== ( 'clear' === $action ? 'clear-current-state' : 'remove-all-internal-data' ) ||
			! is_string( $request['nonce'] ) ) { return self::error( 'invalid_request' ); }
		if ( ! wp_verify_nonce( $request['nonce'], 'foogallery_migrate_storage_' . $action . '_' . get_current_blog_id() ) ) { return self::error( 'invalid_nonce' ); }
		try { return ( new self() )->run( $action ); }
		catch ( \Exception $error ) { return self::error( 'recovery_required' ); }
		catch ( \Throwable $error ) { return self::error( 'recovery_required' ); }
	}

	/** Uninstall has WordPress's own authorization, not an admin nonce. */
	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { return self::error( 'invalid_request' ); }
		try { $result = is_multisite() ? self::uninstall_multisite_options() : ( new self() )->run( 'remove_all' ); }
		catch ( \Exception $error ) { wp_die( esc_html__( 'FooGallery Migrate cleanup could not complete. Plugin files were retained.', 'foogallery-migrate' ) ); return self::error( 'recovery_required' ); }
		catch ( \Throwable $error ) { wp_die( esc_html__( 'FooGallery Migrate cleanup could not complete. Plugin files were retained.', 'foogallery-migrate' ) ); return self::error( 'recovery_required' ); }
		// WordPress proceeds to remove plugin files when uninstall.php returns.
		// A bounded cleanup yield or any refusal must therefore abort deletion.
		if ( empty( $result['complete'] ) ) { wp_die( esc_html__( 'FooGallery Migrate internal cleanup is incomplete. Plugin files were retained; resolve the reported storage ownership or retry uninstall.', 'foogallery-migrate' ) ); }
		return $result;
	}

	/** Multisite remains option-only. Never provision, inspect or drop tables here. */
	private static function uninstall_multisite_options() {
		$complete = true;
		$absent = new \stdClass();
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog ) {
			switch_to_blog( $blog );
			try {
				foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageDiscoverySummary::OPTION ) as $name ) {
					delete_option( $name );
					if ( $absent !== get_option( $name, $absent ) ) { $complete = false; }
				}
			} catch ( \Exception $error ) { restore_current_blog(); throw $error; }
			catch ( \Throwable $error ) { restore_current_blog(); throw $error; }
			restore_current_blog();
		}
		return array( 'complete' => $complete );
	}

	public static function quiescent() {
		global $foogallery_migrate_engine_instance;
		if ( ! isset( $foogallery_migrate_engine_instance ) ) { return true; }
		return class_exists( 'FooPlugins\FooGalleryMigrate\MigratorEngine', false ) &&
			MigratorEngine::storage_maintenance_is_quiescent();
	}

	/** Read-only hint for the existing clear form while ordinary engine reads are blocked. */
	public static function pending_scope() {
		global $wpdb;
		if ( is_multisite() ) { return null; }
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) { return null; }
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 1', self::MARKER ) );
		if ( ! empty( $wpdb->last_error ) || ! is_string( $raw ) || strlen( $raw ) > 2048 ) { return null; }
		$marker = json_decode( $raw, true );
		return is_array( $marker ) && array_keys( $marker ) === array( 'version', 'blog_id', 'action', 'user_cursor', 'users_done' ) &&
			json_encode( $marker ) === $raw && 1 === $marker['version'] && get_current_blog_id() === $marker['blog_id'] &&
			in_array( $marker['action'], array( 'clear', 'remove_all' ), true ) ? $marker['action'] : null;
	}

	private function __construct() {
		global $wpdb;
		if ( is_multisite() || ! is_object( $wpdb ) || ! isset( $wpdb->prefix, $wpdb->base_prefix, $wpdb->options ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) || $wpdb->prefix !== $wpdb->base_prefix ||
			$wpdb->options !== $wpdb->prefix . 'options' ) { throw new \RuntimeException( 'Single-site cleanup context is unavailable.' ); }
		$this->db = $wpdb; $this->blog = get_current_blog_id(); $this->options = $wpdb->options;
		$this->gate = StorageMaintenanceGate::current();
	}

	private function run( $action ) {
		$this->receipt = array( 'action' => $action, 'complete' => false, 'removed' => array(),
			'absent' => array(), 'failed' => array(), 'residual' => array(),
			'message' => 'Migration storage cleanup is incomplete; review residual resources before retrying.' );
		$owned = false;
		try {
			$this->required( $this->gate->acquire_maintenance(), 'maintenance' ); $owned = true;
			$this->required( $this->gate->maintenance_ready( array( __CLASS__, 'quiescent' ) ), 'worker drain' );
			if ( $this->present( LegacyStorageSchema::LOCK_OPTION ) ) { throw new \RuntimeException( 'Ambiguous schema-upgrade owner.' ); }
			if ( 'clear' === $action ) { $this->clear_authority(); }
			$this->claim( $action );
			if ( ! $this->content_transients() ) {
				$this->receipt['residual'][] = 'content transients pending';
				$this->receipt['residual'][] = self::MARKER;
				$this->required( $this->gate->release_maintenance(), 'maintenance release' );
				return $this->receipt;
			}
			$this->delete_option( StorageAuthority::STORAGE_BACKUP_OPTION );
			$this->delete_option( StorageDiscoverySummary::OPTION );
			if ( 'clear' === $action ) {
				$this->clear_option();
				$this->delete_rows();
			} else {
				$this->delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );
				$this->delete_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS );
				$this->drop_all_tables();
				// Mode evidence is retired only after both backends are gone.
				$this->delete_option( StorageAuthority::AUTHORITY_OPTION );
				$this->delete_option( StorageAuthority::CONTROL_OPTION );
				$this->delete_option( LegacyStorageSchema::VERSION_OPTION );
			}
			$this->audit( $action );
			if ( count( $this->receipt['residual'] ) ) { throw new \RuntimeException( 'Owned cleanup resources remain.' ); }
			$this->required( $this->gate->release_maintenance(), 'maintenance release' ); $owned = false;
			$this->delete_marker();
			$this->receipt['complete'] = true;
			$this->receipt['message'] = 'Migration storage cleanup completed; migrated galleries and media were kept.';
		} catch ( \Exception $error ) {
			$this->receipt['failed'][] = $error->getMessage();
			if ( $owned ) { $released = $this->gate->release_maintenance(); if ( true !== $released ) { $this->receipt['failed'][] = 'maintenance release'; } }
			$this->observed_residuals( $action );
		} catch ( \Throwable $error ) {
			$this->receipt['failed'][] = 'Cleanup encountered an unavailable runtime dependency.';
			if ( $owned ) { $released = $this->gate->release_maintenance(); if ( true !== $released ) { $this->receipt['failed'][] = 'maintenance release'; } }
			$this->observed_residuals( $action );
		}
		return $this->receipt;
	}
	/** Best-effort fixed-name inventory, even after a partially applied DDL step. */
	private function observed_residuals( $action ) {
		try {
			$db = $this->gate->connection();
			$names = array( self::MARKER, StorageAuthority::MAINTENANCE_OPTION,
				StorageAuthority::STORAGE_BACKUP_OPTION, StorageDiscoverySummary::OPTION );
			if ( 'remove_all' === $action ) { $names = array_merge( $names, array( FOOGALLERY_MIGRATE_OPTION_DATA,
				FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::CONTROL_OPTION,
				LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) ); }
			foreach ( $names as $name ) {
				$value = $db->get_var( $db->prepare( 'SELECT 1 AS present FROM `' . $this->options . '` WHERE option_name=%s LIMIT 1', array( $name ) ) );
				if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Residual option probe unavailable.' ); }
				if ( null !== $value && ! in_array( $name, $this->receipt['residual'], true ) ) { $this->receipt['residual'][] = $name; }
			}
			if ( 'clear' === $action ) {
				$hash = $db->get_var( $db->prepare( 'SELECT SHA2(option_value,256) FROM `' . $this->options . '` WHERE option_name=%s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
				if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Residual state probe unavailable.' ); }
				if ( null !== $hash && $hash !== hash( 'sha256', serialize( array() ) ) ) { $this->receipt['residual'][] = FOOGALLERY_MIGRATE_OPTION_DATA . ':state'; }
			}
			$tables = array();
			if ( 'remove_all' === $action ) { $tables[] = $db->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX;
				$tables[] = $db->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX; }
			foreach ( $tables as $table ) {
				$found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', array( $db->esc_like( $table ) ) ) );
				if ( ! empty( $db->last_error ) || null !== $found && $table !== $found ) { throw new \RuntimeException( 'Residual table probe unavailable.' ); }
				if ( null !== $found ) { $this->receipt['residual'][] = $table; }
			}
			if ( 'clear' === $action ) {
				foreach ( array( StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX, StorageAuthority::SHARED_STATE_TABLE_SUFFIX ) as $suffix ) {
					$table = $db->base_prefix . $suffix;
					$found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', array( $db->esc_like( $table ) ) ) );
					if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Residual table probe unavailable.' ); }
					if ( null !== $found ) {
						$count = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', array( $this->blog ) ) );
						if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Residual row probe unavailable.' ); }
						if ( '0' !== (string) $count ) { $this->receipt['residual'][] = $table . ':site-rows'; }
					}
				}
			}
			$writer = $db->get_var( $db->prepare( 'SELECT 1 AS present FROM `' . $this->options . '` WHERE option_name LIKE %s LIMIT 1',
				array( $db->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) ) );
			if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Residual writer probe unavailable.' ); }
			if ( null !== $writer ) { $this->receipt['residual'][] = StorageMaintenanceGate::WRITER_PREFIX . '*'; }
		} catch ( \Exception $error ) { $this->receipt['failed'][] = 'Bounded residual readback unavailable; preserve ownership evidence and inspect owned SQL.'; }
		catch ( \Throwable $error ) { $this->receipt['failed'][] = 'Bounded residual readback unavailable; preserve ownership evidence and inspect owned SQL.'; }
	}

	private function required( $result, $name ) {
		if ( true !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Private callers pass fixed operation labels; diagnostic receipts are escaped in HTML and displayed with textContent in the request UI.
			throw new \RuntimeException( $name . ' refused; no owner was taken over.' );
		}
	}
	private function connection() { $this->required( $this->gate->renew_maintenance(), 'maintenance renewal' ); return $this->gate->connection(); }
	private function query( $sql ) {
		$db = $this->connection(); $result = $db->query( $sql );
		if ( false === $result || ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Owned cleanup SQL failed.' ); }
		return $result;
	}
	private function scalar( $sql ) {
		$db = $this->connection(); $result = $db->get_var( $sql );
		if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Owned cleanup read failed.' ); }
		return $result;
	}
	private function invalidate( $name ) {
		wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
	private function present( $name ) {
		$db = $this->connection(); return null !== $this->scalar( $db->prepare( 'SELECT 1 AS present FROM `' . $this->options . '` WHERE option_name=%s LIMIT 1', array( $name ) ) );
	}
	private function raw_small( $name ) {
		$db = $this->connection();
		return $this->scalar( $db->prepare( "SELECT CONCAT('v:', CASE WHEN OCTET_LENGTH(option_value)<=2048 THEN option_value ELSE '' END) FROM `" . $this->options . '` WHERE option_name=%s LIMIT 1', array( $name ) ) );
	}
	private function clear_authority() {
		$control = $this->raw_small( StorageAuthority::CONTROL_OPTION );
		$sentinel = $this->raw_small( StorageAuthority::AUTHORITY_OPTION );
		if ( null === $control ) {
			if ( null !== $sentinel ) { throw new \RuntimeException( 'Orphan storage authority sentinel.' ); }
			if ( ! $this->present( self::MARKER ) && is_wp_error( StorageAuthority::option_mode_error( $this->gate, true ) ) ) {
				throw new \RuntimeException( 'Option authority is not unambiguous.' );
			}
			return;
		}
		$r = StorageControl::decode( substr( $control, 2 ), $this->blog );
		if ( ! StorageControl::runtime_ready( $r, null === $sentinel ? null : substr( $sentinel, 2 ) ) ) {
			throw new \RuntimeException( 'Storage authority is not stable.' );
		}
		if ( 'table' === $r['backend'] ) {
			if ( ! $this->present( self::MARKER ) && $this->present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) { throw new \RuntimeException( 'Selected table has a live option collision.' ); }
			$schema = new LegacyStorageSchema( $this->connection() );
			$definitions = $schema->definitions();
			foreach ( array_keys( $definitions ) as $table ) { if ( ! $this->table_present( $table ) ) { throw new \RuntimeException( 'Selected shared schema is missing.' ); } }
			$schema->verify();
		} elseif ( ! $this->present( self::MARKER ) && ! $this->present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) {
			throw new \RuntimeException( 'Selected option storage is missing.' );
		}
	}
	private function claim( $action ) {
		$raw = $this->raw_small( self::MARKER );
		if ( null === $raw ) {
			$this->marker = array( 'version' => 1, 'blog_id' => $this->blog, 'action' => $action, 'user_cursor' => 0, 'users_done' => false );
			$db = $this->connection(); $value = json_encode( $this->marker );
			$this->required( 1 === $this->query( $db->prepare( 'INSERT IGNORE INTO `' . $this->options . "` (option_name,option_value,autoload) VALUES (%s,%s,'no')", array( self::MARKER, $value ) ) ), 'cleanup marker insertion' );
			$this->invalidate( self::MARKER ); $raw = $this->raw_small( self::MARKER );
		}
		$value = substr( $raw, 2 ); $marker = json_decode( $value, true );
		if ( ! is_array( $marker ) || array_keys( $marker ) !== array( 'version', 'blog_id', 'action', 'user_cursor', 'users_done' ) ||
			json_encode( $marker ) !== $value || 1 !== $marker['version'] || $this->blog !== $marker['blog_id'] ||
			$action !== $marker['action'] || ! is_int( $marker['user_cursor'] ) || $marker['user_cursor'] < 0 ||
			! is_bool( $marker['users_done'] ) ) { throw new \RuntimeException( 'Cleanup marker requires assisted classification.' ); }
		$this->marker = $marker;
	}
	private function save_marker() {
		$db = $this->connection(); $old = $this->raw_small( self::MARKER );
		if ( null === $old ) { throw new \RuntimeException( 'Cleanup marker disappeared.' ); }
		$value = json_encode( $this->marker );
		$this->required( 1 === $this->query( $db->prepare( 'UPDATE `' . $this->options . '` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s',
			array( $value, self::MARKER, substr( $old, 2 ) ) ) ), 'cleanup cursor update' );
		$this->invalidate( self::MARKER );
	}
	private function content_transients() {
		$db = $this->connection(); $processed = 0;
		while ( ! $this->marker['users_done'] && $processed < 1000 ) {
			$rows = $db->get_results( $db->prepare( 'SELECT ID FROM `' . $db->base_prefix . 'users` WHERE ID > %d ORDER BY ID LIMIT 100', array( $this->marker['user_cursor'] ) ), ARRAY_A );
			if ( ! is_array( $rows ) || ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Transient user enumeration failed.' ); }
			foreach ( $rows as $row ) {
				$id = (int) $row['ID']; if ( $id <= $this->marker['user_cursor'] ) { throw new \RuntimeException( 'Transient user order changed.' ); }
				delete_transient( self::TRANSIENT_PREFIX . $id );
				wp_cache_delete( self::TRANSIENT_PREFIX . $id, 'transient' );
				$this->marker['user_cursor'] = $id; ++$processed;
			}
			$this->marker['users_done'] = count( $rows ) < 100;
			$this->save_marker();
		}
		if ( ! $this->marker['users_done'] ) { return false; }
		$prefix = '_transient_' . self::TRANSIENT_PREFIX;
		$timeout = '_transient_timeout_' . self::TRANSIENT_PREFIX;
		$names = $db->get_results( $db->prepare( 'SELECT option_name FROM `' . $this->options . '` WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name LIMIT 101',
			array( $db->esc_like( $prefix ) . '%', $db->esc_like( $timeout ) . '%' ) ), ARRAY_A );
		if ( ! is_array( $names ) || ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Transient option enumeration failed.' ); }
		foreach ( array_slice( $names, 0, 100 ) as $row ) {
			$name = $row['option_name']; $base = 0 === strpos( $name, $timeout ) ? $timeout : $prefix;
			$id = substr( $name, strlen( $base ) );
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) { throw new \RuntimeException( 'Unclassified owned transient name.' ); }
			delete_transient( self::TRANSIENT_PREFIX . $id ); $this->delete_option( $name );
		}
		return count( $names ) <= 100;
	}
	private function delete_option( $name ) {
		$db = $this->connection(); $had = $this->present( $name );
		if ( $had ) { $this->query( $db->prepare( 'DELETE FROM `' . $this->options . '` WHERE option_name=%s', array( $name ) ) ); }
		$this->invalidate( $name );
		if ( $this->present( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Name is a fixed owned option or a validated transient-prefix/numeric-user name, not its value; receipt HTML is escaped and browser diagnostics use textContent.
			throw new \RuntimeException( 'Owned option remained: ' . $name );
		}
		$this->receipt[$had ? 'removed' : 'absent'][] = $name;
	}
	private function clear_option() {
		$control = $this->raw_small( StorageAuthority::CONTROL_OPTION );
		$backend = null === $control ? 'option' : StorageControl::decode( substr( $control, 2 ), $this->blog )['backend'];
		if ( 'table' === $backend ) { $this->delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); return; }
		$db = $this->connection(); $empty = serialize( array() );
		if ( ! $this->present( FOOGALLERY_MIGRATE_OPTION_DATA ) && null === $control ) {
			$this->receipt['absent'][] = FOOGALLERY_MIGRATE_OPTION_DATA; return;
		}
		if ( $this->present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) {
			$this->query( $db->prepare( "UPDATE `" . $this->options . "` SET option_value=%s,autoload='no' WHERE option_name=%s", array( $empty, FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		} else {
			$this->required( 1 === $this->query( $db->prepare( "INSERT INTO `" . $this->options . "` (option_name,option_value,autoload) VALUES (%s,%s,'no')", array( FOOGALLERY_MIGRATE_OPTION_DATA, $empty ) ) ), 'empty option insertion' );
		}
		$this->invalidate( FOOGALLERY_MIGRATE_OPTION_DATA );
		$hash = $this->scalar( $db->prepare( 'SELECT SHA2(option_value,256) FROM `' . $this->options . '` WHERE option_name=%s', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		if ( $hash !== hash( 'sha256', $empty ) ) { throw new \RuntimeException( 'Empty current option did not persist.' ); }
		$this->receipt['removed'][] = FOOGALLERY_MIGRATE_OPTION_DATA . ':state';
	}
	private function table_present( $table ) {
		$db = $this->connection(); $found = $this->scalar( $db->prepare( 'SHOW TABLES LIKE %s', array( $db->esc_like( $table ) ) ) );
		if ( null !== $found && $found !== $table ) { throw new \RuntimeException( 'Unexpected table identity.' ); }
		return null !== $found;
	}
	private function delete_rows() {
		$db = $this->connection();
		foreach ( array( StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX, StorageAuthority::SHARED_STATE_TABLE_SUFFIX ) as $suffix ) {
			$table = $db->base_prefix . $suffix;
			if ( ! $this->table_present( $table ) ) { $this->receipt['absent'][] = $table; continue; }
			$count = $this->scalar( $db->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', array( $this->blog ) ) );
			$this->query( $db->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id=%d', array( $this->blog ) ) );
			if ( '0' !== (string) $this->scalar( $db->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', array( $this->blog ) ) ) ) { throw new \RuntimeException( 'Owned table rows remain.' ); }
			$this->receipt[(int) $count ? 'removed' : 'absent'][] = $table . ':site-rows';
		}
	}
	private function drop_all_tables() {
		foreach ( array( StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX, StorageAuthority::SHARED_STATE_TABLE_SUFFIX ) as $suffix ) { $this->drop_table( $this->db->base_prefix . $suffix ); }
	}
	private function drop_table( $table ) {
		$had = $this->table_present( $table );
		if ( $had ) { $this->query( 'DROP TABLE `' . $table . '`' ); }
		if ( $this->table_present( $table ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Private callers build only the two owned table names from a validated prefix. The exception/receipt is plain data; HTML consumers escape it.
			throw new \RuntimeException( 'Owned table remained: ' . $table );
		}
		$this->receipt[$had ? 'removed' : 'absent'][] = $table;
	}
	private function audit( $action ) {
		foreach ( array( StorageAuthority::STORAGE_BACKUP_OPTION, StorageDiscoverySummary::OPTION ) as $name ) {
			if ( $this->present( $name ) ) { $this->receipt['residual'][] = $name; }
		}
		if ( 'clear' === $action ) {
			$control = $this->raw_small( StorageAuthority::CONTROL_OPTION );
			if ( null === $control || 'option' === StorageControl::decode( substr( $control, 2 ), $this->blog )['backend'] ) {
				$empty = serialize( array() );
				$hash = $this->scalar( $this->connection()->prepare( 'SELECT SHA2(option_value,256) FROM `' . $this->options . '` WHERE option_name=%s', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
				if ( null !== $hash && $hash !== hash( 'sha256', $empty ) || null !== $control && null === $hash ) { $this->receipt['residual'][] = FOOGALLERY_MIGRATE_OPTION_DATA; }
			} elseif ( null !== $control && $this->present( FOOGALLERY_MIGRATE_OPTION_DATA ) ) { $this->receipt['residual'][] = FOOGALLERY_MIGRATE_OPTION_DATA; }
			foreach ( array( StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX, StorageAuthority::SHARED_STATE_TABLE_SUFFIX ) as $suffix ) {
				$table = $this->db->base_prefix . $suffix;
				if ( $this->table_present( $table ) && '0' !== (string) $this->scalar( $this->connection()->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', array( $this->blog ) ) ) ) { $this->receipt['residual'][] = $table; }
			}
		} else {
			foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION,
				StorageAuthority::AUTHORITY_OPTION,
				LegacyStorageSchema::VERSION_OPTION ) as $name ) { if ( $this->present( $name ) ) { $this->receipt['residual'][] = $name; } }
			foreach ( array( StorageAuthority::SHARED_STATE_TABLE_SUFFIX, StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX ) as $suffix ) {
				if ( $this->table_present( $this->db->base_prefix . $suffix ) ) { $this->receipt['residual'][] = $suffix; }
			}
		}
		$db = $this->connection();
		$prefix = '_transient_' . self::TRANSIENT_PREFIX;
		$timeout = '_transient_timeout_' . self::TRANSIENT_PREFIX;
		$transient = $this->scalar( $db->prepare( 'SELECT 1 AS present FROM `' . $this->options . '` WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1',
			array( $db->esc_like( $prefix ) . '%', $db->esc_like( $timeout ) . '%' ) ) );
		if ( null !== $transient ) { $this->receipt['residual'][] = self::TRANSIENT_PREFIX . '*'; }
	}
	private function delete_marker() {
		$db = $this->gate->connection(); $raw = $this->raw_marker_after_release();
		if ( null === $raw || substr( $raw, 2 ) !== json_encode( $this->marker ) ) { throw new \RuntimeException( 'Cleanup marker changed before final removal.' ); }
		$result = $db->query( $db->prepare( 'DELETE FROM `' . $this->options . '` WHERE option_name=%s AND BINARY option_value=BINARY %s', array( self::MARKER, substr( $raw, 2 ) ) ) );
		$this->invalidate( self::MARKER );
		if ( 1 !== $result || ! empty( $db->last_error ) || null !== $this->raw_marker_after_release() ) { throw new \RuntimeException( 'Cleanup marker removal failed.' ); }
		$this->receipt['removed'][] = self::MARKER;
	}
	private function raw_marker_after_release() {
		$db = $this->gate->connection(); $raw = $db->get_var( $db->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->options . '` WHERE option_name=%s LIMIT 1', array( self::MARKER ) ) );
		if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Cleanup marker readback failed.' ); }
		return $raw;
	}
	private static function error( $suffix ) { return new \WP_Error( 'foogallery_migrate_storage_' . $suffix,
		__( 'Migration storage cleanup refused. Retain current storage and ownership evidence.', 'foogallery-migrate' ) ); }
}
