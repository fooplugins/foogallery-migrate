<?php
/**
 * Synthetic migrated-state scale benchmark.
 *
 * Usage: php scripts/benchmark-migrated-store.php --records=5000
 */

require_once dirname( __DIR__ ) . '/tests/bootstrap.php';

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

class FooGalleryMigrateScalePlugin extends Plugin {
	public function name() { return 'ScaleBenchmark'; }
	public function detect() { return true; }
	public function find_galleries() { return array(); }
	public function find_albums() { return array(); }
	public function get_gallery_template( $gallery ) { return 'default'; }
	public function get_gallery_settings( $gallery, $default_settings ) { return $default_settings; }
}

$options = getopt( '', array( 'records::' ) );
$record_count = isset( $options['records'] ) ? max( 1, (int) $options['records'] ) : 5000;
$gallery_children = 204;
$batch_size = 5;
$existing_children = $gallery_children - $batch_size;
$target_parent = 'gallery_ScaleBenchmark_204';
$plugin = new FooGalleryMigrateScalePlugin();
$settings = new MigratorSettings();
$store = new MigratedStore( false, $settings );
$seed_records = array();
$legacy_records = array();

for ( $i = 1; $i <= $record_count; $i++ ) {
	$image = new Image( $plugin );
	$image->source_url = 'https://history.example.test/' . $i . '.jpg';
	$image->migrated = true;
	$image->migrated_id = 100000 + $i;
	$image->migration_status = Migratable::PROGRESS_COMPLETED;
	$seed_records[] = array( 'object' => $image, 'parent_key' => 'gallery_ScaleBenchmark_history' );
	$legacy_records[ $image->unique_identifier() ] = $settings->compact_migrated_object( $image );
}

for ( $i = 1; $i <= $existing_children; $i++ ) {
	$image = new Image( $plugin );
	$image->source_url = 'https://current.example.test/' . $i . '.jpg';
	$image->migrated = true;
	$image->migrated_id = 200000 + $i;
	$image->migration_status = Migratable::PROGRESS_COMPLETED;
	$seed_records[] = array( 'object' => $image, 'parent_key' => $target_parent );
	$legacy_records[ $image->unique_identifier() ] = $settings->compact_migrated_object( $image );
}

$store->upsert_batch( $seed_records );
$new_records = array();
$new_payload_bytes = 0;
for ( $i = $existing_children + 1; $i <= $gallery_children; $i++ ) {
	$image = new Image( $plugin );
	$image->source_url = 'https://current.example.test/' . $i . '.jpg';
	$image->migrated = true;
	$image->migrated_id = 200000 + $i;
	$image->migration_status = Migratable::PROGRESS_COMPLETED;
	$new_records[] = array( 'object' => $image, 'parent_key' => $target_parent );
	$compact = $settings->compact_migrated_object( $image );
	$new_payload_bytes += strlen( json_encode( $compact ) );
}

$legacy_start_memory = memory_get_usage( true );
$legacy_start = microtime( true );
$serialized = serialize( $legacy_records );
$hydrated = unserialize( $serialized );
foreach ( $new_records as $record ) {
	$hydrated[ $record['object']->unique_identifier() ] = $settings->compact_migrated_object( $record['object'] );
}
$rewritten = serialize( $hydrated );
$legacy_elapsed = ( microtime( true ) - $legacy_start ) * 1000;
$legacy_memory_delta = max( 0, memory_get_usage( true ) - $legacy_start_memory );
unset( $hydrated );

$store->reset_diagnostics();
$table_start_memory = memory_get_usage( true );
$table_start = microtime( true );
$store->get( 'https://history.example.test/1.jpg' );
$store->get_by_parent( $target_parent );
$store->upsert_batch( $new_records );
$table_elapsed = ( microtime( true ) - $table_start ) * 1000;
$table_memory_delta = max( 0, memory_get_usage( true ) - $table_start_memory );
$table = $store->diagnostics();
$table['payload_bytes_written'] = $new_payload_bytes;
$table['elapsed_ms'] = round( $table_elapsed, 3 );
$table['memory_delta_bytes'] = $table_memory_delta;
$table['backend'] = 'in-memory test double; SQL index cost represented by logical query counts';

$result = array(
	'records' => $record_count,
	'gallery_children' => $gallery_children,
	'batch_size' => $batch_size,
	'legacy' => array(
		'serialized_bytes_before' => strlen( $serialized ),
		'serialized_bytes_rewritten' => strlen( $rewritten ),
		'elapsed_ms' => round( $legacy_elapsed, 3 ),
		'memory_delta_bytes' => $legacy_memory_delta,
	),
	'table' => $table,
	'assertions' => array(
		'bounded_io' => 2 === $table['read_queries'] && 1 === $table['write_queries'] && $batch_size === $table['rows_written'],
		'avoids_full_history' => 0 === $table['full_history_hydrations'],
	),
);

echo json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;
