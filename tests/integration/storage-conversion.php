<?php
/** Native disposable conversion/phase receipt, not release or literal-floor proof. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Disposable WordPress fixture required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;

 global $wpdb;
$blog = (int) get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing table evidence.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Refusing existing option evidence.' ); }
}
$checks = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$request = function ( $target, $recover = false ) use ( $blog ) {
	$purpose = $recover ? 'recover' : ( 'table' === $target ? 'option_import' : 'table_export' );
	return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => $recover ? 'recover-storage' : 'switch-to-' . $target,
		'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
};
// Deliberately synthetic fixture calibration, NEVER production acceptance.
$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
$codec = new LegacyStateCodec();
$initial = array( 'checkpoint' => array( 'cursor' => '009', 'started' => true ), 'migrated-revision' => 7,
	'migrated' => array( '009' => array( 'ID' => '009', 'object_type' => 'gallery', 'plugin_name' => 'Case', 'migrated_id' => 37,
		'children' => array( 7 => false, '007' => null ), 'unknown' => array( 'binary' => "\0雪", 'negative' => -0.0 ) ), 9 => null ),
	9 => false, '09' => null, 'empty' => array() );
$control = new StorageControl( $wpdb, $blog );
$reset = function () use ( $wpdb, $tables, $names, $blog ) {
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', $blog ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$created = array(); $hook = null;
try {
	$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ), 'native source fixture exists before schema-denial test' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'missing existing schema refuses conversion without provisioning' );
	$check( null === $control->read( StorageAuthority::CONTROL_OPTION ), 'schema denial creates no control or destination' );
	delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'verified existing InnoDB schema; coordinator performs no provisioning' );
	$db = new LegacyTransactionConnection( $wpdb );
	$other = $blog + 1000;
	( new LegacyTableSnapshot( $db, $other ) )->replace_many( array( array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => 'other-blog-retained', 'expected_revision' => null ) ) );
	$other_raw = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A );
	$other_records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE blog_id = %d', $other ), ARRAY_A );

	$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ), 'native fixture source option actually persisted' );
	$raw_before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$r = $request( 'table' ); $r['nonce'] = 'wrong';
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $r ) ), 'native wrong-purpose/invalid nonce refused' );
	$check( null === $control->read( StorageAuthority::CONTROL_OPTION ) && null === $control->read( StorageAuthority::AUTHORITY_OPTION ), 'nonce denial creates no control or sentinel' );
	$check( is_wp_error( ( new StorageConversion() )->switch_backend( $request( 'table' ) ) ), 'no calibrated trusted policy refuses source decode' );
	$check( $raw_before === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ) && null === $control->read( StorageAuthority::CONTROL_OPTION ), 'missing-policy denial retains exact source and no pending control' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) ), 'owned maintenance marker released after refusal' );

	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) );
	$check( ! is_wp_error( $result ), 'authorized native forward switch succeeds' );
	$check( 'table' === $result['backend'] && 'stable' === $result['phase'] && 1 === $result['epoch'], 'forward control publishes table epoch1 only after verification' );
	$check( StorageControl::runtime_ready( $result, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'table sentinel matches exact blog/epoch/switch' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'live stale option name removed after table flip' );
	$backup = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) );
	$check( $raw_before === $backup, 'distinct retained backup contains exact original physical option bytes' );
	$reader = new LegacyTableSnapshot( $db, $blog );
	$actual = array(); foreach ( $initial as $key => $value ) { $actual[$key] = $reader->read_bounded( $key ); }
	$check( $codec->snapshot_digest( $initial ) === $codec->snapshot_digest( $actual ), 'full typed-key/order/revision/unknown/binary/negative-zero parity' );
	$check( $other_raw === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'forward switch retains other SQL blog scope byte-identically' );
	$engine = foogallery_migrate_migrator_instance();
	$check( false === $engine->get_migrator_setting( 9, 'missing' ), 'production factory reads authoritative table scalar, never missing stale option' );
	$check( $engine->set_migrator_setting( 9, true ), 'production engine guarded scalar write reaches table backend' );
	StorageMaintenanceGate::current()->shutdown_writer();

	// Direct admitted-backend primitive fixture mutation, not public UI acceptance.
	$backend = new LegacyTableBackend( $db, $blog );
	$backend->mutate_scalars( array( 9 => true ) );
	$reader->replace_many( array( array( 'key' => 'migrated-revision', 'order' => 1, 'kind' => 'scalar', 'value' => 8, 'expected_revision' => 1 ) ) );
	$expected = $initial; $expected[9] = true; $expected['migrated-revision'] = 8;
	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) );
	$check( ! is_wp_error( $result ), 'authorized reverse switch succeeds from current authoritative table state' );
	$check( 'option' === $result['backend'] && 2 === $result['epoch'] && 'stable' === $result['phase'], 'reverse control publishes option epoch2' );
	$check( $codec->snapshot_digest( $expected ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'reverse exports new table mutation and logical revision, not stale retained backup' );
	$check( $backup === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) ) && null === $control->read( StorageAuthority::AUTHORITY_OPTION ), 'reverse retains backup and removes sentinel only after option flip' );
	$check( 'no' === $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'fresh reverse option is non-autoloaded' );
	try { $engine->set_migrator_setting( 9, false ); $check( false, 'cached old epoch must refuse write' ); }
	catch ( RuntimeException $error ) { $check( true, 'cached table engine refuses post-reverse authority epoch write' ); }
	StorageMaintenanceGate::current()->shutdown_writer();
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
	$check( true === foogallery_migrate_migrator_instance()->get_migrator_setting( 9 ), 'fresh production option factory reads verified reverse export' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'repeat import refuses retained inactive table generations instead of silently erasing evidence' );
	$check( 'stable' === StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog )['phase'], 'repeat-import refusal retains existing stable option authority' );

	$replacement = $request( 'table' ); $replacement['confirmation'] = 'replace-retained-with-table';
	$replacement['nonce'] = wp_create_nonce( 'foogallery_migrate_storage_option_reimport_' . $blog );
	$retained_bytes = function () use ( $wpdb, $tables, $blog, $control ) {
		return serialize( array(
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d ORDER BY id', $blog ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE blog_id = %d ORDER BY id', $blog ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ), ARRAY_A ),
			$control->read( StorageAuthority::CONTROL_OPTION ), $control->read( StorageAuthority::AUTHORITY_OPTION ),
		) );
	};
	$preserved = $retained_bytes(); $stable = $control->read( StorageAuthority::CONTROL_OPTION );
	$check( 2 === StorageControl::decode( $stable, $blog )['version'], 'new reverse binds v2 all-generation and backup evidence' );
	$legacy = StorageControl::decode( $stable, $blog ); $legacy['version'] = 1; unset( $legacy['retained_digest'] );
	$control->cas( StorageAuthority::CONTROL_OPTION, $stable, json_encode( $legacy ) );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->replace_retained( $replacement ) ), 'previous v1 reverse is explicitly unclassified for destructive replacement' );
	$check( $control->read( StorageAuthority::CONTROL_OPTION ) === json_encode( $legacy ), 'legacy replacement refusal preserves raw control' );
	$control->cas( StorageAuthority::CONTROL_OPTION, json_encode( $legacy ), $stable );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s', array( 'changed retained backup', StorageAuthority::STORAGE_BACKUP_OPTION ) ) );
	$changed = $retained_bytes();
	$check( is_wp_error( ( new StorageConversion( $policy ) )->replace_retained( $replacement ) ) && $changed === $retained_bytes(), 'changed retained backup refuses with all changed evidence byte-identically retained' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s', array( $backup, StorageAuthority::STORAGE_BACKUP_OPTION ) ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET payload_bytes = payload_bytes + 1 WHERE blog_id = %d', $blog ) );
	$changed = $retained_bytes();
	$check( is_wp_error( ( new StorageConversion( $policy ) )->replace_retained( $replacement ) ) && $changed === $retained_bytes(), 'changed inactive head metadata refuses without erasing generations' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET payload_bytes = payload_bytes - 1 WHERE blog_id = %d', $blog ) );
	$check( $preserved === $retained_bytes(), 'fixture restores exact captured inactive evidence' );

	// These native trigger failures occur after earlier retirement DELETEs. The
	// pinned adapter bypasses wpdb query filters; rollback must restore ALL rows.
	$trigger = $wpdb->base_prefix . 'foogm_fixture_retire_fail';
	foreach ( array( 'head-delete', 'backup-delete', 'control-update', 'control-readback' ) as $fault ) {
		if ( 'head-delete' === $fault ) {
			$sql = 'BEFORE DELETE ON `' . $tables[0] . "` FOR EACH ROW BEGIN IF OLD.blog_id = " . $blog . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Owned retirement head failure'; END IF; END";
		} elseif ( 'backup-delete' === $fault ) {
			$sql = 'BEFORE DELETE ON `' . $wpdb->options . "` FOR EACH ROW BEGIN IF OLD.option_name = '" . StorageAuthority::STORAGE_BACKUP_OPTION . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Owned retirement backup failure'; END IF; END";
		} else {
			$effect = 'control-readback' === $fault ? "SET NEW.option_value = '{}';" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Owned retirement control failure';";
			$sql = 'BEFORE UPDATE ON `' . $wpdb->options . "` FOR EACH ROW BEGIN IF OLD.option_name = '" . StorageAuthority::CONTROL_OPTION . "' THEN " . $effect . ' END IF; END';
		}
		$check( false !== $wpdb->query( 'CREATE TRIGGER `' . $trigger . '` ' . $sql ), 'native retirement fault installed: ' . $fault );
		$suppressed = $wpdb->suppress_errors( true );
		try { $failed = ( new StorageConversion( $policy ) )->replace_retained( $replacement ); }
		finally { $wpdb->query( 'DROP TRIGGER `' . $trigger . '`' ); $wpdb->suppress_errors( $suppressed ); }
		$check( is_wp_error( $failed ) && $preserved === $retained_bytes(), 'native retirement rollback restores exact generations, backup and stable control: ' . $fault );
	}
	$check( false !== $wpdb->query( 'START TRANSACTION' ), 'ambient transaction opened explicitly' );
	$wpdb->query( 'INSERT INTO `' . $wpdb->options . "` (option_name, option_value, autoload) VALUES ('foogm_retire_ambient_fixture', 'owned', 'no')" );
	$failed = ( new StorageConversion( $policy ) )->replace_retained( $replacement );
	$wpdb->query( 'ROLLBACK' );
	$check( is_wp_error( $failed ) && null === $wpdb->get_var( 'SELECT option_id FROM `' . $wpdb->options . "` WHERE option_name = 'foogm_retire_ambient_fixture'" ) && $preserved === $retained_bytes(), 'replacement refuses ambient transaction without committing its work' );

	// The new import uses the current option, never the old retained backup/table.
	$expected[9] = false; unset( $expected['09'] ); $expected['new-key'] = array( 'unknown' => null, 3 => 'new' );
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $expected, false );
	$current_raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$trigger = $wpdb->base_prefix . 'foogm_fixture_repeat_insert_fail';
	$check( false !== $wpdb->query( 'CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $tables[1] . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Owned repeat import failure'" ), 'post-retirement native import fault installed' );
	$suppressed = $wpdb->suppress_errors( true );
	try { $failed = ( new StorageConversion( $policy ) )->replace_retained( $replacement ); }
	finally { $wpdb->query( 'DROP TRIGGER `' . $trigger . '`' ); $wpdb->suppress_errors( $suppressed ); }
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( is_wp_error( $failed ) && 'copying' === $pending['phase'] && 2 === $pending['epoch'] && 'option' === $pending['backend'], 'committed retirement plus interrupted copy publishes new authoritative recovery evidence' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) ) && $codec->snapshot_digest( $expected ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'post-retirement failure retains exact current source instead of reviving stale backup' );
	$result = ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) );
	$check( ! is_wp_error( $result ) && 3 === $result['epoch'] && 'stable' === $result['phase'], 'explicit recovery completes replacement from new immutable source' );
	$check( $current_raw === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) ), 'replacement retains a fresh exact physical backup, not previous import bytes' );
	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) );
	$check( ! is_wp_error( $result ) && 4 === $result['epoch'] && $codec->snapshot_digest( $expected ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'repeat reverse retains new keys, deletions and typed logical revision parity' );
	$check( $other_raw === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'retirement rollback, recovery and repeat switches leave other SQL blog byte-identical' );

	// A native failed INSERT rolls back all destination generations, not source.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
	// Snapshot SQL deliberately uses its owned native handle, bypassing the
	// WordPress query filter. A fixture-owned database trigger faults that path.
	$trigger = $wpdb->base_prefix . 'foogm_fixture_conversion_fail';
	$check( false !== $wpdb->query( 'CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $tables[1] . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disposable conversion write failure'" ), 'native SQL failure trigger actually installed on owned fixture table' );
	$suppressed = $wpdb->suppress_errors( true );
	try {
		$failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) );
	} finally { $wpdb->query( 'DROP TRIGGER `' . $trigger . '`' ); $wpdb->suppress_errors( $suppressed ); }
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( is_wp_error( $failed ) && 'copying' === $pending['phase'] && 'option' === $pending['backend'], 'native destination SQL failure retains pending option authority' );
	$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $tables[0] . '` WHERE blog_id = %d', $blog ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $tables[1] . '` WHERE blog_id = %d', $blog ) ), 'failed native destination INSERT rolls back all current-blog heads and records' );
	$check( $codec->snapshot_digest( $initial ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'native SQL failure leaves exact logical source intact' );
	$check( ! is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'native SQL failure recovers copying phase after exact source and rollback verification' );

	foreach ( array( 'verified', 'flipped', 'delete-source', 'stable' ) as $fault ) {
		$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
		$fired = false;
		$hook = function ( $sql ) use ( $fault, &$fired ) {
			$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
			$matches = 'delete-source' === $fault ? ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'foogallery-migrate-data'" ) ) :
				( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $set, '"phase":"' . $fault . '"' ) );
			if ( ! $fired && $matches ) { $fired = true; throw new RuntimeException( 'Fixture interrupt before ' . $fault ); }
			return $sql;
		};
		add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
		$check( $fired && is_wp_error( $failed ), 'forward interruption fired and did not report success: ' . $fault );
		$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
		$check( 'stable' !== $pending['phase'] && ! StorageControl::runtime_ready( $pending, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'interrupted phase never admits normal runtime: ' . $fault );
		$result = ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) );
		$check( ! is_wp_error( $result ) && 'table' === $result['backend'] && 'stable' === $result['phase'], 'authoritative phase recovery succeeds without merging: ' . $fault );
	}

	foreach ( array( 'verified', 'flipped', 'remove-sentinel', 'stable' ) as $fault ) {
		$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
		$check( ! is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'reverse interruption fixture forward import succeeds: ' . $fault );
		$fired = false;
		$hook = function ( $sql ) use ( $fault, &$fired ) {
			$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
			$matches = 'remove-sentinel' === $fault ? ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, StorageAuthority::AUTHORITY_OPTION ) ) :
				( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $set, '"phase":"' . $fault . '"' ) );
			if ( ! $fired && $matches ) { $fired = true; throw new RuntimeException( 'Fixture reverse interrupt before ' . $fault ); }
			return $sql;
		};
		add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) ); remove_filter( 'query', $hook ); $hook = null;
		$check( $fired && is_wp_error( $failed ), 'reverse interruption fired without success: ' . $fault );
		$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
		$check( 'stable' !== $pending['phase'] && ! StorageControl::runtime_ready( $pending, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'reverse pending phase denies ordinary runtime: ' . $fault );
		if ( 'remove-sentinel' === $fault || 'stable' === $fault ) {
			( new LegacyTableBackend( $db, $blog ) )->mutate_scalars( array( 9 => true ) ); // Now NONauthoritative.
		}
		$result = ( new StorageConversion( $policy ) )->recover( $request( 'option', true ) );
		$check( ! is_wp_error( $result ) && 'option' === $result['backend'] && 'stable' === $result['phase'], 'reverse phase recovery uses recorded authority, not inactive source: ' . $fault );
		$check( $codec->snapshot_digest( $initial ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'reverse recovery retains exact destination logical state: ' . $fault );
	}

	// Destination verification failure is not repaired by overwriting or merging.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ); $fired = false;
	$hook = function ( $sql ) use ( &$fired, $db, $blog ) {
		$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
		if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $set, '"phase":"verified"' ) ) {
			$fired = true; ( new LegacyTableBackend( $db, $blog ) )->mutate_scalars( array( 9 => true ) );
		}
		return $sql;
	};
	add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( $fired && is_wp_error( $failed ) && 'verified' === $pending['phase'] && 'option' === $pending['backend'], 'destination parity failure retains source authority after staging' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ) && $codec->snapshot_digest( $initial ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'mismatched destination recovery refuses rather than merging or rewriting source' );

	// Publication itself must depend on the last verified destination snapshot.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ); $fired = false;
	$hook = function ( $sql ) use ( &$fired, $db, $blog ) {
		$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
		if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $set, '"phase":"stable"' ) ) {
			$fired = true; ( new LegacyTableBackend( $db, $blog ) )->mutate_scalars( array( 9 => true ) );
		}
		return $sql;
	};
	add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( $fired && is_wp_error( $failed ) && 'flipped' === $pending['phase'] && ! StorageControl::runtime_ready( $pending, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'stable-publication CAS refuses destination changed after final parity check' );

	// Atomic source dependency in final authority CAS, not only a prior SELECT.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ); $fired = false;
	$hook = function ( $sql ) use ( &$fired, $initial ) {
		if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( stripslashes( $sql ), '"phase":"flipped"' ) ) {
			$fired = true; $changed = $initial; $changed['migrated-revision'] = 999; update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $changed, false );
		}
		return $sql;
	};
	add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( $fired && is_wp_error( $failed ) && 'option' === $pending['backend'] && 'verified' === $pending['phase'], 'atomic option source hash dependency denies racing source change at flip SQL' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'recovery refuses changed source instead of merging staged destination' );
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
	$check( ! is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'explicit fixture restoration permits recovery from exact immutable source' );

	// Reverse final-CAS dependencies include every current source head revision.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
	$check( ! is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'reverse source-race fixture forward import succeeds' );
	$fired = false;
	$hook = function ( $sql ) use ( &$fired, $db, $blog ) {
		$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
		if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $set, '"phase":"flipped"' ) ) {
			$fired = true; ( new LegacyTableBackend( $db, $blog ) )->mutate_scalars( array( 9 => true ) );
		}
		return $sql;
	};
	add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) ); remove_filter( 'query', $hook ); $hook = null;
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( $fired && is_wp_error( $failed ) && 'table' === $pending['backend'] && 'verified' === $pending['phase'], 'atomic table source head/revision dependencies deny racing reverse flip' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'option', true ) ) ), 'reverse recovery refuses changed authoritative source even with complete stale destination option' );

	$reset();
	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) );
	$check( ! is_wp_error( $result ) && StorageControl::runtime_ready( $result, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'empty absent option import still creates matching table authority sentinel' );
	$check( ! is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) ) ) && array() === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'empty authoritative table reverse exports complete empty option' );

	// A separate PHP process changes ownership/drain after ready() and before
	// the actual coordinator SQL. Earlier SELECTs alone must never authorize it.
	foreach ( array( 'copying', 'backup', 'flipped', 'delete-source', 'stable', 'reverse-option', 'remove-sentinel' ) as $point ) {
		foreach ( array( 'owner-replace', 'writer-add' ) as $race ) {
			$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
			$reverse = in_array( $point, array( 'reverse-option', 'remove-sentinel' ), true );
			if ( $reverse ) { $check( ! is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'ownership race reverse fixture forward succeeds: ' . $point . '/' . $race ); }
			$fired = false; $owner = null; $before_write = null; $target_name = StorageAuthority::CONTROL_OPTION;
			if ( 'backup' === $point ) { $target_name = StorageAuthority::STORAGE_BACKUP_OPTION; }
			if ( in_array( $point, array( 'delete-source', 'reverse-option' ), true ) ) { $target_name = FOOGALLERY_MIGRATE_OPTION_DATA; }
			if ( 'remove-sentinel' === $point ) { $target_name = StorageAuthority::AUTHORITY_OPTION; }
			$hook = function ( $sql ) use ( &$fired, &$owner, &$before_write, $point, $race, $target_name, $wpdb, $blog ) {
				if ( $fired ) { return $sql; }
				$set = stripslashes( substr( $sql, 0, false === strpos( $sql, ' WHERE ' ) ? strlen( $sql ) : strpos( $sql, ' WHERE ' ) ) );
				$phase = in_array( $point, array( 'copying', 'flipped', 'stable' ), true );
				$matches = $phase ? ( false !== strpos( $set, '"phase":"' . $point . '"' ) && ( 0 === strpos( $sql, 'UPDATE' ) || 0 === strpos( $sql, 'INSERT' ) ) ) :
					( 0 === strpos( $sql, in_array( $point, array( 'delete-source', 'remove-sentinel' ), true ) ? 'DELETE' : 'INSERT' ) && false !== strpos( $sql, "'" . $target_name . "'" ) );
				if ( ! $matches ) { return $sql; }
				$fired = true;
				$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) );
				$before_write = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->options . '` WHERE option_name = %s', $target_name ), ARRAY_A );
				$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-owner-race-worker.php' ) . ' ' . escapeshellarg( rtrim( ABSPATH, '/' ) );
				$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
				if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Owned race process failed to start.' ); }
				fwrite( $pipes[0], json_encode( array( 'blog_id' => $blog, 'action' => $race, 'owner' => $owner ) ) ); fclose( $pipes[0] );
				$out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
				if ( 0 !== proc_close( $process ) || "OWNED_RACE_APPLIED\n" !== $out || '' !== $err ) { throw new RuntimeException( 'Owned separate-process race did not complete cleanly.' ); }
				return $sql;
			};
			add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( $reverse ? 'option' : 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
			$check( $fired && is_wp_error( $failed ), 'separate-process ownership/drain race refuses success: ' . $point . '/' . $race );
			$check( $before_write === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->options . '` WHERE option_name = %s', $target_name ), ARRAY_A ), 'raced coordinator SQL leaves exact target/control bytes untouched: ' . $point . '/' . $race );
			if ( 'owner-replace' === $race ) {
				$check( 'fixture-lost-owner' === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) ), 'failed coordinator retains foreign owner rather than removing it: ' . $point );
				// Only the fixture restores its injected owner so this request can end.
				$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s AND option_value = %s', array( $owner, StorageAuthority::MAINTENANCE_OPTION, 'fixture-lost-owner' ) ) );
				$check( true === StorageMaintenanceGate::current()->release_maintenance(), 'restored fixture owner releases exactly: ' . $point );
			} else {
				$check( 'fixture-undrained-writer' === $wpdb->get_var( 'SELECT option_value FROM `' . $wpdb->options . "` WHERE option_name = 'foogallery_migrate_storage_writer_fixture_race'" ), 'raced writer marker retained for drain proof: ' . $point );
				delete_option( 'foogallery_migrate_storage_writer_fixture_race' );
			}
		}
	}

	// Unsupported historical objects never invoke wakeup and remain untouched.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'old' => (object) array( 'unknown' => true ) ), false );
	$before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'unsupported noncompact object blocks conversion rather than narrowing properties' );
	$check( $before === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ) && null === $control->read( StorageAuthority::CONTROL_OPTION ), 'unsupported object refusal retains original raw source with no pending control' );

	$reset();
	$gate = StorageMaintenanceGate::current(); $check( true === $gate->acquire_maintenance(), 'fixture owner acquires maintenance' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'live foreign maintenance owner is not silently replaced' );
	$check( true === $gate->release_maintenance(), 'original fixture owner still owns and releases exact marker' );
	$check( $other_raw === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'all conversions/failures retain other scoped heads' );
	$check( $other_records === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'all conversions/failures retain other scoped record bytes' );
} finally {
	if ( is_callable( $hook ) ) { remove_filter( 'query', $hook ); }
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE `' . $table . '`' ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'check_count' => count( $checks ), 'blog_id' => $blog,
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'calibration' => 'synthetic fixture policy; NOT production/floor acceptance' ), JSON_PRETTY_PRINT ) . PHP_EOL;
