<?php
/** Run only on an owned disposable native WordPress fixture with table authority. */
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Plugins\WordPressCore;

if ( ! defined( 'FOOGALLERY_CONTENT_REPRESENTATION_FIXTURE' ) || ! FOOGALLERY_CONTENT_REPRESENTATION_FIXTURE ) {
    throw new RuntimeException( 'Owned disposable fixture required.' );
}
$checks = array();
$check = function ( $ok, $label ) use ( &$checks ) {
    if ( ! $ok ) { throw new RuntimeException( $label ); }
    $checks[] = $label;
};
$engine = new MigratorEngine();
$GLOBALS['foogallery_migrate_engine_instance'] = $engine;
$repository = $engine->content_scan_repository();
$check( $repository->is_table(), 'existing selected table authority' );
$item = array( 'post_id' => 999, 'plugin_name' => 'WordPress Core', 'type' => 'shortcode',
    'original_content' => '[gallery ids="1"]', 'nested' => array( "\xff\0", null, false, -0.0, 1.25 ) );
$typed = array( 'progress' => array( 'started' => true, 'scanned' => 2, 'opaque' => false ),
    'items' => array( '09' => $item, 9 => null, 'tail' => false ), 'opaque' => "\0\xff" );
$check( $engine->set_migrator_setting( ContentScanRepository::STATE_KEY, $typed ) !== false, 'settings snapshot write uses record representation' );
$check( serialize( $engine->get_migrator_setting( ContentScanRepository::STATE_KEY ) ) === serialize( $typed ) && serialize( $repository->read_snapshot() ) === serialize( $typed['items'] ), 'typed full compatibility state equality' );
$window = $repository->window( 1, 2 );
$check( array_keys( $window['items'] ) === array( 9, 'tail' ) && $window['total'] === 3, 'typed page keys and total' );
$check( $repository->get_many( array( 'tail', '09' ) ) === array( '09' => $item, 'tail' => false ), 'stored-key lookup preserves stored order' );
$check( $repository->update_match( '09', $item + array( 'updated' => true ) ), 'selected update' );
$before = $repository->window( 0, 4 );
$refused = false;
try { $repository->patch_batch( array( '09' => $item ), array(), $window['revision'] ); }
catch ( Exception $error ) { $refused = true; }
$check( $refused && $before === $repository->window( 0, 4 ), 'stale revision refuses without writes' );
$refused = false;
try { $repository->patch_batch( array(), array( 'oversized' => str_repeat( 'x', 1048576 ) ), $before['revision'] ); }
catch ( Exception $error ) { $refused = true; }
$check( $refused && $before === $repository->window( 0, 4 ), 'oversized checkpoint rolls back atomically' );
$check( $repository->update_with_scan_state( array( $item ), array( 'cursor' => 1 ) ), 'reset generation with first batch' );
$before = $repository->window( 0, 4 );
$check( $repository->append_batch( array( $item ), array( 'cursor' => 2 ), $before['revision'] ), 'retried occurrence upsert and checkpoint' );
$check( $repository->window( 0, 4 )['total'] === 1 && $repository->checkpoint()['cursor'] === 2, 'dedup does not append a duplicate' );
$item['scan_occurrence'] = 1;
$before = $repository->window( 0, 4 );
$check( $repository->append_batch( array( $item ), array( 'cursor' => 3 ), $before['revision'] ), 'distinct occurrence append' );
$check( array_keys( $repository->window( 0, 4 )['items'] ) === array( 0, 1 ), 'stable selectors across append' );

// Real scanner writes, not seeded findings. The fixture owns these two posts only.
$plugin = new WordPressCore(); $plugin->is_detected = true;
$engine->set_migrator_setting( 'plugins', array( $plugin ) );
$posts = array();
$limit = function () { return 1; };
add_filter( 'foogallery_migrate_content_scan_batch_size', $limit );
try {
    $attachment = wp_insert_attachment( array( 'post_title' => 'Owned representation image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '', 0, true );
    if ( is_wp_error( $attachment ) ) { throw new RuntimeException( $attachment->get_error_message() ); }
    $posts[] = $attachment;
    for ( $i = 0; $i < 2; $i++ ) {
        $id = wp_insert_post( array( 'post_title' => 'Owned representation scan', 'post_content' => '[gallery ids="' . $attachment . '"]',
            'post_status' => 'publish', 'post_type' => 'post' ), true );
        if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
        $posts[] = $id;
    }
    $content = $engine->get_content_migrator();
    $first = $content->scan_content_batch( true );
    $check( $first['scanned'] === 1 && ! $first['complete'], 'real scan reset first bounded post batch' );
    $second = $first;
    for ( $batch = 0; $batch < 10 && ! $second['complete']; $batch++ ) { $second = $content->scan_content_batch(); }
    $check( $second['complete'] && $second['scanned'] >= 2 && $repository->window( 0, 4 )['total'] === 2, 'real scan append persists findings and checkpoint' );
    add_filter( 'foogallery_migrate_content_status_batch_size', $limit );
    $status = $content->refresh_migration_status_batch( true );
    $check( $status['cursor'] === 1 && $status['total'] === 2 && ! $status['complete'], 'real status page patch and checkpoint' );
} finally {
    remove_filter( 'foogallery_migrate_content_scan_batch_size', $limit );
    remove_filter( 'foogallery_migrate_content_status_batch_size', $limit );
    foreach ( $posts as $id ) { wp_delete_post( $id, true ); $check( get_post( $id ) === null, 'owned scan post removed' ); }
}

// Exercise the actual coordinator in both directions on a small typed state.
$engine->set_migrator_setting( ContentScanRepository::STATE_KEY, $typed );
StorageMaintenanceGate::current()->end_writer();
unset( $GLOBALS['foogallery_migrate_engine_instance'], $repository, $engine );
$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
$convert = function ( $target, $replace ) use ( $policy, $check ) {
    $blog = get_current_blog_id();
    $purpose = $replace ? 'option_reimport' : ( 'table' === $target ? 'option_import' : 'table_export' );
    $request = array( 'blog_id' => $blog, 'scope' => 'site', 'target' => $target,
        'confirmation' => $replace ? 'replace-retained-with-table' : 'switch-to-' . $target,
        'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
    $conversion = new StorageConversion( $policy );
    $result = $replace ? $conversion->replace_retained( $request ) : $conversion->switch_backend( $request );
    $record = StorageControl::decode( get_option( \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION ), $blog );
    if ( is_wp_error( $result ) && 'stable' === $record['phase'] && 'table' === $record['backend'] ) {
        // Diagnose only this owned fixture after the real authorized API refused.
        // Do not change any gate, policy or authority decision.
        $probe = new StorageConversion( $policy ); $stage = 'start_memory';
        try {
            foreach ( array( 'start_memory', 'begin', 'storage', 'table_snapshot' ) as $stage ) {
                $method = new \ReflectionMethod( $probe, $stage ); $method->setAccessible( true );
                $method->invokeArgs( $probe, 'table_snapshot' === $stage ? array( true ) : array() );
            }
        } catch ( \Exception $error ) {
            throw new RuntimeException( 'owned conversion diagnostic ' . $stage . ': ' . $error->getMessage() );
        } finally {
            $method = new \ReflectionMethod( $probe, 'end' ); $method->setAccessible( true ); $method->invoke( $probe );
        }
    }
    $check( ! is_wp_error( $result ), 'actual conversion to ' . $target . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() . ' phase=' . $record['phase'] . ' backend=' . $record['backend'] . ': ' . $result->get_error_message() : '' ) );
};
$convert( 'option', false );
$engine = new MigratorEngine(); $GLOBALS['foogallery_migrate_engine_instance'] = $engine;
$check( ! $engine->content_scan_repository()->is_table(), 'reverse conversion selects option backend' );
$check( serialize( $engine->get_migrator_setting( ContentScanRepository::STATE_KEY ) ) === serialize( $typed ), 'reverse conversion exact typed content equality' );
unset( $GLOBALS['foogallery_migrate_engine_instance'], $engine );
$convert( 'table', true );
$engine = new MigratorEngine(); $GLOBALS['foogallery_migrate_engine_instance'] = $engine;
$check( $engine->content_scan_repository()->is_table(), 'forward conversion selects table backend' );
$check( serialize( $engine->content_scan_repository()->read_snapshot() ) === serialize( $typed['items'] ) && serialize( $engine->get_migrator_setting( ContentScanRepository::STATE_KEY ) ) === serialize( $typed ), 'forward conversion exact typed content equality' );
return $checks;
