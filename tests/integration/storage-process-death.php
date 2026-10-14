<?php
/** Native SIGKILL schedules: conservative refusal, NOT safe stale-owner admission. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;

if ( PHP_VERSION_ID < 70400 || ! function_exists( 'proc_open' ) ) { throw new RuntimeException( 'This native process fixture requires PHP7.4+ array-form proc_open.' ); }
global $wpdb;
$blog = (int) get_current_blog_id(); $db = new LegacyTransactionConnection( $wpdb );
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
$pattern = $db->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
$checks = array(); $receipts = array(); $created = array(); $process = null; $pipes = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$options = function () use ( $db, $wpdb, $names, $pattern ) {
	$args = $names; $args[] = $pattern;
	$rows = $db->get_results( $db->prepare( 'SELECT option_name, option_value, autoload FROM `' . $wpdb->options . '` WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ') OR option_name LIKE %s ORDER BY option_name', $args ), ARRAY_A );
	if ( ! is_array( $rows ) || ! empty( $db->last_error ) ) { throw new RuntimeException( 'Independent option observer failed.' ); }
	return $rows;
};
$raw = function () use ( $db, $tables, $options ) {
	$result = array( 'options' => $options() );
	foreach ( $tables as $table ) { $result[$table] = $db->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $result[$table] ) || ! empty( $db->last_error ) ) { throw new RuntimeException( 'Independent table observer failed.' ); } }
	return $result;
};
$reset = function () use ( $db, $wpdb, $blog, $tables, $options, $check ) {
	// Fixture teardown only: exact rows captured by this invocation, never product recovery.
	foreach ( $options() as $row ) {
		$check( 1 === $db->query( $db->prepare( 'DELETE FROM `' . $wpdb->options . '` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $row['option_name'], $row['option_value'] ) ) ), 'fixture-owned exact option teardown ' . $row['option_name'] );
		wp_cache_delete( $row['option_name'], 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	foreach ( array_reverse( $tables ) as $table ) { $check( false !== $db->query( $db->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', array( $blog ) ) ), 'fixture-owned blog row teardown ' . $table ); }
};
$start = function ( $mode, $point ) use ( &$process, &$pipes, $blog ) {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/storage-process-worker.php', rtrim( ABSPATH, '/' ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Owned worker unavailable.' ); }
	$input = array( 'blog_id' => $blog, 'plugin' => FOOGM_STORAGE_TEST_ROOT, 'mode' => $mode, 'point' => $point );
	fwrite( $pipes[0], json_encode( $input ) . "\n" ); fflush( $pipes[0] );
	stream_set_timeout( $pipes[1], 30 );
	$line = fgets( $pipes[1], 4096 );
	if ( ! is_string( $line ) ) { throw new RuntimeException( 'Owned worker did not return a bounded handshake.' ); }
	return json_decode( $line, true );
};
$finish = function () use ( &$process, &$pipes ) {
	foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
	$pipes = array();
	if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); }
	$process = null;
};
foreach ( $tables as $table ) { $check( null === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', array( $db->esc_like( $table ) ) ) ) && empty( $db->last_error ), 'refuse pre-existing table ' . $table ); }
$check( array() === $options(), 'refuse pre-existing storage evidence and writers' );
$other = $blog + 1000;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'native fixture schema verified' );
	( new LegacyTableSnapshot( $db, $other ) )->replace_many( array( array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => 'other-blog', 'expected_revision' => null ) ) );
	$other_raw = array();
	foreach ( $tables as $table ) { $other_raw[$table] = $db->get_results( $db->prepare( 'SELECT * FROM `' . $table . '` WHERE blog_id = %d ORDER BY id', array( $other ) ), ARRAY_A ); }
	foreach ( array( 'option-writer', 'table-before-commit', 'table-after-commit', 'copying', 'snapshot-commit', 'flipped', 'source-deleted', 'stable' ) as $point ) {
		$reset();
		$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'checkpoint' => 'before' ), false ), $point . ': seed compact option' );
		$hello = $start( 'hold', $point ); $status = proc_get_status( $process );
		$check( is_array( $hello ) && isset( $hello['pid'], $hello['thread'], $hello['point'], $hello['database'] ) && $hello['pid'] === $status['pid'] && $status['running'] && $hello['point'] === $point && $hello['database'] === DB_NAME && is_int( $hello['thread'] ) && $hello['thread'] > 0, $point . ': exact invocation-owned PHP PID and database session handshake' );
		$check( DB_NAME === $db->get_var( $db->prepare( 'SELECT DB FROM information_schema.PROCESSLIST WHERE ID = %d', array( $hello['thread'] ) ) ), $point . ': independent observer verifies owned live session' );
		$before = $raw();
		$check( proc_terminate( $process, 9 ), $point . ': SIGKILL only exact proc_open child' );
		$deadline = microtime( true ) + 5;
		do { $status = proc_get_status( $process ); if ( ! $status['running'] ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
		$check( ! $status['running'] && $status['signaled'] && 9 === $status['termsig'], $point . ': OS verifies hard PHP death by signal9' );
		fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
		$check( '' === $out && '' === $err, $point . ': no shutdown-handler receipt or stderr after death' );
		foreach ( array( 1, 2 ) as $i ) { fclose( $pipes[$i] ); } $pipes = array(); proc_close( $process ); $process = null;
		$deadline = microtime( true ) + 5;
		do { $live = $db->get_var( $db->prepare( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID = %d', array( $hello['thread'] ) ) ); if ( null === $live ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
		$check( null === $live && empty( $db->last_error ), $point . ': independently observe dead database session' );
		$after = $raw();
		$check( $before === $after, $point . ': committed evidence unchanged across death; incomplete transaction is not published' );
		$rows = $options(); $by_name = array(); $writers = array();
		foreach ( $rows as $row ) { $by_name[$row['option_name']] = $row['option_value']; if ( 0 === strpos( $row['option_name'], StorageMaintenanceGate::WRITER_PREFIX ) ) { $writers[] = $row; } }
		$is_writer = in_array( $point, array( 'option-writer', 'table-before-commit', 'table-after-commit' ), true );
		$check( $is_writer ? 1 === count( $writers ) && ! isset( $by_name[StorageAuthority::MAINTENANCE_OPTION] ) : array() === $writers && isset( $by_name[StorageAuthority::MAINTENANCE_OPTION] ), $point . ': exact interrupted owner evidence retained' );
		if ( 'option-writer' === $point ) {
			$check( serialize( array( 'checkpoint' => 'admitted' ) ) === $by_name[FOOGALLERY_MIGRATE_OPTION_DATA], $point . ': independently observe committed option output' );
		} elseif ( $is_writer ) {
			$expected = 'table-after-commit' === $point ? 'committed-without-receipt' : 'admitted';
			$check( array( 'found' => true, 'value' => $expected ) === ( new LegacyTableBackend( $db, $blog ) )->read( 'checkpoint' ), $point . ': independent table value proves actual commit boundary' );
		} else {
			$control = json_decode( $by_name[StorageAuthority::CONTROL_OPTION], true );
			$phase = in_array( $point, array( 'copying', 'snapshot-commit' ), true ) ? 'copying' : ( 'stable' === $point ? 'stable' : 'flipped' );
			$check( $phase === $control['phase'] && ( 'copying' === $phase ? 'option' : 'table' ) === $control['backend'], $point . ': independent recorded phase and authority, not table existence' );
			$check( in_array( $point, array( 'source-deleted', 'stable' ), true ) ? ! isset( $by_name[FOOGALLERY_MIGRATE_OPTION_DATA] ) : isset( $by_name[FOOGALLERY_MIGRATE_OPTION_DATA] ), $point . ': source retained only until actual postflip deletion' );
			if ( 'copying' !== $point ) { $check( array( 'found' => true, 'value' => 'before' ) === ( new LegacyTableBackend( $db, $blog ) )->read( 'checkpoint' ), $point . ': actual destination committed before worker receipt' ); }
		}
		$probe = function ( $label ) use ( $start, &$process, &$pipes, $check, $raw, $point ) {
			$frozen = $raw(); $result = $start( 'probe', $point );
			fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = array();
			$rc = proc_close( $process ); $process = null;
			$check( 0 === $rc && '' === $out && '' === $err && is_array( $result ) && isset( $result['status'], $result['checks'] ) && 'blocked' === $result['status'] && count( $result['checks'] ) >= 5, $point . ': independent fresh request refuses takeover/recovery ' . $label );
			$check( $frozen === $raw(), $point . ': refused fresh request changes no persisted evidence ' . $label );
			return $result;
		};
		$first = $probe( 'live-marker' );
		// Deliberately age only this killed invocation's marker by exact CAS. This
		// is fixture fault injection, not an elapsed-time death or product cleanup.
		$owners = $is_writer ? $writers : array( array( 'option_name' => StorageAuthority::MAINTENANCE_OPTION, 'option_value' => $by_name[StorageAuthority::MAINTENANCE_OPTION] ) );
		foreach ( $owners as $owner ) {
			$record = json_decode( $owner['option_value'], true ); $check( 1 === $record['version'] && $blog === $record['blog_id'], $point . ': captured v1 marker belongs to tested blog' );
			$record['expires'] = time() - 1;
			$check( 1 === $db->query( $db->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', array( json_encode( $record ), $owner['option_name'], $owner['option_value'] ) ) ), $point . ': fixture alone ages exact killed owner' );
		}
		$expired = $probe( 'fixture-expired-marker' );
		foreach ( $tables as $table ) { $check( $other_raw[$table] === $db->get_results( $db->prepare( 'SELECT * FROM `' . $table . '` WHERE blog_id = %d ORDER BY id', array( $other ) ), ARRAY_A ), $point . ': unrelated SQL blog remains identical ' . $table ); }
		$receipts[] = array( 'point' => $point, 'pid' => $hello['pid'], 'thread' => $hello['thread'], 'signal' => $status['termsig'], 'before_sha256' => hash( 'sha256', json_encode( $before ) ), 'after_sha256' => hash( 'sha256', json_encode( $after ) ), 'fresh_probe' => $first, 'expired_probe' => $expired );
	}
} finally {
	$finish();
	$reset();
	foreach ( array_reverse( $created ) as $table ) { $check( false !== $db->query( 'DROP TABLE `' . $table . '`' ), 'drop only invocation-created schema ' . $table ); }
}
$check( array() === $options(), 'fixture restores absence of storage evidence and writers' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'SIGKILL at eight deterministic native SQL/output boundaries with independent committed-state observation; conservative v1 blocking only, NOT stale-owner recovery, lost-network-ACK, exactly-once, literal floors or full output audit', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $db->get_var( 'SELECT VERSION()' ), 'blog_id' => $blog, 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ), 'death_receipts' => $receipts ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
