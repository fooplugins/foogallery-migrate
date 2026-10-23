<?php
/**
 * Test-only assisted hard-death procedure on ONE fresh R0 CLI-only site.
 * First prepare the site's synthetic protected page/gallery/attachment with
 * storage-release-cleanup.php init, seed-state, provision, switch, recover
 * (fresh processes) until its public status is verified/option. Never use a
 * retained fixture. This is not an automatic stale-owner release API.
 *
 * php storage-assisted-recovery.php SITE arm|kill|probe|inspect|operator-clear|recover|verify
 * operator-clear additionally requires the exact dead-snapshot SHA-256. The
 * externally prepared attestation and backups are described at that branch.
 */
if ( 'cli' !== PHP_SAPI || ! ( 3 === $argc || ( 4 === $argc && 'operator-clear' === $argv[2] ) ) ||
	! in_array( $argv[2], array( 'arm', 'kill', 'probe', 'inspect', 'operator-clear', 'recover', 'verify' ), true ) ) {
	throw new RuntimeException( 'Exact owned site and assisted-recovery phase required.' );
}
$site = realpath( $argv[1] ); $step = $argv[2];
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ) {
	throw new RuntimeException( 'Only a fresh owned R0 site is eligible.' );
}
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) && ! is_link( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'],
	$manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	$manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $manifest['site'] ) !== $site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	realpath( dirname( __DIR__, 2 ) ) !== realpath( $manifest['repo'] ) ||
	! is_file( $site . '/wp-load.php' ) || ! is_file( $site . '/wp-config.php' ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ) {
	throw new RuntimeException( 'Owned site, source checkout or manifest differs.' );
}
$container = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}} {{.State.Running}}' ) .
	' ' . escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container, $container_code );
if ( 0 !== $container_code || count( $container ) !== 1 || trim( $container[0] ) !== $manifest['container_id'] . ' owned true' ) {
	throw new RuntimeException( 'Owned live SQL container differs.' );
}
define( 'WP_USE_THEMES', false ); define( 'DISABLE_WP_CRON', true ); define( 'WP_MAX_MEMORY_LIMIT', '256M' );
ini_set( 'memory_limit', '256M' ); require $site . '/wp-load.php';
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageCopyCursor;
global $wpdb, $wp_version;
function assisted_check( $yes, $message ) { if ( ! $yes ) { throw new RuntimeException( $message ); } }
assisted_check( ! is_multisite() && realpath( ABSPATH ) === $site && DB_NAME === $manifest['database'] &&
	DB_HOST === $manifest['host'] && $wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] &&
	! $wpdb->last_error && ini_get( 'memory_limit' ) === '256M' && WP_MAX_MEMORY_LIMIT === '256M',
	'Owned site, SQL or effective 256M differs.' );
wp_set_current_user( 1 );
assisted_check( current_user_can( 'manage_options' ) && class_exists( 'FooPlugins\\FooGalleryMigrate\\StorageCli' ),
	'Owned administrator or public storage command unavailable.' );
assisted_check( get_option( 'siteurl' ) === 'http://fgm-r0-' . substr( $site, strrpos( $site, '.' ) + 1 ) . '.invalid',
	'Fixture must have its nonserving .invalid URL; operator still must freeze every entry point.' );
$identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$journal_name = 'fgm_assisted_recovery_v1'; $cleanup_name = 'fgm_cleanup_owned_journey_v1';
$source_files = array( 'migrate.php', 'includes/class-storage-maintenance-gate.php',
	'includes/class-storage-conversion.php', 'includes/class-storage-control.php',
	'includes/class-storage-operations.php', 'includes/class-storage-cli.php',
	'includes/class-storage-authority.php', 'includes/class-storage-admin.php',
	'includes/class-storage-preflight.php', 'includes/class-storage-capacity-policy.php',
	'includes/class-storage-capacity-budget.php', 'includes/storage-capacity-policy.json',
	'includes/class-storage-memory-baseline.php', 'includes/class-storage-scalar-observer.php',
	'includes/class-storage-calibration.php', 'includes/class-storage-scale-admission.php',
	'includes/class-storage-copy-cursor.php', 'includes/class-storage-retirement.php',
	'includes/class-storage-provisioning.php', 'includes/class-legacy-storage-schema.php',
	'includes/class-legacy-state-codec.php', 'includes/class-legacy-record-codec.php',
	'includes/class-legacy-collection-envelope.php',
	'includes/class-legacy-transaction-connection.php', 'includes/class-legacy-table-reader.php',
	'includes/class-legacy-table-repository.php', 'includes/class-legacy-table-backend.php',
	'includes/class-legacy-table-snapshot.php',
	'tests/integration/storage-release-cleanup.php', 'tests/integration/storage-assisted-recovery.php',
	'tests/integration/storage-assisted-recovery-worker.php' );
$source = function () use ( $source_files, $manifest ) {
	$map = array();
	foreach ( $source_files as $file ) {
		$path = $manifest['repo'] . '/' . $file;
		assisted_check( is_file( $path ) && ! is_link( $path ), 'Pinned source path differs: ' . $file );
		$map[$file] = hash_file( 'sha256', $path );
	}
	return $map;
};
$option_row = function ( $name ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name, option_value, autoload FROM `' .
		$wpdb->options . '` WHERE option_name=%s LIMIT 2', $name ), ARRAY_A );
	assisted_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) <= 1, 'Exact option SQL read failed: ' . $name );
	return count( $rows ) ? $rows[0] : null;
};
$writer_rows = function () use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name, option_value, autoload FROM `' .
		$wpdb->options . '` WHERE option_name LIKE %s ORDER BY option_id LIMIT 2',
		$wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ), ARRAY_A );
	assisted_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) <= 1, 'Unexpected writer inventory.' );
	return $rows;
};
$cleanup_row = $option_row( $cleanup_name );
$cleanup = $cleanup_row ? maybe_unserialize( $cleanup_row['option_value'] ) : null;
$suffix = substr( $site, strrpos( $site, '.' ) + 1 );
$owner = 'fgm-cleanup-' . $suffix;
assisted_check( is_array( $cleanup ) && isset( $cleanup['identity'], $cleanup['owner'], $cleanup['phase'],
	$cleanup['posts'], $cleanup['post_hashes'], $cleanup['media_hash'] ) &&
	$cleanup['identity'] === $identity && $cleanup['owner'] === $owner && $cleanup['phase'] === 'state-seeded' &&
	array_keys( $cleanup['posts'] ) === array( 'page', 'foogallery', 'attachment' ) &&
	count( $cleanup['post_hashes'] ) === 3, 'Fresh protected lifecycle source journal differs.' );
$uploads = wp_upload_dir( null, false );
$upload_root = $site . '/wp-content/uploads';
assisted_check( empty( $uploads['error'] ) && $uploads['basedir'] === $upload_root &&
	! is_link( $upload_root ) && realpath( $upload_root ) === $upload_root,
	'Uploads escaped exact owned root.' );
$media = $upload_root . '/' . $owner . '/protected.png';
$protected = function () use ( $cleanup, $cleanup_row, $cleanup_name, $option_row, $wpdb, $owner, $media ) {
	assisted_check( $option_row( $cleanup_name ) === $cleanup_row, 'Original cleanup journal changed.' );
	$owned = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM `' . $wpdb->postmeta .
		'` WHERE meta_key=%s AND meta_value=%s ORDER BY post_id', '_fgm_cleanup_owner', $owner ) );
	assisted_check( is_array( $owned ) && ! $wpdb->last_error && array_map( 'intval', $owned ) === array_values( $cleanup['posts'] ),
		'Protected post identity or count changed.' );
	foreach ( $cleanup['posts'] as $kind => $id ) {
		$post = get_post( $id );
		assisted_check( $post && $post->post_type === $kind && get_post_meta( $id, '_fgm_cleanup_owner', true ) === $owner &&
			hash( 'sha256', $post->post_title . '|' . $post->post_content ) === $cleanup['post_hashes'][$kind],
			'Protected source/output post differs: ' . $kind );
	}
	assisted_check( is_file( $media ) && ! is_link( $media ) && realpath( $media ) === $media &&
		hash_file( 'sha256', $media ) === $cleanup['media_hash'] &&
		get_attached_file( $cleanup['posts']['attachment'] ) === $media, 'Protected media differs.' );
	return hash( 'sha256', serialize( array( $cleanup_row, $cleanup['posts'], $cleanup['post_hashes'], $cleanup['media_hash'] ) ) );
};
$protected_sha = $protected();
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
	StorageAuthority::STORAGE_BACKUP_OPTION, StorageAuthority::CLEANUP_OPTION,
	LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION,
	'foogallery-migrate-settings', 'foogallery-migrate-migrated-legacy-backup',
	'foogallery_migrate_storage', 'foogallery-migrate-store-schema-version',
	'foogallery-migrate-store-migration-version' );
$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
$table_equals_source = function ( $source_row ) use ( $wpdb, $tables ) {
	assisted_check( is_array( $source_row ) && isset( $source_row['option_value'] ), 'Original source option is absent.' );
	$value = maybe_unserialize( $source_row['option_value'] );
	assisted_check( is_array( $value ) && count( $value ) > 0 && count( $value ) <= 16,
		'Bounded original source state differs.' );
	$snapshot = new LegacyTableSnapshot( new LegacyTransactionConnection( $wpdb ), get_current_blog_id() );
	foreach ( $value as $key => $item ) {
		assisted_check( $snapshot->read_bounded( $key ) === $item,
			'Staged/selected table state differs from exact original key.' );
	}
	$heads = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $tables[0] . '` WHERE blog_id=%d',
		get_current_blog_id() ) );
	assisted_check( ! $wpdb->last_error && (int) $heads === count( $value ),
		'Staged/selected table has unexpected or missing state heads.' );
	return hash( 'sha256', serialize( $value ) );
};
$capture = function () use ( $names, $tables, $option_row, $writer_rows, $wpdb, $cleanup, $media ) {
	assisted_check( is_file( $media ) && ! is_link( $media ) && realpath( $media ) === $media,
		'Protected media path changed during snapshot.' );
	$state = array( 'options' => array(), 'writers' => $writer_rows(), 'tables' => array(), 'posts' => array(), 'meta' => array(),
		'media_sha256' => hash_file( 'sha256', $media ) );
	assisted_check( is_string( $state['media_sha256'] ), 'Protected media cannot be read.' );
	foreach ( $names as $name ) { $state['options'][$name] = $option_row( $name ); }
	foreach ( $tables as $table ) {
		$rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A );
		assisted_check( is_array( $rows ) && ! $wpdb->last_error, 'Owned staging SQL read failed: ' . $table );
		$state['tables'][$table] = $rows;
	}
	foreach ( $cleanup['posts'] as $kind => $id ) {
		$state['posts'][$kind] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->posts . '` WHERE ID=%d', $id ), ARRAY_A );
		assisted_check( is_array( $state['posts'][$kind] ) && ! $wpdb->last_error && count( $state['posts'][$kind] ) === 1,
			'Protected raw post SQL differs.' );
		$state['meta'][$kind] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->postmeta .
			'` WHERE post_id=%d ORDER BY meta_id', $id ), ARRAY_A );
		assisted_check( is_array( $state['meta'][$kind] ) && ! $wpdb->last_error,
			'Protected raw metadata SQL differs.' );
	}
	return $state;
};
$snapshot_sha = function ( $state ) { return hash( 'sha256', serialize( $state ) ); };
$journal_row = $option_row( $journal_name );
$journal = $journal_row ? maybe_unserialize( $journal_row['option_value'] ) : null;
$save = function ( $value, $new ) use ( $journal_name, $option_row ) {
	$before = $option_row( $journal_name );
	assisted_check( $new === ( null === $before ), 'Unexpected assisted journal presence.' );
	if ( $new ) { assisted_check( add_option( $journal_name, $value, '', false ), 'Assisted journal insertion failed.' ); }
	else { update_option( $journal_name, $value, false ); }
	$after = $option_row( $journal_name );
	assisted_check( $after && maybe_unserialize( $after['option_value'] ) === $value &&
		get_option( $journal_name, null ) === $value, 'Assisted journal durable readback failed.' );
};
$result = array( 'site' => $site, 'database' => $manifest['database'], 'container_id' => $manifest['container_id'],
	'php' => PHP_VERSION, 'wordpress' => $wp_version, 'step' => $step, 'memory_limit' => ini_get( 'memory_limit' ) );
if ( 'arm' === $step ) {
	assisted_check( null === $journal_row && null === $option_row( StorageAuthority::MAINTENANCE_OPTION ) &&
		array() === $writer_rows() && null === $option_row( StorageAuthority::CLEANUP_OPTION ) &&
		( new LegacyStorageSchema( $wpdb ) )->verify(), 'Fresh verified stage without owner required.' );
	$control_row = $option_row( StorageAuthority::CONTROL_OPTION );
	$control = $control_row ? StorageControl::decode( $control_row['option_value'], get_current_blog_id() ) : null;
	assisted_check( is_array( $control ) && $control['phase'] === 'verified' && $control['backend'] === 'option' &&
		$control['from'] === 'option' && $control['to'] === 'table' && null !== $option_row( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		null === $option_row( StorageAuthority::AUTHORITY_OPTION ) &&
		null === $option_row( StorageAuthority::STORAGE_BACKUP_OPTION ),
		'Exact verified option-to-table stage before retained backup required.' );
	$source_row = $option_row( FOOGALLERY_MIGRATE_OPTION_DATA );
	$source_sha = $table_equals_source( $source_row );
	$source_value = maybe_unserialize( $source_row['option_value'] );
	$proof = 4 === $control['version'] && isset( $control['verify_cursor'] ) ?
		StorageCopyCursor::validate_verify( $control['verify_cursor'] ) : null;
	assisted_check( is_array( $proof ) && is_string( $proof['heads_digest'] ) &&
		$proof['state_order'] === count( $source_value ) && 0 === $proof['next_root_ordinal'] &&
		0 === $proof['next_node_ordinal'], 'Complete verified proof cursor required before arming the flip receiver.' );
	$journal = array( 'identity' => $identity, 'phase' => 'armed', 'source_files' => $source(),
		'cleanup_sha256' => hash( 'sha256', $cleanup_row['option_value'] ), 'protected_sha256' => $protected_sha,
		'source_value_sha256' => $source_sha,
		'control_sha256' => hash( 'sha256', $control_row['option_value'] ),
		'initial_sha256' => $snapshot_sha( $capture() ) );
	$save( $journal, true ); $result['initial_sha256'] = $journal['initial_sha256'];
} else {
	assisted_check( is_array( $journal ) && isset( $journal['identity'], $journal['phase'], $journal['source_files'],
		$journal['cleanup_sha256'], $journal['protected_sha256'] ) && $journal['identity'] === $identity &&
		$journal['source_files'] === $source() && $journal['cleanup_sha256'] === hash( 'sha256', $cleanup_row['option_value'] ) &&
		$journal['protected_sha256'] === $protected_sha, 'Assisted journal, source or protected baseline differs.' );
	$result['phase_before'] = $journal['phase'];
}
if ( 'kill' === $step ) {
	assisted_check( $journal['phase'] === 'armed' && $snapshot_sha( $capture() ) === $journal['initial_sha256'],
		'Exact armed source/staging changed before owned worker.' );
	$worker = __DIR__ . '/storage-assisted-recovery-worker.php';
	$before_journal = $option_row( $journal_name );
	$process = proc_open( array( PHP_BINARY, $worker, $site, hash( 'sha256', $before_journal['option_value'] ) ),
		array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	assisted_check( is_resource( $process ), 'Exact owned child could not start.' );
	try {
		stream_set_timeout( $pipes[1], 30 ); $line = fgets( $pipes[1], 4096 ); $hello = is_string( $line ) ? json_decode( $line, true ) : null;
		$status = proc_get_status( $process );
		if ( ! ( is_array( $hello ) && isset( $hello['pid'], $hello['thread'], $hello['database'], $hello['phase'], $hello['next'] ) &&
			$status['running'] && $hello['pid'] === $status['pid'] && $hello['thread'] > 0 &&
			$hello['database'] === DB_NAME && $hello['phase'] === 'verified' && $hello['next'] === 'flipped' ) ) {
			$exit = ! $status['running'] && isset( $status['exitcode'] ) && is_int( $status['exitcode'] ) ?
				(string) $status['exitcode'] : 'unavailable';
			throw new RuntimeException( 'Exact held worker handshake differs (hello ' . ( is_array( $hello ) ? 'invalid' : 'absent' ) .
				', child ' . ( $status['running'] ? 'running' : 'exited' ) . ', exit ' . $exit . ').' );
		}
		$live = $wpdb->get_var( $wpdb->prepare( 'SELECT DB FROM information_schema.PROCESSLIST WHERE ID=%d', $hello['thread'] ) );
		assisted_check( ! $wpdb->last_error && $live === DB_NAME, 'Held worker SQL session not independently present.' );
		$before = $capture(); $marker = $before['options'][StorageAuthority::MAINTENANCE_OPTION];
		$control = StorageControl::decode( $before['options'][StorageAuthority::CONTROL_OPTION]['option_value'], get_current_blog_id() );
		$pending = $control; ++$pending['epoch'];
		$sentinel = json_encode( StorageControl::sentinel( $pending ) );
		$source_row = $before['options'][FOOGALLERY_MIGRATE_OPTION_DATA];
		$backup_row = $before['options'][StorageAuthority::STORAGE_BACKUP_OPTION];
		$authority_row = $before['options'][StorageAuthority::AUTHORITY_OPTION];
		assisted_check( $marker && array() === $before['writers'] && $control['phase'] === 'verified' &&
			$control['backend'] === 'option' && $control['to'] === 'table' &&
			is_array( $source_row ) && is_array( $backup_row ) && is_array( $authority_row ) &&
			$backup_row['option_value'] === $source_row['option_value'] &&
			$authority_row['option_value'] === $sentinel &&
			$protected() === $journal['protected_sha256'] &&
			$table_equals_source( $source_row ) === $journal['source_value_sha256'],
			'Exact interrupted owner, source, destination or protected output differs.' );
		assisted_check( proc_terminate( $process, 9 ), 'SIGKILL of exact owned child failed.' );
		$deadline = microtime( true ) + 5;
		do { $status = proc_get_status( $process ); if ( ! $status['running'] ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
		assisted_check( ! $status['running'] && $status['signaled'] && $status['termsig'] === 9, 'Owned child was not killed by signal 9.' );
		fclose( $pipes[0] ); $pipes[0] = null;
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
		assisted_check( $out === '' && $err === '', 'Killed child produced unexpected shutdown/error output.' );
		$deadline = microtime( true ) + 5;
		do { $live = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID=%d', $hello['thread'] ) );
			if ( null === $live ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
		assisted_check( null === $live && ! $wpdb->last_error, 'Killed child SQL session remains.' );
		$after = $capture();
		assisted_check( $before === $after, 'Kill changed committed source, staging, owner or protected output.' );
		$journal['phase'] = 'dead'; $journal['death'] = array( 'pid' => $hello['pid'], 'thread' => $hello['thread'],
			'signal' => 9, 'marker' => $marker, 'snapshot' => $before,
			'snapshot_sha256' => $snapshot_sha( $before ), 'died_at' => time() );
		$save( $journal, false ); $result['death'] = array( 'pid' => $hello['pid'], 'thread' => $hello['thread'],
			'marker_sha256' => hash( 'sha256', $marker['option_value'] ), 'snapshot_sha256' => $journal['death']['snapshot_sha256'] );
	} finally {
		$status = proc_get_status( $process ); if ( $status['running'] ) { proc_terminate( $process, 9 ); }
		foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $process );
	}
}
if ( 'probe' === $step ) {
	assisted_check( $journal['phase'] === 'dead' && $capture() === $journal['death']['snapshot'],
		'Dead owner evidence changed before fresh public refusal.' );
	$refusal = StorageCli::execute( 'recover', array( 'target' => 'table', 'confirm' => 'recover-storage' ) );
	assisted_check( is_wp_error( $refusal ) && $refusal->get_error_code() === 'foogallery_migrate_storage_recovery_required' &&
		$capture() === $journal['death']['snapshot'], 'Fresh public recovery did not refuse unchanged dead ownership.' );
	$journal['phase'] = 'refused'; $journal['refusal'] = $refusal->get_error_code(); $save( $journal, false );
	$result['refusal'] = $journal['refusal'];
}
if ( 'inspect' === $step || 'operator-clear' === $step ) {
	assisted_check( $journal['phase'] === 'refused' && $journal['refusal'] === 'foogallery_migrate_storage_recovery_required' &&
		$capture() === $journal['death']['snapshot'], 'Assisted evidence changed before operator intervention.' );
	$thread = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$others = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=%s AND ID<>%d',
		DB_NAME, $thread ) );
	$transactions = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE p.DB=%s', DB_NAME ) );
	assisted_check( ! $wpdb->last_error && $thread > 0 && (string) $others === '0' && (string) $transactions === '0',
		'Externally frozen fixture still has other SQL sessions or transactions.' );
	$result['quiescence_sql'] = array( 'observer_thread' => $thread, 'other_sessions' => 0, 'transactions' => 0 );
	$result['dead_snapshot_sha256'] = $journal['death']['snapshot_sha256'];
	$result['marker_sha256'] = hash( 'sha256', $journal['death']['marker']['option_value'] );
}
if ( 'operator-clear' === $step ) {
	assisted_check( preg_match( '/^[a-f0-9]{64}$/D', $argv[3] ) && $argv[3] === $journal['death']['snapshot_sha256'],
		'Operator must name the exact reviewed dead snapshot.' );
	$attestation_path = $site . '/.fgm-assisted-quiescence.json';
	assisted_check( is_file( $attestation_path ) && ! is_link( $attestation_path ) &&
		realpath( $attestation_path ) === $attestation_path && filesize( $attestation_path ) <= 4096 &&
		( fileperms( $attestation_path ) & 0077 ) === 0,
		'External operator attestation must be an owned private regular file.' );
	$attestation_raw = file_get_contents( $attestation_path ); $attestation = json_decode( $attestation_raw, true );
	$required = array( 'entrypoints_frozen', 'worker_and_descendants_drained', 'output_inactive',
		'sql_sessions_drained', 'sql_transactions_drained', 'backup_preserved' );
	assisted_check( is_array( $attestation ) && isset( $attestation['kind'], $attestation['site'], $attestation['database'],
		$attestation['container_id'], $attestation['snapshot_sha256'], $attestation['marker_sha256'],
		$attestation['worker_pid'], $attestation['worker_thread'], $attestation['operator'], $attestation['observed_at'],
		$attestation['checks'], $attestation['database_backup_sha256'], $attestation['files_backup_sha256'] ) &&
		$attestation['kind'] === 'fgm-assisted-quiescence-v1' && $attestation['site'] === $site &&
		$attestation['database'] === DB_NAME && $attestation['container_id'] === $manifest['container_id'] &&
		$attestation['snapshot_sha256'] === $journal['death']['snapshot_sha256'] &&
		$attestation['marker_sha256'] === hash( 'sha256', $journal['death']['marker']['option_value'] ) &&
		$attestation['worker_pid'] === $journal['death']['pid'] &&
		$attestation['worker_thread'] === $journal['death']['thread'] &&
		is_string( $attestation['operator'] ) && strlen( $attestation['operator'] ) >= 2 && strlen( $attestation['operator'] ) <= 100 &&
		is_int( $attestation['observed_at'] ) && $attestation['observed_at'] >= $journal['death']['died_at'] &&
		$attestation['observed_at'] <= time() && is_array( $attestation['checks'] ) &&
		array_keys( $attestation['checks'] ) === $required &&
		array_values( $attestation['checks'] ) === array_fill( 0, count( $required ), true ),
		'External operator quiescence statement differs from the exact killed fixture.' );
	foreach ( array( 'database' => '.fgm-assisted-db.sql', 'files' => '.fgm-assisted-files.tar' ) as $kind => $suffix_path ) {
		$path = $site . '/' . $suffix_path;
		$key = $kind . '_backup_sha256';
		assisted_check( is_file( $path ) && ! is_link( $path ) && realpath( $path ) === $path &&
			preg_match( '/^[a-f0-9]{64}$/D', $attestation[$key] ) && hash_file( 'sha256', $path ) === $attestation[$key],
			'External recoverable backup missing or differs: ' . $kind );
	}
	$expected = $journal['death']['marker'];
	$pin = new LegacyTransactionConnection( $wpdb );
	$pin_thread = $pin->get_var( 'SELECT CONNECTION_ID()' );
	assisted_check( $option_row( StorageAuthority::MAINTENANCE_OPTION ) === $expected &&
		$capture() === $journal['death']['snapshot'] && array() === $writer_rows() &&
		$pin->is_current() && ! $pin->last_error && (int) $pin_thread === $thread,
		'Final exact owner, state or pinned SQL connection changed before CAS.' );
	$deleted = $pin->query( $pin->prepare( 'DELETE FROM `' . $wpdb->options .
		'` WHERE option_id=%d AND option_name=%s AND BINARY option_value=BINARY %s',
		array( (int) $expected['option_id'], $expected['option_name'], $expected['option_value'] ) ) );
	assisted_check( $deleted === 1 && $pin->is_current() && ! $pin->last_error,
		'Exact dead-owner CAS did not remove exactly one row on its pinned connection.' );
	$readback = $pin->get_results( $pin->prepare( 'SELECT option_id, option_value FROM `' . $wpdb->options .
		'` WHERE option_name=%s', array( StorageAuthority::MAINTENANCE_OPTION ) ), ARRAY_A );
	assisted_check( $readback === array() && $pin->is_current() && ! $pin->last_error,
		'Dead-owner row remains or pinned readback failed.' );
	wp_cache_delete( StorageAuthority::MAINTENANCE_OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	assisted_check( null === $option_row( StorageAuthority::MAINTENANCE_OPTION ) && $pin->is_current(),
		'Dead-owner row remains after cache invalidation.' );
	$journal['phase'] = 'cleared'; $journal['operator'] = array( 'attestation_sha256' => hash( 'sha256', $attestation_raw ),
		'database_backup_sha256' => $attestation['database_backup_sha256'],
		'files_backup_sha256' => $attestation['files_backup_sha256'], 'marker_option_id' => (int) $expected['option_id'] );
	$save( $journal, false ); $result['operator'] = $journal['operator'];
}
if ( 'recover' === $step ) {
	assisted_check( in_array( $journal['phase'], array( 'cleared', 'recovering' ), true ) &&
		null === $option_row( StorageAuthority::MAINTENANCE_OPTION ) && array() === $writer_rows(),
		'Reviewed exact owner clearance and writer drain required.' );
	$control_row = $option_row( StorageAuthority::CONTROL_OPTION );
	$control = $control_row ? StorageControl::decode( $control_row['option_value'], get_current_blog_id() ) : null;
	assisted_check( is_array( $control ) && $control['phase'] !== 'stable' && $control['to'] === 'table' &&
		$control['from'] === 'option', 'Only recorded nonstable forward switch may resume.' );
	$journal['phase'] = 'recovery-pending'; $journal['before_recover_control_sha256'] = hash( 'sha256', $control_row['option_value'] );
	$save( $journal, false );
	$response = StorageCli::execute( 'recover', array( 'target' => 'table', 'confirm' => 'recover-storage' ) );
	assisted_check( ! is_wp_error( $response ), 'Public authorized recovery refused; preserve pending receipt.' );
	$after = StorageAdmin::status();
	assisted_check( $after['backend'] === 'table' || $after['phase'] !== 'stable', 'Unexpected stable non-table authority.' );
	$journal['phase'] = $after['phase'] === 'stable' ? 'stable' : 'recovering';
	$journal['last_recovery'] = $response; $save( $journal, false );
	$result['response'] = $response; $result['storage'] = $after;
}
if ( 'verify' === $step ) {
	assisted_check( $journal['phase'] === 'stable' && isset( $journal['operator'] ) &&
		null === $option_row( StorageAuthority::MAINTENANCE_OPTION ) && array() === $writer_rows(),
		'Fresh stable verification requires reviewed operator receipt and no owners.' );
	$status = StorageAdmin::status();
	$control_row = $option_row( StorageAuthority::CONTROL_OPTION );
	$control = $control_row ? StorageControl::decode( $control_row['option_value'], get_current_blog_id() ) : null;
	$authority_row = $option_row( StorageAuthority::AUTHORITY_OPTION );
	$backup_row = $option_row( StorageAuthority::STORAGE_BACKUP_OPTION );
	$source_before = $journal['death']['snapshot']['options'][FOOGALLERY_MIGRATE_OPTION_DATA];
	assisted_check( $status['backend'] === 'table' && $status['phase'] === 'stable' && $status['problem'] === '' &&
		is_array( $control ) && $control['phase'] === 'stable' && $control['backend'] === 'table' &&
		$authority_row && StorageControl::runtime_ready( $control, $authority_row['option_value'] ) &&
		$backup_row && $source_before && $backup_row['option_value'] === $source_before['option_value'] &&
		null === $option_row( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		( new LegacyStorageSchema( $wpdb ) )->verify() && $protected() === $journal['protected_sha256'],
		'Fresh stable authority, exact backup, schema or protected output differs.' );
	assisted_check( $table_equals_source( $source_before ) === $journal['source_value_sha256'],
		'Fresh selected state differs from original source proof.' );
	$final_raw = $capture(); $death_raw = $journal['death']['snapshot'];
	assisted_check( $final_raw['posts'] === $death_raw['posts'] &&
		$final_raw['meta'] === $death_raw['meta'] &&
		$final_raw['media_sha256'] === $death_raw['media_sha256'],
		'Full protected source/output post, metadata or media bytes changed during recovery.' );
	$result['storage'] = $status; $result['backup_sha256'] = hash( 'sha256', $backup_row['option_value'] );
}
if ( 'inspect' === $step ) {
	$result['worker_pid'] = $journal['death']['pid']; $result['worker_thread'] = $journal['death']['thread'];
	$result['operator_attestation_path'] = $site . '/.fgm-assisted-quiescence.json';
	$result['database_backup_path'] = $site . '/.fgm-assisted-db.sql';
	$result['files_backup_path'] = $site . '/.fgm-assisted-files.tar';
}
$result['phase_after'] = $journal['phase']; $result['protected_sha256'] = $protected_sha;
echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
