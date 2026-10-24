<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageSourceCounts;
use PHPUnit\Framework\TestCase;

class StorageSourceCountsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$GLOBALS['foogallery_migrate_test_filters'] = array();
		$GLOBALS['wpdb'] = new SourceCountsWpdb();
	}
	protected function tearDown(): void {
		unset( $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_is_multisite'], $GLOBALS['foogallery_migrate_test_filters'], $GLOBALS['wpdb'], $_GET );
	}
	private function message( $overrides = array() ) {
		$status = array_merge( array( 'backend' => 'option', 'phase' => 'stable', 'option_bytes' => '0', 'calibrated' => true, 'problem' => '' ), $overrides );
		$method = new \ReflectionMethod( StorageAdmin::class, 'recommendation_message' ); $method->setAccessible( true );
		return $method->invoke( null, $status );
	}
	public function test_aggregate_counts_do_not_hydrate_sources_and_missing_image_counts_stay_unknown(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = '100';
		$this->assertSame( array( 'galleries' => 100, 'images' => null ), StorageSourceCounts::measure() );
		$this->assertFalse( $db->suppressed );
		$this->assertCount( 5, $db->queries, 'Two scalar post counts and three missing source-table checks.' );
		foreach ( $db->queries as $sql ) {
			$this->assertTrue( 0 === strpos( $sql, 'SELECT COUNT(' ) || 0 === strpos( $sql, 'SHOW TABLES LIKE ' ) );
			$this->assertStringNotContainsString( 'SELECT *', $sql );
			$this->assertStringNotContainsString( 'SELECT meta_value', $sql );
		}
		$this->assertStringContainsString( 'Detected gallery records: 100.', $this->message() );
		$this->assertStringNotContainsString( 'image records:', $this->message() );
	}
	public function test_counts_sum_only_scalar_source_totals_with_a_fixed_query_budget(): void {
		$db = $GLOBALS['wpdb']; $db->tables = array( 'wp_ngg_gallery', 'wp_ngg_pictures', 'wp_bwg_gallery', 'wp_bwg_image', 'wp_wppa_albums', 'wp_wppa_photos' );
		$db->post_galleries = '3'; $db->aigpl_galleries = '4';
		$db->source_counts = array( 'ngg_gallery' => '5', 'ngg_pictures' => '1000', 'bwg_gallery' => '6', 'bwg_image' => '2000', 'wppa_albums' => '7', 'wppa_photos' => '3000' );
		$this->assertSame( array( 'galleries' => 25, 'images' => 6000 ), StorageSourceCounts::measure() );
		$this->assertCount( 14, $db->queries );
		$sql = implode( "\n", $db->queries );
		$this->assertStringContainsString( 'g.gid=i.galleryid WHERE (i.exclude=0 OR i.exclude IS NULL)', $sql );
		$this->assertStringContainsString( 'g.id=i.gallery_id WHERE g.published=1', $sql );
		$this->assertStringContainsString( "TRIM(g.capability)=''", $sql );
		$this->assertStringContainsString( 'EXISTS (SELECT 1', $sql );
		$this->assertStringContainsString( 'COUNT(DISTINCT g.id)', $sql );
	}
	public function test_each_advisory_boundary_is_inclusive_even_before_any_state_is_saved(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = '99';
		$this->assertSame( '', $this->message() );
		$db->post_galleries = '100';
		$this->assertStringContainsString( 'before migrating', $this->message() );
		$db->post_galleries = '0'; $db->tables = array( 'wp_ngg_gallery', 'wp_ngg_pictures' ); $db->source_counts['ngg_pictures'] = '999';
		$this->assertSame( '', $this->message() );
		$db->source_counts['ngg_pictures'] = '1000';
		$formatted = function_exists( 'number_format_i18n' ) ? number_format_i18n( 1000 ) : number_format( 1000 );
		$this->assertStringContainsString( 'Detected image records: ' . $formatted . '.', $this->message() );
		$db->queries = array();
		$this->assertStringContainsString( 'For this migration size', $this->message( array( 'option_bytes' => '1048576' ) ) );
		$this->assertCount( 1, $db->queries, 'Existing byte advice reads only the cleanup hint, with no source-count queries.' );
		$this->assertStringContainsString( 'foogallery_migrate_storage_cleanup', $db->queries[0] );
	}
	public function test_invalid_threshold_filters_keep_defaults_and_valid_filters_adjust_advice_only(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = '99';
		foreach ( array( 0, -1, 1.5, '10', null, array( 1 ), '9999999999999999999999' ) as $invalid ) {
			foreach ( array( 'galleries', 'images' ) as $kind ) {
				$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_' . $kind] = function () use ( $invalid ) { return $invalid; };
			}
			$this->assertSame( '', $this->message() );
		}
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_galleries'] = function () { return 99; };
		$this->assertStringContainsString( 'before migrating', $this->message() );
		$db->post_galleries = '0'; $db->tables = array( 'wp_ngg_gallery', 'wp_ngg_pictures' ); $db->source_counts['ngg_pictures'] = '4';
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_images'] = function () { return 4; };
		$this->assertStringContainsString( 'Detected image records: 4.', $this->message() );
	}
	public function test_unknown_failed_and_malformed_counts_never_become_invented_workload(): void {
		$db = $GLOBALS['wpdb'];
		foreach ( array( null, false, '-1', 'unknown', '1.5', '<script>1000</script>', array( 1000 ) ) as $invalid ) {
			$db->post_galleries = $invalid;
			$this->assertSame( array( 'galleries' => 0, 'images' => null ), StorageSourceCounts::measure() );
			$this->assertSame( '', $this->message() );
		}
		$db->post_galleries = '100'; $db->failed_fragment = 'FROM `wp_posts`';
		$this->assertSame( array( 'galleries' => null, 'images' => null ), StorageSourceCounts::measure() );
		$this->assertSame( '', $this->message() );
		$this->assertFalse( $db->suppressed );
		$db->failed_fragment = ''; $db->throw = true; $db->suppressed = true;
		$this->assertSame( array( 'galleries' => null, 'images' => null ), StorageSourceCounts::measure() );
		$this->assertTrue( $db->suppressed, 'Restore prior SQL error-display state even after an exception.' );
	}
	public function test_authority_recovery_capability_and_multisite_gates_precede_source_queries(): void {
		foreach ( array( array( 'backend' => 'table' ), array( 'phase' => 'copying' ), array( 'calibrated' => false ), array( 'problem' => 'unhealthy' ) ) as $status ) {
			$this->assertSame( '', $this->message( $status ) );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->assertSame( array( 'galleries' => null, 'images' => null ), StorageSourceCounts::measure() );
		$_GET['page'] = 'foogallery-migrate'; ob_start(); StorageAdmin::recommendation_notice(); $this->assertSame( '', ob_get_clean() );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = true;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = true;
		$this->assertSame( '', $this->message() );
		$this->assertSame( array( 'galleries' => null, 'images' => null ), StorageSourceCounts::measure() );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}
	public function test_advice_renders_on_settings_and_migration_screens_with_a_settings_link(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = '100';
		$status = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render( $status ); $html = ob_get_clean();
		$this->assertStringContainsString( 'Detected gallery records: 100.', $html );
		$this->assertStringContainsString( 'Source counts are advisory estimates', $html );
		$_GET['page'] = 'foogallery-migrate'; ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertStringContainsString( 'before migrating', $html );
		$this->assertStringContainsString( 'tab=settings#migration-storage', $html );
		$db->queries = array(); $_GET['tab'] = 'settings';
		ob_start(); StorageAdmin::recommendation_notice(); $this->assertSame( '', ob_get_clean() );
		$this->assertSame( array(), $db->queries, 'No duplicate global recommendation query on Settings.' );
	}
	public function test_pending_cleanup_suppresses_both_source_and_saved_byte_advice_before_source_queries(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = '1000'; $db->pending = true;
		$this->assertSame( '', $this->message() );
		$this->assertSame( '', $this->message( array( 'option_bytes' => '1048576' ) ) );
		$this->assertCount( 2, $db->queries );
		foreach ( $db->queries as $sql ) { $this->assertStringContainsString( 'foogallery_migrate_storage_cleanup', $sql ); }
	}
	public function test_large_counts_saturate_without_overflow_and_unsafe_prefixes_issue_no_sql(): void {
		$db = $GLOBALS['wpdb']; $db->post_galleries = (string) PHP_INT_MAX; $db->aigpl_galleries = '100';
		$this->assertSame( PHP_INT_MAX, StorageSourceCounts::measure()['galleries'] );
		$db->queries = array(); $db->prefix = 'wp_`; DROP';
		$this->assertSame( array( 'galleries' => null, 'images' => null ), StorageSourceCounts::measure() );
		$this->assertSame( array(), $db->queries );
	}
}

/** Reject any accidental source hydration or mutation; scalar SQL only. */
class SourceCountsWpdb {
	public $prefix = 'wp_', $options = 'wp_options', $last_error = '', $post_galleries = '0', $aigpl_galleries = '0', $failed_fragment = '', $throw = false, $suppressed = false, $pending = false;
	public $queries = array(), $tables = array(), $source_counts = array();
	public function suppress_errors( $value ) { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, ...$values ) { if ( count( $values ) === 1 && is_array( $values[0] ) ) { $values = $values[0]; } foreach ( $values as $value ) { $sql = preg_replace( '/%[sd]/', "'" . str_replace( "'", "''", $value ) . "'", $sql, 1 ); } return $sql; }
	public function get_var( $sql ) {
		$this->queries[] = $sql; $this->last_error = '';
		if ( $this->throw ) { throw new \RuntimeException( 'Source unavailable.' ); }
		if ( $this->failed_fragment && false !== strpos( $sql, $this->failed_fragment ) ) { $this->last_error = 'Missing source column'; return null; }
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE ' ) ) {
			foreach ( $this->tables as $table ) { if ( false !== strpos( $sql, $this->esc_like( $table ) ) ) { return $table; } }
			return null;
		}
		if ( false !== strpos( $sql, 'OCTET_LENGTH(' ) ) { return '0'; }
		if ( false !== strpos( $sql, 'wp_options' ) ) { return $this->pending ? json_encode( array( 'version' => 1, 'blog_id' => 7, 'action' => 'clear', 'user_cursor' => 0, 'users_done' => true ) ) : null; }
		if ( 0 !== strpos( $sql, 'SELECT COUNT(' ) ) { throw new \LogicException( 'Unexpected/hydrating SQL: ' . $sql ); }
		if ( false !== strpos( $sql, 'FROM `wp_posts`' ) ) { return false !== strpos( $sql, 'aigpl_gallery' ) ? $this->aigpl_galleries : $this->post_galleries; }
		if ( false !== strpos( $sql, 'COUNT(DISTINCT g.id)' ) ) { return isset( $this->source_counts['wppa_albums'] ) ? $this->source_counts['wppa_albums'] : '0'; }
		foreach ( array( 'ngg_pictures', 'bwg_image', 'wppa_photos', 'ngg_gallery', 'bwg_gallery' ) as $table ) {
			if ( false !== strpos( $sql, 'FROM `wp_' . $table . '`' ) ) { return isset( $this->source_counts[$table] ) ? $this->source_counts[$table] : '0'; }
		}
		throw new \LogicException( 'Unexpected SQL: ' . $sql );
	}
}
