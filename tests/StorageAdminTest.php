<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use PHPUnit\Framework\TestCase;

class StorageAdminTest extends TestCase {
	protected function setUp(): void { $GLOBALS['foogallery_migrate_test_blog_id'] = 7; $GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true ); $GLOBALS['foogallery_migrate_test_filters'] = array(); }
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_filters'], $GLOBALS['foogallery_migrate_test_is_multisite'], $GLOBALS['foogallery_migrate_test_option_reads'], $GLOBALS['wpdb'], $_GET ); }
	public function test_site_scoped_controls_use_distinct_existing_receiver_purposes(): void {
		$base = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'calibrated' => true, 'schema' => 'verified' );
		$a = StorageAdmin::actions( $base );
		$this->assertSame( array( 'clear', 'switch', 'remove_all' ), array_column( $a, 'operation' ) );
		$this->assertSame( 'clear', $a[0]['purpose'] ); $this->assertSame( 'clear-current-state', $a[0]['confirmation'] );
		$this->assertSame( 'option_import', $a[1]['purpose'] ); $this->assertSame( 'switch-to-table', $a[1]['confirmation'] );
		$base['retained'] = true; $a = StorageAdmin::actions( $base );
		$this->assertSame( array( 'clear', 'replace', 'remove_all' ), array_column( $a, 'operation' ) );
		$this->assertSame( 'option_reimport', $a[1]['purpose'] ); $this->assertSame( 'replace-retained-with-table', $a[1]['confirmation'] );
		$base['backend'] = 'table'; $base['target'] = 'table';
		$a = StorageAdmin::actions( $base ); $this->assertSame( 'table_export', $a[1]['purpose'] ); $this->assertSame( 'switch-to-option', $a[1]['confirmation'] );
		$base['phase'] = 'flipped'; $base['target'] = 'option';
		$a = StorageAdmin::actions( $base ); $this->assertSame( 'recover', $a[0]['operation'] ); $this->assertSame( 'recover', $a[0]['purpose'] ); $this->assertSame( 'recover-storage', $a[0]['confirmation'] );
		$this->assertSame( 'option', $a[0]['target'] );
		$base['backend'] = 'unavailable'; $this->assertSame( array( 'remove_all' ), array_column( StorageAdmin::actions( $base ), 'operation' ) );
	}
	public function test_read_only_view_escapes_status_and_emits_exact_site_scoped_post_controls(): void {
		ob_start(); StorageAdmin::render_troubleshooting( array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '123', 'records' => null, 'schema' => 'not provisioned', 'problem' => '<script>bad</script>', 'calibrated' => false ) ); $html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>bad</script>', $html ); $this->assertStringContainsString( '&lt;script&gt;bad&lt;/script&gt;', $html );
		foreach ( array( 'name="action" value="foogallery_migrate_storage"', 'name="operation" value="clear"', 'name="blog_id" value="7"', 'name="scope" value="site"', 'name="confirmation" value="clear-current-state"', 'name="nonce"', 'data-confirm=', 'admin-post.php', "form.getAttribute('action')", 'Storage needs attention', 'Galleries and media are kept' ) as $text ) { $this->assertStringContainsString( $text, $html ); }
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringNotContainsString( 'name="operation" value="switch"', $html );
	}
	public function test_unauthorized_render_stops_before_status_sql(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '4096' );
		try { StorageAdmin::render(); $this->fail( 'Unauthorized render must stop.' ); }
		catch ( \FooGalleryMigrateTestDie $error ) { $this->assertSame( array(), $GLOBALS['wpdb']->queries ); }
	}
	public function test_single_table_action_includes_purpose_scoped_preparation_without_a_second_button(): void {
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		foreach ( array( 'name="operation" value="switch"', 'data-preflight-nonce="nonce-foogallery_migrate_storage_option_import_7"', 'data-provision-nonce="nonce-foogallery_migrate_storage_provision_schema_7"', 'data-recover-nonce="nonce-foogallery_migrate_storage_recover_7"', 'Changing storage does not change your galleries or media.', 'form="foogm-storage-switch"', '>Change storage</button>' ) as $text ) { $this->assertStringContainsString( $text, $html ); }
		$this->assertStringNotContainsString( 'name="operation" value="provision"', $html );
		$this->assertSame( 1, substr_count( $html, 'name="confirmation" value="switch-to-table"' ) );
		$s['schema'] = 'verified'; ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$this->assertStringNotContainsString( 'name="operation" value="provision"', $html );
		$this->assertStringNotContainsString( 'data-provision-nonce=', $html );
		$this->assertStringContainsString( 'name="operation" value="switch"', $html );
	}
	public function test_threshold_filter_accepts_only_positive_integers_and_byte_comparison_includes_boundary(): void {
		$this->assertSame( 1048576, StorageAdmin::advice_threshold() );
		foreach ( array( 0, -1, 1.5, '1048576', '9223372036854775808', array( 1048576 ), null ) as $invalid ) {
			$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_bytes'] = function () use ( $invalid ) { return $invalid; };
			$this->assertSame( 1048576, StorageAdmin::advice_threshold() );
		}
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_bytes'] = function () { return 2048; };
		$this->assertSame( 2048, StorageAdmin::advice_threshold() );
		$method = new \ReflectionMethod( StorageAdmin::class, 'above_advice_threshold' ); $method->setAccessible( true );
		$this->assertFalse( $method->invoke( null, '2047' ) );
		$this->assertTrue( $method->invoke( null, '2048' ) );
		$this->assertTrue( $method->invoke( null, '2049' ) );
		$this->assertFalse( $method->invoke( null, 'not-a-size' ) );
		$eligible = new \ReflectionMethod( StorageAdmin::class, 'recommendation_eligible' ); $eligible->setAccessible( true );
		$this->assertTrue( $eligible->invoke( null, array( 'backend' => 'option', 'phase' => 'stable', 'option_bytes' => '2048', 'calibrated' => true ) ) );
		$this->assertFalse( $eligible->invoke( null, array( 'backend' => 'table', 'phase' => 'stable', 'option_bytes' => '4096', 'calibrated' => true ) ) );
		$this->assertFalse( $eligible->invoke( null, array( 'backend' => 'option', 'phase' => 'copying', 'option_bytes' => '4096', 'calibrated' => true ) ) );
		$this->assertFalse( $eligible->invoke( null, array( 'backend' => 'option', 'phase' => 'stable', 'option_bytes' => '2047', 'calibrated' => true ) ) );
		$this->assertFalse( $eligible->invoke( null, array( 'backend' => 'option', 'phase' => 'stable', 'option_bytes' => '4096', 'calibrated' => false ) ) );
	}
	public function test_recommendation_is_page_scoped_measured_and_read_only(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '1048576' );
		$GLOBALS['foogallery_migrate_test_option_reads'] = array();
		$_GET['page'] = 'plugins'; ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertSame( '', $html ); $this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$_GET['page'] = 'foogallery-migrate-storage'; ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertSame( '', $html ); $this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$_GET['page'] = 'foogallery-migrate'; ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertStringContainsString( '1 MB', $html );
		$this->assertStringContainsString( 'we recommend database tables', $html );
		$this->assertStringContainsString( 'edit.php?post_type=foogallery&amp;page=foogallery-migrate&amp;tab=settings#migration-storage', $html );
		$this->assertStringContainsString( 'OCTET_LENGTH(option_value)', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertStringNotContainsString( 'SHOW TABLE STATUS', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertStringNotContainsString( 'SHOW COLUMNS', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertStringNotContainsString( 'COUNT(*)', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_reads'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
		$this->assertSame( array(), $GLOBALS['wpdb']->ddl );
		$GLOBALS['wpdb']->queries = array(); $_GET['tab'] = 'settings';
		ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertSame( '', $html ); $this->assertSame( array(), $GLOBALS['wpdb']->queries, 'Settings renders its own recommendation without a duplicate global query.' );
	}
	public function test_main_advisor_checks_capability_before_any_sql(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '4096' ); $_GET['page'] = 'foogallery-migrate';
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		ob_start(); StorageAdmin::recommendation_notice(); $html = ob_get_clean();
		$this->assertSame( '', $html ); $this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}
	public function test_pending_cleanup_scope_keeps_only_valid_resume_and_remove_all_controls(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '0' );
		$GLOBALS['wpdb']->pending_scope = 'clear';
		$this->assertSame( array( 'clear' ), array_column( StorageAdmin::actions( array( 'backend' => 'unavailable', 'phase' => 'unavailable' ) ), 'operation' ) );
		$GLOBALS['wpdb']->pending_scope = 'remove_all';
		$this->assertSame( array( 'remove_all' ), array_column( StorageAdmin::actions( array( 'backend' => 'option', 'phase' => 'stable', 'calibrated' => true ) ), 'operation' ) );
	}
	public function test_multisite_and_unsupported_runtime_do_not_show_table_actions(): void {
		$GLOBALS['foogallery_migrate_test_is_multisite'] = true;
		$this->assertSame( array(), StorageAdmin::actions( array( 'backend' => 'option', 'phase' => 'stable', 'calibrated' => true ) ) );
		ob_start(); StorageAdmin::render( array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '100', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true ) ); $html = ob_get_clean();
		$this->assertStringContainsString( 'Table storage is not available on multisite', $html );
		$this->assertStringContainsString( 'Continue using WordPress options', $html );
		$this->assertStringNotContainsString( 'read-only decode-budget preflight', $html );
		$this->assertStringNotContainsString( 'name="operation" value="switch"', $html );
	}
	public function test_storage_screen_recommendation_uses_provided_status_and_explains_scaling_limits(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '2048' );
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_advice_threshold_bytes'] = function () { return 2048; };
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '2048', 'records' => null, 'schema' => 'verified', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$this->assertStringContainsString( 'we recommend database tables', $html );
		$this->assertStringContainsString( '2 KB', $html );
		$this->assertStringContainsString( 'tab=troubleshooting', $html );
		$this->assertStringNotContainsString( 'OCTET_LENGTH(option_value)', implode( ' ', $GLOBALS['wpdb']->queries ) );
		$this->assertStringNotContainsString( 'COUNT(*)', implode( ' ', $GLOBALS['wpdb']->queries ) );
		ob_start(); StorageAdmin::render_troubleshooting( $s ); $troubleshooting = ob_get_clean();
		$this->assertStringContainsString( 'very large individual galleries and full source discovery', $troubleshooting );
		$s['calibrated'] = false;
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$this->assertStringNotContainsString( 'we recommend database tables', $html );
		$this->assertStringContainsString( 'Storage switching is not available on this site', $html );
		$this->assertStringNotContainsString( 'id="foogm-storage-change"', $html );
	}
	public function test_render_does_not_offer_other_cleanup_or_provision_controls_during_pending_clear(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '0' );
		$GLOBALS['wpdb']->pending_scope = 'clear';
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render_troubleshooting( $s ); $html = ob_get_clean();
		$this->assertStringContainsString( 'name="operation" value="clear"', $html );
		$this->assertStringNotContainsString( 'name="operation" value="remove_all"', $html );
		$this->assertStringNotContainsString( 'name="operation" value="provision"', $html );
		$this->assertStringNotContainsString( 'data-provision-nonce=', $html );
		$GLOBALS['wpdb']->pending_scope = 'remove_all';
		ob_start(); StorageAdmin::render_troubleshooting( $s ); $html = ob_get_clean();
		$this->assertStringContainsString( 'name="operation" value="remove_all"', $html );
		$this->assertStringNotContainsString( 'name="operation" value="clear"', $html );
		$this->assertStringNotContainsString( 'name="operation" value="provision"', $html );
		$this->assertStringNotContainsString( 'data-provision-nonce=', $html );
	}
	public function test_settings_keep_recovery_and_cleanup_reachable_without_engine_access(): void {
		$GLOBALS['wpdb'] = new StorageAdminAdviceWpdb( '0' );
		foreach ( array(
			array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'problem' => 'bad state' ),
			array( 'backend' => 'option', 'phase' => 'copying', 'problem' => '' ),
			array( 'backend' => 'table', 'phase' => 'stable', 'problem' => 'schema problem' )
		) as $status ) {
			$this->assertFalse( StorageAdmin::settings_available( $status ) );
			$foogallery_migrate_storage_status = array_merge( array( 'target' => 'table', 'retained' => false, 'discardable' => true, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'calibrated' => true ), $status );
			ob_start(); include dirname( __DIR__ ) . '/includes/views/view-migrate-tab-settings.php'; $html = ob_get_clean();
			$this->assertStringContainsString( 'id="migration-storage"', $html );
			$this->assertStringContainsString( 'preferences are unavailable', $html );
			$this->assertStringNotContainsString( 'foogallery_migrate_save_settings', $html );
			$this->assertStringContainsString( 'tab=troubleshooting', $html );
			$this->assertStringNotContainsString( 'foogm-storage-cleanup', $html );
			ob_start(); include dirname( __DIR__ ) . '/includes/views/view-migrate-tab-troubleshooting.php'; $troubleshooting = ob_get_clean();
			$this->assertStringContainsString( 'copying' === $status['phase'] ? 'Continue storage change' : 'Remove all migration data', $troubleshooting );
		}
		$healthy = array( 'backend' => 'option', 'phase' => 'stable', 'problem' => '' );
		$this->assertTrue( StorageAdmin::settings_available( $healthy ) );
		$GLOBALS['wpdb']->pending_scope = 'remove_all';
		$this->assertFalse( StorageAdmin::settings_available( $healthy ) );
	}
	public function test_compact_picker_has_no_acknowledgements_and_exact_hidden_receiver_fields(): void {
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '264453', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( 2, $xpath->query( '//input[@type="radio"]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@type="checkbox"]' )->length );
		$this->assertSame( 0, $xpath->query( '//form//input[@type="radio"]' )->length, 'The picker must not add fields to strict receiver requests.' );
		$this->assertSame( 1, $xpath->query( '//button[@id="foogm-storage-change"][@disabled]' )->length );
		$this->assertSame( 7, $xpath->query( '//form[@id="foogm-storage-switch"]//input[@name]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[@id="foogm-storage-switch"][@data-confirm]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[@data-confirm]' )->length );
		$this->assertSame( 0, $xpath->query( '//details[@open]' )->length );
		$this->assertStringContainsString( '258 KB', $html );
		$this->assertStringNotContainsString( 'I have a backup', $html );
		$this->assertStringNotContainsString( 'explicitly confirm', $html );
		$s['backend'] = 'table'; $s['records'] = '1234'; $s['option_bytes'] = '0';
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$this->assertStringContainsString( 'Migration records: 1234', strip_tags( $html ) );
		$this->assertStringNotContainsString( 'Migration data: 0 B', strip_tags( $html ) );
	}
	public function test_storage_controls_use_native_settings_label_and_control_columns(): void {
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '264453', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => true );
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$row = '//section[@id="migration-storage"]/table[@class="form-table"][@role="presentation"]/tbody/tr';
		$this->assertSame( 1, $xpath->query( $row )->length );
		$this->assertSame( 'Migration Storage', $xpath->query( $row . '/th[@scope="row"][@id="migration-storage-heading"]' )->item( 0 )->textContent );
		$this->assertSame( 2, $xpath->query( $row . '/td//input[@type="radio"]' )->length );
		$this->assertSame( 1, $xpath->query( $row . '/td//button[@id="foogm-storage-change"]' )->length );
		$this->assertSame( 0, $xpath->query( $row . '/td/details' )->length );
		$this->assertSame( 0, $xpath->query( '//form//form' )->length );
		$this->assertSame( 0, $xpath->query( '//section[@id="migration-storage"]//h2' )->length );
	}
}

class StorageAdminAdviceWpdb {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $options = 'wp_options'; public $last_error = ''; public $queries = array(); public $writes = array(); public $ddl = array(); public $pending_scope = null;
	private $bytes;
	public function __construct( $bytes ) { $this->bytes = $bytes; }
	public function prepare( $query, ...$values ) {
		if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
		foreach ( $values as $value ) { $query = preg_replace( '/%[ds]/', is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'", $query, 1 ); }
		return $query;
	}
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function get_var( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'OCTET_LENGTH(option_value)' ) ) { return $this->bytes; }
		if ( false !== strpos( $query, \FooPlugins\FooGalleryMigrate\StorageCleanup::MARKER ) && null !== $this->pending_scope ) {
			return json_encode( array( 'version' => 1, 'blog_id' => 7, 'action' => $this->pending_scope, 'user_cursor' => 0, 'users_done' => true ) );
		}
		return null;
	}
	public function get_results( $query, $format = null ) { $this->queries[] = $query; return array(); }
	public function get_blog_prefix( $blog_id ) { return 'wp_'; }
	public function query( $query ) { $this->writes[] = $query; return 1; }
	public function dbDelta( $query ) { $this->ddl[] = $query; return array(); }
}
