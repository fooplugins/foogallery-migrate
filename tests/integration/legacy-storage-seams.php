<?php
/**
 * Real WP/MariaDB shared-schema/codec/read-primitives checkpoint.
 * Run only on a disposable site, plugins/themes skipped:
 * wp --skip-plugins --skip-themes eval 'define("FOOGM_STORAGE_TEST_DISPOSABLE", true);
 *     require "/absolute/repo/tests/integration/legacy-storage-seams.php";'
 * Refuses existing owned tables. This does not test/enable opt-in conversion.
 * Set FOOGM_STORAGE_TEST_ROOT to an extracted package root to exercise its
 * actual plugin bootstrap/autoload instead of the source tree.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'An explicitly disposable WordPress site is required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

global $wpdb;
$codec = new LegacyStateCodec();
$schema = new LegacyStorageSchema( $wpdb );
$definitions = $schema->definitions();
$tables = array_keys( $definitions );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) {
		throw new RuntimeException( 'Refusing existing shared tables or an unhealthy database.' );
	}
}
$missing = new stdClass();
$options = array();
foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $option ) {
	$options[ $option ] = get_option( $option, $missing );
}
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$refuses = function ( $callback, $label ) use ( $check ) {
	try { $callback(); } catch ( RuntimeException $error ) { $check( true, $label ); return; }
	$check( false, $label );
};
// Fixture-only full rows; production writes must be coordinated by the manager.
$insert = function ( $table, $changes ) use ( $wpdb, $definitions, $codec ) {
	$columns = array(); $expressions = array(); $args = array();
	foreach ( $definitions[ $table ]['columns'] as $name => $spec ) {
		if ( 'id' === $name ) { continue; }
		$value = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? 0 : ( 'datetime' === $spec[0] ? '2000-01-01 00:00:00' : '' ) );
		if ( 'binary(32)' === $spec[0] && ! $spec[1] ) { $value = str_repeat( '0', 64 ); }
		if ( 'longtext' === $spec[0] && ! $spec[1] ) { $value = $codec->encode( null ); }
		if ( array_key_exists( $name, $changes ) ) { $value = $changes[ $name ]; }
		$columns[] = '`' . $name . '`';
		if ( null === $value ) { $expressions[] = 'NULL'; continue; }
		$expressions[] = 'binary(32)' === $spec[0] ? 'UNHEX(%s)' : ( false !== strpos( $spec[0], 'int' ) ? '%d' : '%s' );
		$args[] = $value;
	}
	$sql = 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $expressions ) . ')';
	if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { throw new RuntimeException( 'Fixture insert failed: ' . $wpdb->last_error ); }
	return (int) $wpdb->insert_id;
};
$created = array();
try {
	foreach ( $schema->statements() as $index => $ddl ) {
		$created[] = $tables[ $index ];
		dbDelta( $ddl );
	}
	$check( $schema->verify(), 'dbDelta creates exact InnoDB columns and ordered indexes' );
	$check( $schema->verify(), 'metadata health check is repeatable without upgrades' );
	$wpdb->query( 'ALTER TABLE `' . $tables[1] . '` DROP INDEX work' );
	$refuses( function () use ( $schema ) { $schema->verify(); }, 'missing required index fails closed' );
	dbDelta( $schema->statements()[1] );
	$check( $schema->verify(), 'dbDelta restores fixture index and health verifies it' );

	$blog = (int) get_current_blog_id();
	$state_hash = $codec->key_hash( 'migrated' );
	$heads = array();
	foreach ( array( $blog, $blog + 1 ) as $site ) {
		$heads[ $site ] = $insert( $tables[0], array( 'blog_id' => $site, 'state_key_hash' => $state_hash,
			'state_key_value' => $codec->key_value( 'migrated' ), 'state_kind' => 'map', 'active_generation' => 2,
			'revision' => 8, 'codec_version' => 1, 'record_count' => 3, 'root_payload' => $codec->encode( array() ) ) );
	}
	$root_keys = array( 9, '009', -2 );
	$ids = array();
	foreach ( array( $blog, $blog + 1 ) as $site ) {
		foreach ( $root_keys as $order => $key ) {
			$base = array( 'object_type' => 'image', 'ID' => '007', 'unknown' => array( 'bytes' => "\xff\x00雪", 'missing' => false ), 'site' => $site );
			$mutable = array( 'migration_status' => 'completed', 'migrated' => true, 'migrated_id' => 204 );
			$payload = $codec->encode( $base ); $mutation = $codec->encode( $mutable );
			$ids[ $site ][ $order ] = $insert( $tables[1], array( 'blog_id' => $site, 'state_id' => $heads[ $site ], 'generation' => 2,
				'row_kind' => 'root', 'node_hash' => $codec->node_hash( $state_hash, null, $key ),
				'key_type' => is_int( $key ) ? 'integer' : 'string', 'key_value' => base64_encode( (string) $key ), 'item_order' => $order,
				'payload' => $payload, 'mutable_payload' => $mutation, 'payload_hash' => hash( 'sha256', $payload ),
				'logical_hash' => $codec->logical_hash( array_merge( $base, $mutable ) ), 'payload_bytes' => strlen( $payload ) + strlen( $mutation ),
				'legacy_identifier_hash' => $codec->key_hash( 'MixedCase-' . str_repeat( 'x', 300 ) . $order ),
				'legacy_identifier_value' => $codec->encode( 'MixedCase-' . str_repeat( 'x', 300 ) . $order ) ) );
		}
	}
	$reader = new LegacyTableReader( $wpdb, $blog );
	$page1 = $reader->record_page( 'migrated', 2 );
	$page2 = $reader->record_page( 'migrated', 2, $page1['cursor'] );
	$check( array( 9, '009' ) === array_column( $page1['rows'], 'key' ) && array( -2 ) === array_column( $page2['rows'], 'key' ), 'keyset pages retain exact typed keys and legacy order' );
	$check( "\xff\x00雪" === $page1['rows'][0]['base']['unknown']['bytes'], 'arbitrary string bytes and unknown nested data survive real LONGTEXT' );
	$check( true === $page1['rows'][0]['mutable']['migrated'], 'mutable envelope preserves boolean separately' );
	$check( $blog === $page1['rows'][0]['base']['site'] && $blog + 1 === ( new LegacyTableReader( $wpdb, $blog + 1 ) )->record_page( 'migrated', 1 )['rows'][0]['base']['site'], 'shared rows are strictly blog isolated' );
	$check( array() === $reader->record_page( 'migrated', 2, $page2['cursor'] )['rows'], 'terminal keyset page is empty without rereading history' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET item_order = 0 WHERE blog_id = %d AND id = %d', $blog, $ids[ $blog ][1] ) );
	$tied = $reader->record_page( 'migrated', 2 );
	$check( array( 9, '009' ) === array_column( $tied['rows'], 'key' ), 'row ID is the deterministic tie-breaker within an ordered page' );
	$tied_first = $reader->record_page( 'migrated', 1 );
	$check( '009' === $reader->record_page( 'migrated', 1, $tied_first['cursor'] )['rows'][0]['key'], 'keyset cursor retains same-order rows with larger row IDs' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET item_order = 1 WHERE blog_id = %d AND id = %d', $blog, $ids[ $blog ][1] ) );
	$refuses( function () use ( $wpdb, $blog, $page1 ) { ( new LegacyTableReader( $wpdb, $blog + 1 ) )->record_page( 'migrated', 1, $page1['cursor'] ); }, 'cross-blog cursor is refused' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET revision = 9 WHERE blog_id = %d AND id = %d', $blog, $heads[ $blog ] ) );
	$refuses( function () use ( $reader, $page1 ) { $reader->record_page( 'migrated', 1, $page1['cursor'] ); }, 'cursor captured before logical revision change is refused' );

	// Nested records are fetched separately, never as an all-tree read.
	$child_payload = $codec->encode( array( 'child' => true ) );
	$child_mutable = $codec->encode( array() );
	$parent = $codec->node_hash( $state_hash, null, 9 );
	$insert( $tables[1], array( 'blog_id' => $blog, 'state_id' => $heads[ $blog ], 'generation' => 2,
		'row_kind' => 'child', 'parent_node_hash' => $parent, 'node_hash' => $codec->node_hash( $state_hash, $parent, '03' ),
		'key_type' => 'string', 'key_value' => base64_encode( '03' ), 'depth' => 1,
		'payload' => $child_payload, 'mutable_payload' => $child_mutable, 'payload_hash' => hash( 'sha256', $child_payload ),
		'logical_hash' => $codec->logical_hash( array( 'child' => true ) ), 'payload_bytes' => strlen( $child_payload ) + strlen( $child_mutable ) ) );
	$check( '03' === $reader->record_page( 'migrated', 1, null, $parent )['rows'][0]['key'], 'bounded child page checks state and parent ancestry' );

	// Standalone complete logical-record format, not a repository/authority path.
	$records = new LegacyRecordCodec();
	$logical = array( 'migration_status' => 'queued', 'object_type' => 'album', 'ID' => '009', 'migrated' => false,
		'unknown' => array( 9 => false, '009' => null, 'bytes' => "\xff\x00雪" ),
		'children' => array( '009' => array( 'object_type' => 'gallery', 'plugin_name' => 'MixedCase', 'ID' => 9,
			'children' => array( -2 => array( 'object_type' => 'image', 'plugin_name' => 'MixedCase', 'ID' => '-007',
				'error' => array( 'code' => 'failed', 'message' => "\xff" ), 'migrated_id' => 204, 'migrated' => true ) ) ), 9 => null ), 'progress' => -0.0 );
	$rows = array();
	$stats = $records->flatten_tree( 'albums', '009', $logical, 7, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
	$album_head = $insert( $tables[0], array( 'blog_id' => $blog, 'state_key_hash' => $codec->key_hash( 'albums' ),
		'state_key_value' => $codec->key_value( 'albums' ), 'state_order' => 1, 'state_kind' => 'map', 'active_generation' => 2,
		'revision' => 8, 'codec_version' => 1, 'record_count' => $stats['record_count'], 'payload_bytes' => $stats['payload_bytes'] ) );
	foreach ( $rows as $row ) { $insert( $tables[1], array_merge( $row, array( 'blog_id' => $blog, 'state_id' => $album_head, 'generation' => 2 ) ) ); }
	$columns = array();
	foreach ( $definitions[ $tables[1] ]['columns'] as $name => $spec ) {
		$columns[] = 'binary(32)' === $spec[0] ? 'HEX(`' . $name . '`) AS `' . $name . '`' : '`' . $name . '`';
	}
	$tree_sql = $wpdb->prepare( 'SELECT ' . implode( ', ', $columns ) . ' FROM `' . $tables[1] . '` WHERE blog_id = %d AND state_id = %d AND generation = 2 ORDER BY id ASC LIMIT %d', $blog, $album_head, count( $rows ) + 1 );
	$stored = $wpdb->get_results( $tree_sql, ARRAY_A );
	$rebuilt = $records->rebuild_tree( 'albums', $rows[0]['node_hash'], $stored );
	$check( $logical === $rebuilt, 'real nested split records rebuild with exact types keys bytes and order' );
	$check( pack( 'd', -0.0 ) === pack( 'd', $rebuilt['progress'] ), 'negative zero survives split LONGTEXT and binary hashes' );
	$check( $codec->snapshot_digest( array( 'albums' => $logical, 'migrated-revision' => 17 ) ) === $codec->snapshot_digest( array( 'albums' => $rebuilt, 'migrated-revision' => 17 ) ), 'complete logical digest ignores physical row metadata and retains legacy revision' );
	$check( 7 === (int) $stored[2]['source_id_numeric'] && $codec->encode( '-007' ) === $stored[2]['source_id_value'], 'numeric source alias and exact source ID remain separate after real DB storage' );
	$tampered = $stored; $tampered[2]['migrated_id'] = '205';
	$refuses( function () use ( $records, $rows, $tampered ) { $records->rebuild_tree( 'albums', $rows[0]['node_hash'], $tampered ); }, 'DB-shaped derived projection corruption is refused' );
	$tampered = $stored; $mutable = $codec->decode( $tampered[2]['mutable_payload'] ); $mutable['migrated_title'] = 'tampered';
	$tampered[2]['mutable_payload'] = $codec->encode( $mutable );
	$tampered[2]['payload_bytes'] = strlen( $tampered[2]['payload'] ) + strlen( $tampered[2]['mutable_payload'] );
	$refuses( function () use ( $records, $rows, $tampered ) { $records->rebuild_tree( 'albums', $rows[0]['node_hash'], $tampered ); }, 'DB-shaped mutable corruption is refused by full logical-record hash' );

	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET payload_hash = UNHEX(%s) WHERE blog_id = %d AND id = %d', str_repeat( '0', 64 ), $blog, $ids[ $blog ][0] ) );
	$refuses( function () use ( $reader ) { $reader->record_page( 'migrated', 1 ); }, 'corrupt base payload hash fails closed' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET payload = %s WHERE blog_id = %d AND id = %d', str_repeat( 'x', LegacyTableReader::MAX_RECORD_BYTES + 1 ), $blog, $ids[ $blog ][0] ) );
	$refuses( function () use ( $reader ) { $reader->record_page( 'migrated', 1 ); }, 'oversized record is refused before payload fetch' );
	$check( false === strpos( $wpdb->last_query, ', key_type, key_value' ) && false !== strpos( $wpdb->last_query, 'AS encoded_bytes' ), 'last SQL on size refusal only selected bounded metadata' );
	$check( $schema->verify(), 'read/refusal paths do not modify schema health' );
	$result = array( 'status' => 'pass', 'scope' => 'inert shared schema / compact split tree integrity / bounded read primitives only; no conversion or authority flip',
		'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
		'blog_id' => $blog, 'checks' => $checks, 'peak_bytes' => memory_get_peak_usage( true ) );
} finally {
	foreach ( $created as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
}
foreach ( $tables as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'read-back confirms fixture table removed: ' . $table );
}
foreach ( $options as $option => $value ) {
	$check( $value === get_option( $option, $missing ), 'read-back confirms option untouched: ' . $option );
}
$result['checks'] = $checks;
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
