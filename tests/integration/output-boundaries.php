<?php
/** Native WordPress escapers with synthetic source/repository data; no migrations. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned fixture required.' ); }
require_once dirname( __DIR__ ) . '/support/EscapingRenderFixtures.php';
use FooPlugins\FooGalleryMigrate\StorageAdmin;

$checks = 0;
$check = function ( $yes ) use ( &$checks ) { ++$checks; if ( ! $yes ) { throw new RuntimeException( 'Native output-boundary check failed: ' . $checks ); } };
$text = '\"><img id="fgm-probe" src=x onerror="alert(1)"><script id="fgm-script">alert(1)</script> & 雪';
foreach ( array( 'galleries', 'albums', 'content' ) as $kind ) {
	foreach ( array( false, true ) as $table ) {
		$html = \FooPlugins\FooGalleryMigrate\Tests\render_escaping_probe( $kind, $table, $text, false, 'javascript:alert(1)' );
		$check( false !== strpos( $html, esc_html( $text ) ) );
		$dom = new DOMDocument(); @$dom->loadHTML( $html ); $xpath = new DOMXPath( $dom );
		$check( 0 === $xpath->query( '//*[@id="fgm-probe" or @id="fgm-script" or @onerror]' )->length );
		$check( 0 === $xpath->query( '//a[starts-with(@href,"javascript:")]' )->length );
		$check( $xpath->query( '//input[@type="checkbox"]' )->length > 0 );
		$check( $xpath->query( '//button' )->length > 0 );
		$nonce = 'galleries' === $kind ? 'foogallery_migrate' : ( 'albums' === $kind ? 'foogallery_album_migrate' : 'foogallery_content_migrate' );
		$fields = $xpath->query( '//input[@name="' . $nonce . '"]' ); $check( 1 === $fields->length );
		$check( false !== wp_verify_nonce( $fields->item( 0 )->getAttribute( 'value' ), $nonce ) );
	}
}
foreach ( array( 'galleries', 'albums', 'content' ) as $kind ) {
	$level = ob_get_level(); ob_start(); echo 'caller';
	try { \FooPlugins\FooGalleryMigrate\Tests\render_escaping_probe( $kind, true, 'Not published', true ); throw new RuntimeException( 'Stale form was published.' ); }
	catch ( RuntimeException $error ) { $check( false !== strpos( $error->getMessage(), 'changed' ) ); $check( 'caller' === ob_get_contents() && $level + 1 === ob_get_level() ); }
	ob_end_clean();
}
$message = '<svg id="fgm-exception" onload="alert(1)">A & B</svg>';
$status = array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'target' => 'option', 'retained' => false, 'discardable' => false,
	'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => $message, 'calibrated' => true );
ob_start(); StorageAdmin::render_troubleshooting( $status ); $html = ob_get_clean();
$check( false !== strpos( $html, esc_html( $message ) ) ); $check( false === strpos( $html, '&amp;lt;svg' ) );
$dom = new DOMDocument(); @$dom->loadHTML( $html ); $xpath = new DOMXPath( $dom );
$check( 0 === $xpath->query( '//*[@id="fgm-exception" or @onload]' )->length );
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'scope' => 'real WordPress escapers/nonce/DOM; synthetic renderer data, no database mutation or browser execution' ) ) . "\n";
