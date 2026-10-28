<?php
/** Modern CLI-only completion validator; excluded from production packages. */
try {
	if ( count( $argv ) !== 3 ) { throw new RuntimeException( 'Need output path and case ID.' ); }
	$text = file_get_contents( $argv[1] );
	$sentinel = 'PREFLIGHT_CASE_COMPLETE:' . $argv[2];
	if ( false === $text || 1 !== substr_count( $text, $sentinel ) || substr( rtrim( $text ), -strlen( $sentinel ) ) !== $sentinel ) {
		throw new RuntimeException( 'Missing, repeated or nonfinal completion sentinel.' );
	}
	$payload = json_decode( trim( substr( $text, 0, strpos( $text, $sentinel ) ) ), true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) || ! isset( $payload['status'], $payload['checks'], $payload['check_count'], $payload['blog_id'], $payload['wordpress'], $payload['php'], $payload['database'] ) ||
		'pass' !== $payload['status'] || ! is_array( $payload['checks'] ) || ! count( $payload['checks'] ) ||
		count( $payload['checks'] ) !== $payload['check_count'] || ! is_int( $payload['blog_id'] ) || $payload['blog_id'] < 1 ) {
		throw new RuntimeException( 'Invalid or unsuccessful native test payload.' );
	}
	foreach ( $payload['checks'] as $check ) {
		if ( ! is_string( $check ) || '' === $check ) { throw new RuntimeException( 'Invalid check label.' ); }
	}
	echo count( $payload['checks'] ) . " validated primitive checks\n";
} catch ( Exception $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
