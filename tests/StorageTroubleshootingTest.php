<?php
namespace {
	if ( ! function_exists( 'foogallery_migrate_admin_url' ) ) {
		function foogallery_migrate_admin_url( $tab ) { return 'https://example.test/admin.php?tab=' . $tab; }
	}
}
namespace FooPlugins\FooGalleryMigrate\Tests {
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageDiscoverySummary;
use PHPUnit\Framework\TestCase;

class StorageTroubleshootingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		unset( $GLOBALS['foogallery_migrate_test_failed_options'], $GLOBALS['foogallery_migrate_test_failed_deletes'] );
		$GLOBALS['wpdb'] = new TroubleshootingMetadataWpdb();
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$GLOBALS['foogallery_migrate_engine_instance'] = new TroubleshootingEngineTrap();
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_is_multisite'], $GLOBALS['foogallery_migrate_engine_instance'], $_GET, $_POST );
	}
	private function status( $values = array() ) {
		return array_merge( array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'discardable' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true ), $values );
	}
	public function test_settings_has_no_technical_or_destructive_controls(): void {
		ob_start(); StorageAdmin::render( $this->status() ); $html = ob_get_clean();
		$this->assertStringContainsString( 'id="foogm-storage-change"', $html );
		$this->assertStringNotContainsString( 'tab=troubleshooting', $html );
		$this->assertStringNotContainsString( 'Troubleshooting, technical details and cleanup', $html );
		foreach ( array( 'id="foogm-storage-details"', 'id="foogm-storage-cleanup"', 'value="clear"', 'value="remove_all"', 'value="discard"', 'id="foogm-support-report"' ) as $control ) {
			$this->assertStringNotContainsString( $control, $html );
		}
	}
	public function test_diagnostics_are_bounded_escaped_and_exclude_private_problem_text_from_support_summary(): void {
		$problem = '<script>secret problem /private/server-path</script>';
		ob_start(); StorageAdmin::render_troubleshooting( $this->status( array( 'problem' => $problem ) ) ); $html = ob_get_clean();
		$this->assertStringContainsString( '&lt;script&gt;secret problem', $html );
		$this->assertStringNotContainsString( '<script>secret problem', $html );
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( 13, $xpath->query( '//section[@id="foogm-storage-details"]//tr' )->length );
		$report = $xpath->query( '//textarea[@id="foogm-support-report"]' )->item( 0 )->textContent;
		foreach ( array( 'PHP version:', 'WordPress version:', 'PHP memory limit:', 'Backend: option', 'Option bytes: 0' ) as $text ) { $this->assertStringContainsString( $text, $report ); }
		$this->assertStringNotContainsString( 'secret problem', $report );
		$this->assertStringNotContainsString( '/private/', $report );
		$this->assertStringNotContainsString( 'nonce', $report );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}
	public function test_technical_details_and_support_use_saved_counts_without_source_queries(): void {
		$summary = array( 'version' => 1, 'galleries' => 114, 'images' => 12345, 'images_complete' => true, 'refreshed_at' => time() - 3600 );
		$formatted = function_exists( 'number_format_i18n' ) ? number_format_i18n( 12345 ) : number_format( 12345 );
		StorageDiscoverySummary::publish( $summary );
		ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( '114', $xpath->query( '//section[@id="foogm-storage-details"]//tr[th="Discovered galleries"]/td' )->item( 0 )->textContent );
		$this->assertSame( $formatted, $xpath->query( '//section[@id="foogm-storage-details"]//tr[th="Image entries"]/td' )->item( 0 )->textContent );
		$this->assertStringNotContainsString( 'SELECT COUNT(', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertStringNotContainsString( 'SHOW TABLES LIKE', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$report = $xpath->query( '//textarea[@id="foogm-support-report"]' )->item( 0 )->textContent;
		$this->assertStringContainsString( 'Image entries: ' . $formatted, $report );
		$method = new \ReflectionMethod( StorageAdmin::class, 'recommendation_message' ); $method->setAccessible( true );
		$notice = $method->invoke( null, $this->status() );
		$this->assertStringContainsString( 'Discovered galleries: 114.', $notice );
		$this->assertStringContainsString( 'Image entries: ' . $formatted . '.', $notice );
		$this->assertStringNotContainsString( ' UTC:', $notice );
		$this->assertStringContainsString( 'Discovery counts saved: ' . gmdate( 'Y-m-d H:i', $summary['refreshed_at'] ) . ' UTC.', $report );
		$this->assertStringContainsString( 'Use Refresh Galleries to update them.', $html );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
		$summary['images_complete'] = false; StorageDiscoverySummary::publish( $summary );
		ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
		$this->assertStringContainsString( 'Known image entries (partial)', $html );
	}
	public function test_missing_or_invalid_summary_keeps_partial_scalar_count_fallback(): void {
		foreach ( array( false, array( 'version' => 99 ) ) as $invalid ) {
			$GLOBALS['foogallery_migrate_test_options'][StorageDiscoverySummary::OPTION] = $invalid;
			$GLOBALS['wpdb']->queries = array();
			ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
			$this->assertStringContainsString( 'Detected image records (partial estimate)', $html );
			$this->assertStringContainsString( 'SELECT COUNT(', implode( ' ', $GLOBALS['wpdb']->queries ) );
			$this->assertStringNotContainsString( 'Discovery counts saved:', $html );
			$this->assertSame( array(), $GLOBALS['wpdb']->writes );
		}
	}
	public function test_cleanup_controls_keep_exact_nonce_fields_one_confirmation_and_native_rows(): void {
		ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( 2, $xpath->query( '//section[@id="foogm-storage-cleanup"]//tr' )->length );
		$this->assertSame( 2, $xpath->query( '//form[@data-confirm]' )->length );
		foreach ( $xpath->query( '//form' ) as $form ) { $this->assertSame( 7, $xpath->query( './/input[@name]', $form )->length ); }
		$this->assertSame( 0, $xpath->query( '//input[@type="checkbox"]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@type="radio"]' )->length );
		$this->assertSame( 0, $xpath->query( '//details[@id="foogm-storage-cleanup"]' )->length );
	}
	public function test_recovery_is_visible_and_pending_cleanup_keeps_only_the_matching_action(): void {
		ob_start(); StorageAdmin::render_troubleshooting( $this->status( array( 'phase' => 'copying', 'target' => 'table', 'discardable' => true ) ) ); $html = ob_get_clean();
		$this->assertStringContainsString( 'Continue storage change', $html );
		$this->assertStringContainsString( 'name="confirmation" value="recover-storage"', $html );
		$this->assertStringContainsString( 'name="confirmation" value="discard-copy"', $html );
		$GLOBALS['wpdb']->pending = true;
		ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
		$this->assertStringContainsString( '<section id="foogm-storage-cleanup"', $html );
		$this->assertStringContainsString( 'Continue cleanup', $html );
		$this->assertStringNotContainsString( 'name="operation" value="remove_all"', $html );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}
	public function test_simplified_troubleshooting_layout_and_copy_follow_the_requested_order(): void {
		ob_start(); StorageAdmin::render_troubleshooting( $this->status() ); $html = ob_get_clean();
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( 'If you are having trouble with a migration, try the following:', $xpath->query( '/html/body/*[1][self::p]' )->item( 0 )->textContent );
		$this->assertSame( 'ul', $xpath->query( '/html/body/*[2]' )->item( 0 )->nodeName );
		$this->assertSame( array(
			'If a migration stops, pause any other migrations and refresh the page before continuing.',
			'If you see a timeout or memory error, reduce Images Per Turn in Settings and keep Page Size above zero.',
			'If you are migrating a lot of galleries or images, try changing your migration storage to use tables',
		), array_map( function ( $node ) { return $node->textContent; }, iterator_to_array( $xpath->query( '/html/body/ul/li' ) ) ) );
		$this->assertStringNotContainsString( 'Database tables can help with larger migrations, but very large galleries may still need more memory.', $html );
		$this->assertStringNotContainsString( 'Before installing an older version of this plugin', $html );
		$this->assertSame( array( 'Reset & cleanup', 'Technical details' ), array_map( function ( $node ) { return $node->textContent; }, iterator_to_array( $xpath->query( '//h2|//h3' ) ) ) );
		$this->assertStringNotContainsString( 'Storage is stable. No storage change is in progress.', $html );
		$this->assertStringNotContainsString( 'Check storage, continue an interrupted change, or reset migration data.', $html );
		$this->assertStringNotContainsString( 'Open migration settings', $html );
		$this->assertStringContainsString( 'Galleries and media are kept. Migrating again after a reset or removal may create duplicates.', $html );
		$this->assertLessThan( strpos( $html, 'id="foogm-storage-details"' ), strpos( $html, 'id="foogm-storage-cleanup"' ) );
		$this->assertLessThan( strpos( $html, 'Information for support' ), strpos( $html, 'id="foogm-storage-details"' ) );
		$this->assertSame( 1, $xpath->query( '//details[summary="Information for support"][not(@open)]' )->length );
	}
	public function test_permission_denial_precedes_diagnostics_sql(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		try { StorageAdmin::render_troubleshooting(); $this->fail( 'Must refuse unauthorized access.' ); }
		catch ( \FooGalleryMigrateTestDie $error ) { $this->assertSame( array(), $GLOBALS['wpdb']->queries ); }
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_troubleshooting_route_stays_reachable_during_cleanup_without_engine_access(): void {
		$GLOBALS['wpdb']->pending = true;
		$_GET = array( 'tab' => 'troubleshooting' ); $_POST = array();
		ob_start(); include dirname( __DIR__ ) . '/includes/views/view-migrate.php'; $html = ob_get_clean();
		$this->assertStringContainsString( 'id="foogallery_migrate_troubleshooting"', $html );
		$this->assertStringContainsString( 'id="foogm-storage-details"', $html );
		$this->assertStringContainsString( 'Continue cleanup', $html );
		$this->assertStringContainsString( 'tab=settings', $html );
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_troubleshooting_route_handles_broken_authority_without_engine_access(): void {
		$GLOBALS['wpdb']->control = 'invalid control';
		$_GET = array( 'tab' => 'TroubleShooting' ); $_POST = array();
		ob_start(); include dirname( __DIR__ ) . '/includes/views/view-migrate.php'; $html = ob_get_clean();
		$this->assertStringContainsString( 'id="foogallery_migrate_troubleshooting"', $html );
		$this->assertStringContainsString( 'Storage needs attention', $html );
		$this->assertStringContainsString( 'Remove all migration data', $html );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}
}
class TroubleshootingEngineTrap {
	public function __call( $name, $args ) { throw new \RuntimeException( 'Troubleshooting must not access the engine: ' . $name ); }
}
class TroubleshootingMetadataWpdb {
	public $prefix = 'wp_', $base_prefix = 'wp_', $options = 'wp_options', $last_error = '', $pending = false, $control = null;
	public $queries = array(), $writes = array();
	public function prepare( $sql, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $value ) { $sql = preg_replace( '/%[sd]/', "'" . addslashes( (string) $value ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function get_blog_prefix( $blog ) { return $this->prefix; }
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'OCTET_LENGTH' ) ) { return '0'; }
		if ( $this->pending && false !== strpos( $sql, "option_name='foogallery_migrate_storage_cleanup'" ) ) { return json_encode( array( 'version' => 1, 'blog_id' => 7, 'action' => 'clear', 'user_cursor' => 0, 'users_done' => true ) ); }
		if ( null !== $this->control && false !== strpos( $sql, "option_name = 'foogallery_migrate_storage_control'" ) ) { return 'v:' . $this->control; }
		return null;
	}
	public function query( $sql ) { $this->writes[] = $sql; throw new \RuntimeException( 'No diagnostic writes admitted.' ); }
}
}
