<?php
/** Exact production ZIP/source identity and extracted-tree guard for owned fixtures. */
if ( 'cli' !== PHP_SAPI || 4 !== $argc || ! in_array( $argv[1], array( 'inspect', 'verify-tree' ), true ) ) {
	throw new RuntimeException( 'Use inspect ZIP SOURCE or verify-tree PLUGIN MANIFEST.' );
}
function package_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
function package_digest( $files ) {
	ksort( $files, SORT_STRING );
	$context = hash_init( 'sha256' );
	foreach ( $files as $path => $digest ) {
		package_check( is_string( $path ) && is_string( $digest ) &&
			preg_match( '/^[a-f0-9]{64}$/D', $digest ), 'Invalid package file identity.' );
		hash_update( $context, $path . "\0" . $digest . "\n" );
	}
	return hash_final( $context );
}
function package_path( $path ) {
	package_check( is_string( $path ) && $path !== '' && $path[0] !== '/' &&
		strpos( $path, '\\' ) === false && strpos( $path, "\0" ) === false &&
		! preg_match( '~(^|/)(\.|\.\.|)(/|$)~', $path ) &&
		! in_array( explode( '/', $path )[0], array( 'vendor', 'tests', 'node_modules' ), true ),
		'Unsafe or development package path.' );
}
if ( $argv[1] === 'inspect' ) {
	$zip_path = realpath( $argv[2] );
	$source = realpath( $argv[3] );
	package_check( class_exists( 'ZipArchive' ) && $zip_path && is_file( $zip_path ) &&
		$source && is_dir( $source ), 'ZIP or source directory unavailable.' );
	$zip = new ZipArchive();
	package_check( true === $zip->open( $zip_path ), 'Production ZIP could not be opened.' );
	$files = array();
	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$name = $zip->getNameIndex( $index );
		package_check( is_string( $name ), 'ZIP entry name unavailable.' );
		if ( substr( $name, -1 ) === '/' ) { package_path( substr( $name, 0, -1 ) ); continue; }
		package_path( $name );
		package_check( ! isset( $files[$name] ), 'Duplicate package path.' );
		$path = $source . '/' . $name;
		$bytes = $zip->getFromIndex( $index );
		package_check( is_string( $bytes ) && is_file( $path ) && ! is_link( $path ) &&
			file_get_contents( $path ) === $bytes, 'ZIP/source byte parity failed: ' . $name );
		$files[$name] = hash( 'sha256', $bytes );
	}
	$zip->close();
	package_check( isset( $files['migrate.php'], $files['uninstall.php'], $files['includes/functions.php'] ),
		'Production entrypoints missing from package.' );
	ksort( $files, SORT_STRING );
	echo json_encode( array( 'zip_sha256' => hash_file( 'sha256', $zip_path ),
		'tree_sha256' => package_digest( $files ), 'files' => $files ), JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	$plugin = realpath( $argv[2] );
	$manifest_path = realpath( $argv[3] );
	package_check( $plugin && is_dir( $plugin ) && ! is_link( $argv[2] ) &&
		$manifest_path && is_file( $manifest_path ), 'Extracted package or manifest unavailable.' );
	$manifest = json_decode( file_get_contents( $manifest_path ), true );
	package_check( is_array( $manifest ) && isset( $manifest['package'] ) &&
		is_array( $manifest['package'] ) && isset( $manifest['package']['zip_sha256'],
		$manifest['package']['tree_sha256'], $manifest['package']['files'] ) &&
		is_array( $manifest['package']['files'] ) &&
		preg_match( '/^[a-f0-9]{64}$/D', $manifest['package']['zip_sha256'] ) &&
		preg_match( '/^[a-f0-9]{64}$/D', $manifest['package']['tree_sha256'] ),
		'Pinned package identity missing or invalid.' );
	$expected = $manifest['package']['files'];
	$actual = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin,
		FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $iterator as $entry ) {
		$path = $entry->getPathname();
		package_check( ! is_link( $path ), 'Extracted package contains a symlink.' );
		if ( $entry->isDir() ) { continue; }
		package_check( $entry->isFile(), 'Extracted package contains a non-file.' );
		$name = substr( $path, strlen( $plugin ) + 1 );
		package_path( $name );
		$actual[$name] = hash_file( 'sha256', $path );
	}
	ksort( $actual, SORT_STRING );
	ksort( $expected, SORT_STRING );
	package_check( $actual === $expected &&
		package_digest( $actual ) === $manifest['package']['tree_sha256'],
		'Extracted production tree differs from pinned ZIP bytes.' );
	echo $manifest['package']['tree_sha256'] . "\n";
}
