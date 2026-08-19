<?php
/**
 * Lightweight regression tests for bounded, atomic content scanning.
 *
 * Run with: php tests/content-scan-regression.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['test_batch_size'] = 2;
$GLOBALS['test_time_limit'] = 30;

function apply_filters( $hook, $value ) {
	if ( 'foogallery_migrate_content_scan_batch_size' === $hook ) {
		return $GLOBALS['test_batch_size'];
	}
	if ( 'foogallery_migrate_content_scan_time_limit' === $hook ) {
		return $GLOBALS['test_time_limit'];
	}
	return $value;
}

function parse_blocks( $content ) {
	$blocks = array();
	if ( preg_match_all( '/<!-- wp:example\/gallery \{"id":(\d+)\} \/-->/', $content, $matches ) ) {
		foreach ( $matches[1] as $id ) {
			$blocks[] = array(
				'blockName' => 'example/gallery',
				'attrs' => array( 'id' => (int) $id ),
				'innerBlocks' => array(),
				'innerContent' => array(),
				'innerHTML' => '',
				'serialized' => '<!-- wp:example/gallery {"id":' . (int) $id . '} /-->',
			);
		}
	}
	return $blocks;
}

function serialize_block( $block ) {
	return $block['serialized'];
}

class FakeWpdb {
	public $posts = 'wp_posts';
	public $queries = array();
	private $rows;

	public function __construct( $rows ) {
		$this->rows = $rows;
	}

	public function replace_rows( $rows ) {
		$this->rows = $rows;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		return preg_replace_callback(
			'/%[ds]/',
			function( $match ) use ( &$args ) {
				$arg = array_shift( $args );
				return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( $arg ) . "'";
			},
			$query
		);
	}

	public function get_results( $query ) {
		$this->queries[] = $query;
		preg_match( '/ID\s*>\s*(\d+)/', $query, $cursor_match );
		preg_match( '/LIMIT\s+(\d+)/i', $query, $limit_match );
		$cursor = isset( $cursor_match[1] ) ? (int) $cursor_match[1] : 0;
		$limit = isset( $limit_match[1] ) ? (int) $limit_match[1] : count( $this->rows );
		$rows = array_filter(
			$this->rows,
			function( $row ) use ( $cursor ) {
				return $row->ID > $cursor && 'publish' === $row->post_status && in_array( $row->post_type, array( 'post', 'page' ), true ) && ( false !== strpos( $row->post_content, '[' ) || false !== strpos( $row->post_content, '<!-- wp:' ) );
			}
		);
		usort( $rows, function( $a, $b ) { return $a->ID - $b->ID; } );
		return array_slice( $rows, 0, $limit );
	}
}

class FakeMigratedObject {
	public $ID;
	public $migrated = true;
	public $migrated_id;
	public $plugin;

	public function __construct( $id, $plugin ) {
		$this->ID = $id;
		$this->migrated_id = 1000 + $id;
		$this->plugin = $plugin;
	}

	public function type() {
		return 'gallery';
	}
}

class FakePlugin {
	public $is_detected = true;
	public $throw_once = false;
	public $delay_microseconds = 0;

	public function name() {
		return 'Example Gallery';
	}

	public function get_block_patterns() {
		return array( 'example/gallery' => true );
	}

	public function get_shortcode_patterns() {
		if ( $this->delay_microseconds > 0 ) {
			usleep( $this->delay_microseconds );
		}
		if ( $this->throw_once ) {
			$this->throw_once = false;
			throw new RuntimeException( 'Synthetic scanner failure' );
		}
		return array( '/\[example-gallery\s+id=["\']?(\d+)["\']?\]/' );
	}
}

class FakeEngine {
	public $settings = array();
	public $fail_next_write = false;
	private $plugin;
	private $migrated_objects = array();

	public function __construct( $plugin, $ids ) {
		$this->plugin = $plugin;
		foreach ( $ids as $id ) {
			$this->migrated_objects[] = new FakeMigratedObject( $id, $plugin );
		}
	}

	public function get_migrator_setting( $name, $default = false ) {
		return array_key_exists( $name, $this->settings ) ? $this->settings[ $name ] : $default;
	}

	public function set_migrator_setting( $name, $value ) {
		if ( $this->fail_next_write ) {
			$this->fail_next_write = false;
			return false;
		}
		$this->settings[ $name ] = $value;
		return true;
	}

	public function get_plugins() {
		return array( $this->plugin );
	}

	public function get_migrated_objects() {
		return $this->migrated_objects;
	}
}

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function assert_true( $actual, $message ) {
	assert_same( true, (bool) $actual, $message );
}

function assert_throws( $callback, $message ) {
	try {
		$callback();
	} catch ( Throwable $error ) {
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\nExpected an exception.\n" );
	exit( 1 );
}

function make_posts( $ids, $content_callback = null ) {
	$posts = array();
	foreach ( $ids as $id ) {
		$content = $content_callback ? $content_callback( $id ) : '[example-gallery id="' . $id . '"]';
		$posts[] = (object) array(
			'ID' => $id,
			'post_title' => 'Post ' . $id,
			'post_content' => $content,
			'post_type' => 'post',
			'post_status' => 'publish',
		);
	}
	return $posts;
}

require_once dirname( __DIR__ ) . '/includes/migrators/class-content-migrator.php';

use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;

// Batches are bounded and use a resumable keyset cursor.
$plugin = new FakePlugin();
$engine = new FakeEngine( $plugin, range( 1, 5 ) );
$wpdb = new FakeWpdb( make_posts( range( 1, 5 ) ) );
$GLOBALS['wpdb'] = $wpdb;
$migrator = new ContentMigrator( $engine, 'content' );
$progress = $migrator->scan_content_batch( true );
$state = $engine->settings['content_scan_state'];
assert_same( 2, $progress['scanned'], 'the first request processes only the configured batch size' );
assert_same( 2, count( $state['items'] ), 'findings are persisted in the atomic state' );
assert_true( false !== stripos( $wpdb->queries[0], 'LIMIT 3' ), 'the bounded query fetches one lookahead row' );
assert_true( false !== stripos( $wpdb->queries[0], 'ID > 0' ), 'the query uses a keyset cursor' );
assert_same( false, $progress['complete'], 'lookahead reports more work' );

// Persistence failure leaves both findings and cursor unchanged, then retry succeeds.
$before_failed_write = $engine->settings['content_scan_state'];
$engine->fail_next_write = true;
assert_throws( function() use ( $migrator ) { $migrator->scan_content_batch(); }, 'state persistence failure is surfaced' );
assert_same( $before_failed_write, $engine->settings['content_scan_state'], 'failed persistence cannot advance cursor or findings' );
$progress = $migrator->scan_content_batch();
assert_same( 4, $progress['cursor'], 'the same batch remains retryable after failed persistence' );
assert_same( 4, count( $engine->settings['content_scan_state']['items'] ), 'retry commits the findings atomically' );

// Exact multiples complete on the final full batch without an empty follow-up query.
$exact_engine = new FakeEngine( $plugin, range( 1, 4 ) );
$exact_wpdb = new FakeWpdb( make_posts( range( 1, 4 ) ) );
$GLOBALS['wpdb'] = $exact_wpdb;
$exact_migrator = new ContentMigrator( $exact_engine, 'content' );
assert_same( false, $exact_migrator->scan_content_batch( true )['complete'], 'the first exact-multiple batch is incomplete' );
$exact_progress = $exact_migrator->scan_content_batch();
assert_same( true, $exact_progress['complete'], 'the final full batch is complete' );
assert_same( 2, count( $exact_wpdb->queries ), 'exact multiples do not require an empty third query' );

// A failed replacement scan preserves the last successful snapshot until reset commits.
$reset_engine = new FakeEngine( $plugin, array( 1, 2, 10, 11 ) );
$reset_wpdb = new FakeWpdb( make_posts( array( 1, 2 ) ) );
$GLOBALS['wpdb'] = $reset_wpdb;
$reset_migrator = new ContentMigrator( $reset_engine, 'content' );
$reset_migrator->scan_content_batch( true );
$old_snapshot = $reset_engine->settings['content_scan_state'];
$reset_wpdb->replace_rows( make_posts( array( 10, 11 ) ) );
$reset_engine->fail_next_write = true;
assert_throws( function() use ( $reset_migrator ) { $reset_migrator->scan_content_batch( true ); }, 'replacement scan write failure is surfaced' );
assert_same( $old_snapshot, $reset_engine->settings['content_scan_state'], 'failed reset preserves the previous successful results' );
$reset_migrator->scan_content_batch( true );
assert_same( 10, $reset_engine->settings['content_scan_state']['items'][0]['gallery_id'], 'successful reset atomically replaces old results' );

// A transient parser failure does not advance state and succeeds on retry.
$retry_plugin = new FakePlugin();
$retry_plugin->throw_once = true;
$retry_engine = new FakeEngine( $retry_plugin, range( 1, 2 ) );
$retry_wpdb = new FakeWpdb( make_posts( range( 1, 2 ) ) );
$GLOBALS['wpdb'] = $retry_wpdb;
$retry_migrator = new ContentMigrator( $retry_engine, 'content' );
assert_throws( function() use ( $retry_migrator ) { $retry_migrator->scan_content_batch( true ); }, 'transient parser failure stops the batch' );
assert_same( false, isset( $retry_engine->settings['content_scan_state'] ), 'failed parsing does not persist an advanced cursor' );
$retry_progress = $retry_migrator->scan_content_batch();
assert_same( true, $retry_progress['complete'], 'retry successfully processes the formerly failing post' );
assert_same( 2, count( $retry_engine->settings['content_scan_state']['items'] ), 'retry retains every finding' );

// Repeated identical block and shortcode occurrences remain distinct findings.
$repeat_content = '<!-- wp:example/gallery {"id":7} /--><!-- wp:example/gallery {"id":7} /--> [example-gallery id="7"] [example-gallery id="7"]';
$repeat_engine = new FakeEngine( $plugin, array( 7 ) );
$repeat_wpdb = new FakeWpdb( make_posts( array( 7 ), function() use ( $repeat_content ) { return $repeat_content; } ) );
$GLOBALS['wpdb'] = $repeat_wpdb;
$repeat_migrator = new ContentMigrator( $repeat_engine, 'content' );
$repeat_migrator->scan_content_batch( true );
$repeat_items = $repeat_engine->settings['content_scan_state']['items'];
assert_same( 4, count( $repeat_items ), 'identical block and shortcode occurrences are not deduplicated' );
assert_same( array( 0, 1, 2, 3 ), array_column( $repeat_items, 'scan_occurrence' ), 'each repeated occurrence has stable per-post identity' );

// Time budget stops between posts and leaves the next post retryable.
$GLOBALS['test_batch_size'] = 5;
$GLOBALS['test_time_limit'] = 1;
$slow_plugin = new FakePlugin();
$slow_plugin->delay_microseconds = 600000;
$slow_engine = new FakeEngine( $slow_plugin, range( 1, 3 ) );
$slow_wpdb = new FakeWpdb( make_posts( range( 1, 3 ) ) );
$GLOBALS['wpdb'] = $slow_wpdb;
$slow_migrator = new ContentMigrator( $slow_engine, 'content' );
$slow_progress = $slow_migrator->scan_content_batch( true );
assert_same( 2, $slow_progress['scanned'], 'time cutoff stops before starting another post' );
assert_same( 2, $slow_progress['cursor'], 'time cutoff advances only through successfully parsed posts' );
assert_same( false, $slow_progress['complete'], 'time cutoff remains resumable' );
$slow_plugin->delay_microseconds = 0;
assert_same( true, $slow_migrator->scan_content_batch()['complete'], 'a later request resumes after the time cutoff' );

fwrite( STDOUT, "PASS: atomic bounded content scan regression tests\n" );
