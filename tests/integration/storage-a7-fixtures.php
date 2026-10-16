<?php
/** Host-only deterministic producer; no WordPress bootstrap or SQL. */
require_once dirname( __DIR__ ) . '/support/StorageA7Fixture.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture;
define( 'ABSPATH', dirname( dirname( __DIR__ ) ) . '/' );
require_once ABSPATH . 'includes/class-legacy-state-codec.php';
if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 70000 ) { throw new RuntimeException( 'PHP>=7 CLI corpus producer required.' ); }
ini_set( 'serialize_precision', '-1' );
$manifest = StorageA7Fixture::manifest();
foreach ( StorageA7Fixture::cells() as $count ) {
	$manifest['cells'][(string) $count]['logical_sha256'] = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->snapshot_digest( StorageA7Fixture::value( $count ) );
}
if ( isset( $argv[1] ) ) {
	$directory = $argv[1];
	if ( file_exists( $directory ) || is_link( $directory ) || ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'New-only fixture directory required.' ); }
	foreach ( StorageA7Fixture::cells() as $count ) { file_put_contents( $directory . '/' . $count . '.serialized', serialize( StorageA7Fixture::value( $count ) ) ); }
	file_put_contents( $directory . '/over-bound.serialized', StorageA7Fixture::over_bound_raw() );
	file_put_contents( $directory . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
echo json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
