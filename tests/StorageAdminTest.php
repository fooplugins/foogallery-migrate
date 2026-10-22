<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use PHPUnit\Framework\TestCase;

class StorageAdminTest extends TestCase {
	protected function setUp(): void { $GLOBALS['foogallery_migrate_test_blog_id'] = 7; $GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true ); }
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'] ); }
	public function test_site_scoped_controls_use_distinct_existing_receiver_purposes(): void {
		$base = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false );
		$a = StorageAdmin::actions( $base );
		$this->assertSame( array( 'switch', 'remove_all' ), array_column( $a, 'operation' ) );
		$this->assertSame( 'option_import', $a[0]['purpose'] ); $this->assertSame( 'switch-to-table', $a[0]['confirmation'] );
		$base['retained'] = true; $a = StorageAdmin::actions( $base );
		$this->assertSame( array( 'replace', 'remove_all' ), array_column( $a, 'operation' ) );
		$this->assertSame( 'option_reimport', $a[0]['purpose'] ); $this->assertSame( 'replace-retained-with-table', $a[0]['confirmation'] );
		$base['backend'] = 'table'; $base['target'] = 'table';
		$a = StorageAdmin::actions( $base ); $this->assertSame( 'table_export', $a[0]['purpose'] ); $this->assertSame( 'switch-to-option', $a[0]['confirmation'] );
		$base['phase'] = 'flipped'; $base['target'] = 'option';
		$a = StorageAdmin::actions( $base ); $this->assertSame( 'recover', $a[0]['operation'] ); $this->assertSame( 'recover', $a[0]['purpose'] ); $this->assertSame( 'recover-storage', $a[0]['confirmation'] );
		$this->assertSame( 'option', $a[0]['target'] );
		$base['backend'] = 'unavailable'; $this->assertSame( array( 'remove_all' ), array_column( StorageAdmin::actions( $base ), 'operation' ) );
	}
	public function test_read_only_view_escapes_status_and_emits_exact_site_scoped_post_controls(): void {
		ob_start(); StorageAdmin::render( array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '123', 'records' => null, 'schema' => 'not provisioned', 'problem' => '<script>bad</script>', 'calibrated' => false ) ); $html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>bad</script>', $html ); $this->assertStringContainsString( '&lt;script&gt;bad&lt;/script&gt;', $html );
		foreach ( array( 'name="action" value="foogallery_migrate_storage"', 'name="operation" value="switch"', 'name="blog_id" value="7"', 'name="scope" value="site"', 'name="confirmation" value="switch-to-table"', 'name="nonce"', 'type="checkbox" required', 'admin-post.php', "form.getAttribute('action')", 'Not approved for this runtime' ) as $text ) { $this->assertStringContainsString( $text, $html ); }
	}
	public function test_unauthorized_render_stops_before_status_sql(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->expectException( \FooGalleryMigrateTestDie::class ); StorageAdmin::render();
	}
	public function test_schema_only_form_is_separate_from_conversion_and_disappears_when_ready(): void {
		$s = array( 'backend' => 'option', 'phase' => 'stable', 'target' => 'option', 'retained' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => '', 'calibrated' => false );
		ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		foreach ( array( 'name="operation" value="provision"', 'name="scope" value="installation"', 'name="confirmation" value="provision-shared-schema"', 'no site will switch backends', 'name="operation" value="switch"' ) as $text ) { $this->assertStringContainsString( $text, $html ); }
		$s['schema'] = 'verified'; ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
		$this->assertStringNotContainsString( 'name="operation" value="provision"', $html );
	}
}
