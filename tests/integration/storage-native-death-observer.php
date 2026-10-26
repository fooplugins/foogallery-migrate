<?php
/** Included after real base/core output commits and its exact writer releases. */
if ( ! defined( 'FOOGM_STORAGE_NATIVE_DEATH_ONLY' ) || true !== FOOGM_STORAGE_NATIVE_DEATH_ONLY || PHP_VERSION_ID < 70400 || ! isset( $scenario, $target, $object, $observer, $assert ) ) { throw new RuntimeException( 'Owned modern death fixture required.' ); }
$phase = substr( $scenario, strlen( 'base-death-' ) ); $label = $mode . '/' . $phase;
$capture = function () use ( $observer, $names, $tables, $writers, $object, $source_post ) {
	$state = array( 'writers' => $writers() );
	foreach ( $names as $name ) { $state[$name] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $observer->options . '` WHERE option_name=%s', $name ), ARRAY_A ); }
	foreach ( $tables as $table ) { $state[$table] = $observer->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); }
	foreach ( array( $object->migrated_id, $source_post ) as $id ) {
		$state['post-' . $id] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $observer->posts . '` WHERE ID=%d', $id ), ARRAY_A );
		$state['meta-' . $id] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $observer->postmeta . '` WHERE post_id=%d ORDER BY meta_id', $id ), ARRAY_A );
	}
	if ( ! empty( $observer->last_error ) ) { throw new RuntimeException( 'Independent observer failed.' ); }
	return $state;
};
// Shared-table hashes contain binary bytes. json_encode may return false;
// serialize preserves every captured byte instead of hashing an empty string.
$digest = function ( $state ) { return hash( 'sha256', serialize( $state ) ); };
$process = null; $pipes = array();
$start = function ( $operation ) use ( &$process, &$pipes, $blog, $root, $target, $phase, $object ) {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/storage-native-death-worker.php', rtrim( ABSPATH, '/' ) ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start owned receiver.' ); }
	fwrite( $pipes[0], json_encode( array( 'blog_id' => $blog, 'plugin' => $root, 'target' => $target, 'phase' => $phase, 'mode' => $operation, 'migrated_id' => (int) $object->migrated_id ) ) . "\n" ); fflush( $pipes[0] );
	stream_set_timeout( $pipes[1], 30 ); $line = fgets( $pipes[1], 4096 );
	if ( ! is_string( $line ) ) {
		$status = proc_get_status( $process );
		$detail = $status['running'] ? 'receiver still running' : 'exit=' . $status['exitcode'] . ' stderr=' . stream_get_contents( $pipes[2], 4096 );
		throw new RuntimeException( 'Missing bounded receiver handshake: ' . $detail );
	}
	return json_decode( $line, true );
};
$finish = function () use ( &$process, &$pipes ) {
	if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); }
	foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } } $pipes = array();
	if ( is_resource( $process ) ) { proc_close( $process ); } $process = null;
};
try {
	$hello = $start( 'hold' ); $status = proc_get_status( $process );
	$assert( is_array( $hello ) && $hello['pid'] === $status['pid'] && $status['running'] && $hello['phase'] === $phase && $hello['database'] === DB_NAME && $hello['thread'] > 0, 'exact live public switch PID and SQL session ' . $label );
	$assert( DB_NAME === $observer->get_var( $observer->prepare( 'SELECT DB FROM information_schema.PROCESSLIST WHERE ID=%d', $hello['thread'] ) ), 'independent native public switch session identity ' . $label );
	$before = $capture();
	$name = \FooPlugins\FooGalleryMigrate\StorageAuthority::MAINTENANCE_OPTION;
	$assert( 1 === count( $before[$name] ) && array() === $before['writers'], 'original output writer drained but conversion owner retained ' . $label );
	$control = json_decode( $before[\FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION][0]['option_value'], true );
	$assert( $phase === $control['phase'] && ( in_array( $phase, array( 'flipped', 'stable' ), true ) ? $target : $mode ) === $control['backend'], 'actual public phase and authority independent of table presence ' . $label );
	$assert( proc_terminate( $process, 9 ), 'kill only invocation-owned public receiver ' . $label );
	$deadline = microtime( true ) + 5;
	do { $status = proc_get_status( $process ); if ( ! $status['running'] ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
	$assert( ! $status['running'] && $status['signaled'] && 9 === $status['termsig'], 'OS proves physical public receiver SIGKILL ' . $label );
	fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = array(); proc_close( $process ); $process = null;
	$assert( '' === $out && '' === $err, 'no finally JSON or shutdown-handler receipt after death ' . $label );
	$deadline = microtime( true ) + 5;
	do { $live = $observer->get_var( $observer->prepare( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=%d', $hello['thread'] ) ); if ( null === $live ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
	$assert( null === $live && empty( $observer->last_error ), 'independent observer sees dead receiver SQL session ' . $label );
	$after = $capture();
	$assert( $before === $after, 'hard death retains complete committed storage and real output ' . $label );
	$probes = array();
	foreach ( array( 'retained', 'expired' ) as $age ) {
		if ( 'expired' === $age ) {
			// Fault injection ONLY. Never a product age/death/adoption rule.
			$owner = $after[$name][0]; $record = json_decode( $owner['option_value'], true );
			$assert( array_keys( $record ) === array( 'version', 'blog_id', 'token', 'expires' ) && 1 === $record['version'] && $blog === $record['blog_id'], 'captured v1 evidence has no provable process/session identity ' . $label );
			$record['expires'] = time() - 1;
			$assert( 1 === $observer->query( $observer->prepare( 'UPDATE `' . $observer->options . '` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s', json_encode( $record ), $name, $owner['option_value'] ) ), 'fixture alone ages exact killed owner ' . $label );
		}
		$frozen = $capture(); $probe = $start( 'probe' ); $fresh_status = proc_get_status( $process );
		fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = array(); $rc = proc_close( $process ); $process = null;
		$assert( 0 === $rc && '' === $out && '' === $err && is_array( $probe ) && $probe['pid'] === $fresh_status['pid'] && $probe['pid'] !== getmypid() && $probe['pid'] !== $hello['pid'], 'separately bootstrapped purpose-authorized public recovery ' . $label . '/' . $age );
		$assert( false === $probe['response']['success'] && 'foogallery_migrate_storage_recovery_required' === $probe['response']['data']['code'] && true === $probe['runtime_blocked'] && true === $probe['output_retained'], 'dead or aged owner blocks even completed output and stable destination ' . $label . '/' . $age );
		$assert( $frozen === $capture(), 'fresh refused recovery neither clears owner nor rewrites any backend/output ' . $label . '/' . $age );
		$probes[] = array( 'age' => $age, 'exit' => $rc, 'stderr_bytes' => strlen( $err ), 'state_sha256' => $digest( $frozen ), 'receipt' => $probe );
	}
	$native_deaths[] = array( 'from' => $mode, 'to' => $target, 'phase' => $phase, 'backend' => $control['backend'], 'blog_id' => $blog, 'parent_pid' => getmypid(), 'worker_pid' => $hello['pid'], 'thread' => $hello['thread'], 'signal' => $status['termsig'], 'migrated_id' => (int) $object->migrated_id, 'before_sha256' => $digest( $before ), 'after_sha256' => $digest( $after ), 'probes' => $probes );
} finally { $finish(); }
// Only outer invocation teardown may remove these rows; no recovery is run after
// removal and no success is claimed from cleanup. Preserve evidence until here.
wp_cache_flush(); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
