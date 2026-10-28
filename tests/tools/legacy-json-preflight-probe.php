<?php
/** Isolated native-boundary instrumentation; no production hooks or storage. */
namespace FooPlugins\FooGalleryMigrate;

function json_decode( $json, $assoc, $depth ) {
	++$GLOBALS['native_decodes'];
	return \json_decode( $json, $assoc, $depth );
}
function substr( $value, $offset, $length ) {
	$GLOBALS['substring_bytes'] += $length;
	if ( $length > 5 ) { throw new \RuntimeException( 'Unbounded scalar copy in JSON preflight.' ); }
	return \substr( $value, $offset, $length );
}
function max( $left, $right ) {
	++$GLOBALS['depth_events'];
	return \max( $left, $right );
}

define( 'ABSPATH', dirname( dirname( __DIR__ ) ) . '/' );
require ABSPATH . 'includes/class-legacy-state-codec.php';
$cases = array(
	'oversized_raw' => array( new LegacyStateCodec( 100, 2, 1 ), str_repeat( ' ', 101 ) ),
	'malformed_decimal_prefix' => array( new LegacyStateCodec( 2000000, 2, 1 ), '{"version":' . str_repeat( '9', 1000000 ) . '}' ),
	'duplicate_key_flood' => array( new LegacyStateCodec( 2000000, 2, 1 ), '{' . str_repeat( '"version":1,', 50000 ) ),
	'depth_flood' => array( new LegacyStateCodec( 2000000, 2, 100 ), str_repeat( '[', 1000000 ) ),
	'token_flood' => array( new LegacyStateCodec( 2000000, 2, 1 ), '[' . str_repeat( 'true,', 100000 ) ),
	'unterminated_string' => array( new LegacyStateCodec( 2000000, 2, 1 ), '{"value":"unterminated' )
);
$result = array();
foreach ( $cases as $name => $case ) {
	$GLOBALS['native_decodes'] = 0; $GLOBALS['substring_bytes'] = 0; $GLOBALS['depth_events'] = 0;
	try { $case[0]->decode( $case[1] ); throw new \Exception( 'Expected pre-native refusal.' ); }
	catch ( \RuntimeException $error ) {
		if ( 0 !== $GLOBALS['native_decodes'] || $GLOBALS['depth_events'] > 9 || $GLOBALS['substring_bytes'] > 40 ) {
			throw new \Exception( 'Native-boundary/work assertion failed for ' . $name );
		}
		$result[$name] = array( 'input_bytes' => strlen( $case[1] ), 'native_decode_calls' => $GLOBALS['native_decodes'],
			'container_peak_updates' => $GLOBALS['depth_events'], 'scalar_substring_bytes' => $GLOBALS['substring_bytes'], 'error' => $error->getMessage() );
	}
}
$GLOBALS['native_decodes'] = 0; $GLOBALS['substring_bytes'] = 0; $GLOBALS['depth_events'] = 0;
$codec = new LegacyStateCodec();
$json = '{"version":1,"value":["boolean",true]}';
if ( true !== $codec->decode( $json ) || 1 !== $GLOBALS['native_decodes'] ) { throw new \Exception( 'Valid input missed the native boundary.' ); }
$result['valid_boolean'] = array( 'native_decode_calls' => $GLOBALS['native_decodes'], 'inspection' => $codec->inspect_json( $json ) );
print \json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
