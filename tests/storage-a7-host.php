<?php
/** Actual host-only finite corpus/decoder/digest/observer checks, NO WordPress/SQL. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require ABSPATH . 'includes/class-legacy-option-decoder.php';
require ABSPATH . 'includes/class-legacy-state-codec.php';
require ABSPATH . 'includes/class-legacy-record-codec.php';
require ABSPATH . 'includes/class-table-reader.php';
require ABSPATH . 'includes/class-legacy-collection-envelope.php';
require ABSPATH . 'includes/class-migrator-settings.php';
require ABSPATH . 'includes/class-storage-scalar-observer.php';
require ABSPATH . 'tests/support/StorageA7Fixture.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture;
ini_set( 'serialize_precision', '-1' );
$checks = 0; $first = null; $decoder = array();
$check = function ( $ok, $label ) use ( &$checks ) { ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } };
try {
	$manifest = json_decode( file_get_contents( __DIR__ . '/integration/storage-a7-manifest.json' ), true );
	$codec = new LegacyStateCodec();
	foreach ( StorageA7Fixture::cells() as $count ) {
		$value = StorageA7Fixture::value( $count ); $raw = serialize( $value );
		$check( count( $value['galleries']['items'] ) === 1 && count( $value['galleries']['items']['gallery-009']['children'] ) === $count, 'Original image/gallery count' );
		$check( strlen( $raw ) === $manifest['cells'][$count]['raw_bytes'] && hash( 'sha256', $raw ) === $manifest['cells'][$count]['sha256'], 'Frozen raw hash' );
		$check( $codec->snapshot_digest( $value ) === $manifest['cells'][$count]['logical_sha256'], 'Frozen full logical hash' );
		$split = ( new \FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope() )->split( 'galleries', 'map', $value['galleries'] );
		$check( 1 === count( $split['items'] ) && 'gallery' === $split['items']['gallery-009']['object_type'], 'Actual compact-v1 gallery envelope' );
		if ( $count >= 10000 ) {
			$emitted = 0; $refused = null;
			try { ( new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec() )->flatten_tree( 'galleries', 'gallery-009', $split['items']['gallery-009'], 0, function () use ( &$emitted ) { ++$emitted; } ); }
			catch ( RuntimeException $error ) { $refused = $error->getMessage(); }
			$decoder[$count]['record_codec_refusal'] = $refused;
			$check( 'Legacy storage record codec: Tree exceeds the record budget.' === $refused && 0 === $emitted, 'Actual default record budget must refuse before emitting' );
		}
		try {
			$subject = new LegacyOptionDecoder( $manifest['experimental_policy']['max_raw_bytes'] );
			$decoded = $subject->decode( $raw );
			$decoder[$count] = array( 'decoded' => true );
			$check( $count < 10000 && $decoded === $value, 'Unexpected decoder admission/parity' );
		} catch ( RuntimeException $error ) {
			$decoder[$count] = array_merge( isset( $decoder[$count] ) ? $decoder[$count] : array(), array( 'decoded' => false, 'failure' => $error->getMessage() ) );
			$bound = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\LegacyOptionDecoder', 'max_nodes' ); if ( PHP_VERSION_ID < 80100 ) { $bound->setAccessible( true ); }
			$scan = new ReflectionMethod( 'FooPlugins\\FooGalleryMigrate\\LegacyOptionDecoder', 'scan' ); if ( PHP_VERSION_ID < 80100 ) { $scan->setAccessible( true ); }
			$offset = 0; $nodes = 0;
			try { $scan->invokeArgs( $subject, array( $raw, &$offset, 0, &$nodes, false ) ); }
			catch ( RuntimeException $observed ) { $decoder[$count]['scan_failure'] = $observed->getMessage(); }
			$decoder[$count]['nodes_at_refusal'] = $nodes; $decoder[$count]['offset_at_refusal'] = $offset;
			$check( $count >= 10000 && 30000 === $bound->getValue( $subject ) && isset( $decoder[$count]['scan_failure'] ), 'Unexpected decoder refusal: ' . $error->getMessage() );
		}
	}
	$over = StorageA7Fixture::over_bound_raw();
	$check( strlen( $over ) === $manifest['cells']['20000']['raw_bytes'] + 1 && hash( 'sha256', $over ) === $manifest['cells']['over-bound']['sha256'], 'Exact +1 byte refusal fixture' );
	try { ( new LegacyOptionDecoder( $manifest['experimental_policy']['max_raw_bytes'] ) )->decode( $over ); $check( false, 'Over-bound admitted' ); }
	catch ( RuntimeException $error ) { $check( 'Unsupported or unbounded serialized option. Retain recorded authority and all storage evidence.' === $error->getMessage(), 'Wrong boundary refusal reason' );
		$admitted = ( new LegacyOptionDecoder( strlen( $over ) ) )->decode( $over );
		$check( isset( $admitted['padding'] ), 'Boundary fixture is not independently supported' ); }
	$base = StorageA7Fixture::value( 20000 ); $digest = $codec->snapshot_digest( $base );
	$images = $base['galleries']['items']['gallery-009']['children'];
	$check( 'image-000' === key( $images ) && null === $images['image-000']['unknown']['09'] && false === $images['image-000']['unknown'][9], 'Held-out typed keys/scalars' );
	$check( "\xff\x00" === $images['image-000']['unknown']['binary'] && '雪🌍' === $images['image-000']['unknown']['unicode'], 'Binary/Unicode byte preservation' );
	$mutations = array(
		function ( &$v ) { $v['galleries']['items']['gallery-009']['children']['image-000']['unknown']['09'] = false; },
		function ( &$v ) { $v['galleries']['items']['gallery-009']['children'] = array_values( $v['galleries']['items']['gallery-009']['children'] ); },
		function ( &$v ) { $v['galleries']['items']['gallery-009']['children'] = array_reverse( $v['galleries']['items']['gallery-009']['children'], true ); },
		function ( &$v ) { unset( $v['galleries']['items']['gallery-009']['children']['image-000']['children']['boundary'] ); },
		function ( &$v ) { $v['galleries']['items']['gallery-009']['children']['image-000']['unknown']['binary'] = 'ff00'; },
		function ( &$v ) { $v['galleries']['items']['gallery-009']['children']['image-000']['ID'] = 0; },
	);
	foreach ( $mutations as $mutate ) { $changed = $base; $mutate( $changed ); $check( $codec->snapshot_digest( $changed ) !== $digest, 'Semantic mutation undetected' ); }
	$get = function ( $name, $set = false, $value = null ) {
		$p = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', $name ); if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
		if ( $set ) { $p->setValue( null, $value ); } return $p->getValue();
	};
	StorageScalarObserver::milestone( 'receiver-entry' ); $check( array() === $get( 'milestones' ), 'Observer must be inert by default' );
	$get( 'milestones_enabled', true, true ); StorageScalarObserver::milestone( 'receiver-entry' ); StorageScalarObserver::milestone( 'authorized' );
	$marks = $get( 'milestones' );
	$check( count( $marks ) === 2 && $marks[1]['time'] >= $marks[0]['time'] && $marks[1]['peak_allocated_cumulative'] >= $marks[1]['allocated'], 'Real phase observations' );
	StorageScalarObserver::milestone( 'payload-or-unknown' ); $check( false === $get( 'milestones_valid' ), 'Closed milestone vocabulary' );
	$get( 'milestones_valid', true, true ); $get( 'milestones', true, array() );
	for ( $i = 0; $i < 129; ++$i ) { StorageScalarObserver::milestone( 'receiver-entry' ); }
	$check( 128 === count( $get( 'milestones' ) ) && false === $get( 'milestones_valid' ), 'Finite milestone capacity' );
} catch ( Throwable $error ) { $first = $error->getMessage(); }
echo json_encode( array( 'domain' => 'host-only/no-WP/no-SQL', 'checks' => $checks, 'first_failure' => $first, 'decoder_observations' => $decoder ), JSON_PRETTY_PRINT ) . "\n";
exit( null === $first ? 0 : 1 );
