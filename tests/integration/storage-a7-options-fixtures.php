<?php
/** Host-only freeze of the separate proposal; no WordPress, SQL or native route. */
if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 70000 ) { throw new RuntimeException( 'PHP>=7 CLI producer required.' ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( dirname( __DIR__ ) ) . '/' ); }
require_once ABSPATH . 'includes/class-legacy-state-codec.php';
require_once dirname( __DIR__ ) . '/support/StorageA7OptionsFixture.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsFixture;
ini_set( 'serialize_precision', '-1' );
$value = StorageA7OptionsFixture::value();
$manifest = StorageA7OptionsFixture::manifest();
if ( json_decode( file_get_contents( __DIR__ . '/storage-a7-options-manifest.json' ), true ) !== $manifest ) {
	throw new RuntimeException( 'Frozen positive manifest mismatch.' );
}
if ( isset( $argv[1] ) ) {
	$directory = $argv[1];
	if ( file_exists( $directory ) || is_link( $directory ) || ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'New-only fixture directory required.' ); }
	$raw = serialize( $value );
	$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( file_put_contents( $directory . '/' . StorageA7OptionsFixture::NAME . '.serialized', $raw ) !== strlen( $raw ) ||
		file_put_contents( $directory . '/manifest.json', $json ) !== strlen( $json ) ) { throw new RuntimeException( 'Incomplete fixture freeze.' ); }
}
echo json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
