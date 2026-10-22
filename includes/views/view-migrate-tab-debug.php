<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$migrator = foogallery_migrate_migrator_instance();
$scoped_history = $migrator->uses_scoped_history();
$migrated_objects = $scoped_history ? null : $migrator->get_migrated_objects();
$raw_output = '';

if ( $scoped_history ) {
	// Preserve print_r(array) output while releasing every bounded history page.
	// Only the rendered text grows with show-all; no object collection is cached.
	$cursor = null; $found = false; $revision = $migrator->get_migrated_revision();
	do {
		$previous_cursor = $cursor;
		$page = $migrator->get_migrated_log_page( '', '', 100, $cursor );
		if ( ! is_array( $page ) || ! isset( $page['rows'], $page['revision'] ) ||
			! is_array( $page['rows'] ) || count( $page['rows'] ) > 100 ||
			$page['revision'] !== $revision || ! array_key_exists( 'cursor', $page ) ||
			( null !== $page['cursor'] && ! is_array( $page['cursor'] ) ) ||
			( empty( $page['rows'] ) && null !== $page['cursor'] ) ) {
			throw new \RuntimeException( 'Invalid or changed bounded debug history page.' );
		}
		foreach ( $page['rows'] as $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'key', $entry ) || ! array_key_exists( 'value', $entry ) ||
				( ! is_string( $entry['key'] ) && ! is_int( $entry['key'] ) ) ) {
				throw new \RuntimeException( 'Invalid bounded debug history row.' );
			}
			if ( ! is_object( $entry['value'] ) ) { continue; }
			if ( ! $found ) { $raw_output = "Array\n(\n"; $found = true; }
			$piece = print_r( array( $entry['key'] => $entry['value'] ), true );
			$raw_output .= substr( $piece, strlen( "Array\n(\n" ), -strlen( ")\n" ) );
		}
		$cursor = $page['cursor'];
		if ( null !== $cursor && $cursor === $previous_cursor ) { throw new \RuntimeException( 'Debug history cursor did not advance.' ); }
	} while ( null !== $cursor );
	if ( $migrator->get_migrated_revision() !== $revision ) { throw new \RuntimeException( 'History changed during debug rendering.' ); }
	if ( $found ) { $raw_output .= ")\n"; }
} elseif ( ! empty( $migrated_objects ) ) {
	$raw_output = print_r( $migrated_objects, true );
}
?>
<?php if ( $scoped_history ? ! $found : ( empty( $migrated_objects ) || ! is_array( $migrated_objects ) ) ) { ?>
	<p><?php esc_html_e( 'No migrated objects found.', 'foogallery-migrate' ); ?></p>
<?php } ?>
<textarea class="large-text code" rows="20" readonly="readonly"><?php echo esc_textarea( $raw_output ); ?></textarea>
