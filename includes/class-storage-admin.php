<?php
/** Site-local operator controls over the existing bounded public receiver. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageAdmin {
	public static function register() {
		add_options_page( __( 'FooGallery Migration Storage', 'foogallery-migrate' ), __( 'Migration Storage', 'foogallery-migrate' ), 'manage_options', 'foogallery-migrate-storage', array( __CLASS__, 'render' ) );
	}

	/** Informational metadata only: never hydrate the legacy state or run DDL. */
	public static function status() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Storage access denied.', 'foogallery-migrate' ) ); }
		global $wpdb;
		$blog = get_current_blog_id();
		$s = array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'target' => 'option', 'retained' => false, 'discardable' => false, 'option_bytes' => null, 'records' => null, 'schema' => 'not provisioned', 'problem' => '', 'calibrated' => false );
		try {
			$control = new StorageControl( $wpdb, $blog );
			$raw = $control->read( StorageAuthority::CONTROL_OPTION );
			$r = null === $raw ? null : StorageControl::decode( $raw, $blog );
			$runtime = StorageAuthority::runtime_record();
			if ( is_wp_error( $runtime ) && ( null === $r || 'stable' === $r['phase'] ) ) { throw new \RuntimeException( $runtime->get_error_message() ); }
			$s['backend'] = null === $r ? 'option' : $r['backend'];
			$s['phase'] = null === $r ? 'stable' : $r['phase']; $s['target'] = null === $r ? 'option' : $r['to'];
			$s['discardable'] = null !== $r && 4 === $r['version'] && 'copying' === $r['phase'] && 'option' === $r['backend'] && 'table' === $r['to'];
			$s['retained'] = null !== $r && in_array( $r['version'], array( 2, 4 ), true ) && 'stable' === $r['phase'] && 'table' === $r['from'] && 'option' === $r['to'];
			$bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT OCTET_LENGTH(option_value) FROM `' . $wpdb->prefix . 'options` WHERE option_name=%s LIMIT 1', FOOGALLERY_MIGRATE_OPTION_DATA ) );
			if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Storage size is unavailable.', 'foogallery-migrate' ) ); }
			$s['option_bytes'] = null === $bytes ? '0' : $bytes;
			$schema = new LegacyStorageSchema( $wpdb );
			try {
				$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $blog;
				$metadata = $wpdb->get_blog_prefix( $main ) . 'options';
				// PHP5's native SQL object result loses numeric property names when
				// wpdb projects them through get_object_vars(). Keep the exact byte
				// ownership predicate, but give its literal result a named column.
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $metadata ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS owned FROM `' . $metadata . '` WHERE option_name=%s AND BINARY option_value=BINARY %s', LegacyStorageSchema::VERSION_OPTION, (string) LegacyStorageSchema::VERSION ) ) || $wpdb->last_error ) { throw new \RuntimeException( 'Shared schema ownership is not verified.' ); }
				$schema->verify(); $s['schema'] = 'verified';
				$tables = array_keys( $schema->definitions() ); $table = $tables[1];
				$s['records'] = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', $blog ) );
				if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Record count is unavailable.', 'foogallery-migrate' ) ); }
			} catch ( \RuntimeException $error ) {
				$s['schema'] = 'not ready';
				if ( null !== $r ) { $s['problem'] = __( 'Shared tables need attention. No automatic repair or cleanup has been attempted.', 'foogallery-migrate' ); }
			}
			$s['calibrated'] = ! is_wp_error( StorageCapacityPolicy::resolve() );
		} catch ( \Exception $error ) { $s['backend'] = 'unavailable'; $s['problem'] = $error->getMessage(); }
		catch ( \Throwable $error ) { $s['backend'] = 'unavailable'; $s['problem'] = __( 'Storage health could not be read. Current authority has not been changed.', 'foogallery-migrate' ); }
		return $s;
	}

	/** Display choices never authorize a mutation; the receiver revalidates all. */
	public static function actions( $s ) {
		if ( is_multisite() ) { return array(); }
		if ( ! in_array( $s['backend'], array( 'option', 'table' ), true ) ) { return array(); }
		if ( 'stable' !== $s['phase'] ) {
			$a = array( array( 'operation' => 'recover', 'target' => $s['target'], 'confirmation' => 'recover-storage', 'purpose' => 'recover', 'label' => __( 'Recover recorded switch', 'foogallery-migrate' ) ) );
			if ( ! empty( $s['discardable'] ) ) { $a[] = array( 'operation' => 'discard', 'target' => 'table', 'confirmation' => 'discard-copy', 'purpose' => 'discard', 'label' => __( 'Discard this incomplete table copy (keep current options)', 'foogallery-migrate' ) ); }
			return $a;
		}
		if ( 'table' === $s['backend'] ) { return array( array( 'operation' => 'switch', 'target' => 'option', 'confirmation' => 'switch-to-option', 'purpose' => 'table_export', 'label' => __( 'Switch this site to options', 'foogallery-migrate' ) ) ); }
		if ( $s['retained'] ) { return array( array( 'operation' => 'replace', 'target' => 'table', 'confirmation' => 'replace-retained-with-table', 'purpose' => 'option_reimport', 'label' => __( 'Replace retained table copy and switch this site to tables', 'foogallery-migrate' ) ) ); }
		return array( array( 'operation' => 'switch', 'target' => 'table', 'confirmation' => 'switch-to-table', 'purpose' => 'option_import', 'label' => __( 'Switch this site to tables', 'foogallery-migrate' ) ) );
	}

	public static function render( $status = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Storage access denied.', 'foogallery-migrate' ) ); }
		$s = is_array( $status ) ? $status : self::status();
		$blog = get_current_blog_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'FooGallery Migration Storage', 'foogallery-migrate' ) . '</h1>';
		echo '<p>' . esc_html__( 'Backend switches affect only this site. Schema provisioning creates shared installation tables but does not switch any site. Neither operation changes galleries or attachments. Back up first. Opening this page never provisions or switches storage.', 'foogallery-migrate' ) . '</p><dl>';
		foreach ( array( __( 'Site ID', 'foogallery-migrate' ) => $blog, __( 'Backend', 'foogallery-migrate' ) => $s['backend'], __( 'Switch phase', 'foogallery-migrate' ) => $s['phase'], __( 'Shared schema', 'foogallery-migrate' ) => $s['schema'], __( 'Option bytes', 'foogallery-migrate' ) => $s['option_bytes'], __( 'Site table records (including retained copies)', 'foogallery-migrate' ) => $s['records'] ) as $label => $value ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( null === $value ? __( 'Unavailable', 'foogallery-migrate' ) : $value ) . '</dd>';
		}
		echo '</dl><p>' . esc_html( $s['calibrated'] ? __( 'Release capacity policy available. Each operation still checks size, memory, ownership and the deadline.', 'foogallery-migrate' ) : __( 'Not approved for this runtime by the shipped release capacity policy. Switching and recovery refuse safely. A customer certificate or manually configured profile cannot enable this release. No fallback to unlimited memory or automatic switching.', 'foogallery-migrate' ) ) . '</p>';
		if ( '' !== $s['problem'] ) { echo '<p role="alert">' . esc_html( $s['problem'] ) . '</p>'; }
		echo '<p>' . esc_html__( 'Table migration storage is supported on single-site installations only. Multisite continues to use options. Recovery follows only the recorded authoritative phase. Foreign ownership, corrupt storage or insufficient capacity must be resolved before retrying. Never clear an owner because of its age, a missing PID or a closed browser; assisted recovery requires independently established whole-worker and output quiescence.', 'foogallery-migrate' ) . '</p>';
		if ( $s['retained'] ) { echo '<p>' . esc_html__( 'A previous table copy is retained after reversing to options. Re-import explicitly replaces that inactive copy; it never merges both backends.', 'foogallery-migrate' ) . '</p>'; }
		$actions = self::actions( $s );
		if ( ! is_multisite() && 'option' === $s['backend'] && 'stable' === $s['phase'] && 'verified' !== $s['schema'] ) {
			array_unshift( $actions, array( 'operation' => 'provision', 'scope' => 'installation', 'target' => 'table', 'confirmation' => 'provision-shared-schema', 'purpose' => 'provision_schema', 'label' => __( 'Provision shared schema only (keep options)', 'foogallery-migrate' ) ) );
		}
		foreach ( $actions as $a ) {
			echo '<form class="foogm-storage-form" method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '">';
			foreach ( array( 'action' => 'foogallery_migrate_storage', 'operation' => $a['operation'], 'blog_id' => $blog, 'scope' => isset( $a['scope'] ) ? $a['scope'] : 'site', 'target' => $a['target'], 'confirmation' => $a['confirmation'] ) as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
			wp_nonce_field( 'foogallery_migrate_storage_' . $a['purpose'] . '_' . $blog, 'nonce', false );
			$confirmation = 'provision' === $a['operation'] ? __( 'I have a backup and explicitly confirm shared installation schema provisioning only; no site will switch backends.', 'foogallery-migrate' ) : __( 'I have a backup and explicitly confirm this site-local storage operation.', 'foogallery-migrate' );
			echo '<p><label><input type="checkbox" required> ' . esc_html( $confirmation ) . '</label></p><p><button type="submit" class="button button-secondary">' . esc_html( $a['label'] ) . '</button></p></form>';
		}
		echo '<p id="foogm-storage-result" role="status" aria-live="polite"></p></div>';
		?>
		<script>
		(function () {
			var forms = document.querySelectorAll('.foogm-storage-form'), result = document.getElementById('foogm-storage-result');
			for (var i = 0; i < forms.length; i++) { forms[i].addEventListener('submit', function (event) {
				event.preventDefault(); var form = this, button = form.querySelector('button'), xhr = new XMLHttpRequest();
				button.disabled = true; result.textContent = '';
				xhr.open('POST', form.getAttribute('action')); xhr.timeout = 30000;
				xhr.onload = function () { button.disabled = false; try { var data = JSON.parse(xhr.responseText); result.textContent = data.data.message; if (data.success) { window.location.reload(); } } catch (error) { result.textContent = <?php echo wp_json_encode( __( 'The response could not be read. Reload to inspect the recorded phase before retrying; no automatic retry was sent.', 'foogallery-migrate' ) ); ?>; } };
				xhr.onerror = xhr.ontimeout = function () { button.disabled = false; result.textContent = <?php echo wp_json_encode( __( 'The connection ended. Reload to inspect the recorded phase before retrying; the operation may have persisted.', 'foogallery-migrate' ) ); ?>; };
				xhr.send(new FormData(form));
			}); }
		}());
		</script>
		<?php
	}
}
