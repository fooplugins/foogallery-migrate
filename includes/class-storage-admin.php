<?php
/** Site-local operator controls over the existing bounded public receiver. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageAdmin {
	const ADVICE_THRESHOLD_BYTES = 1048576;

	public static function register() {
		add_options_page( __( 'FooGallery Migration Storage', 'foogallery-migrate' ), __( 'Migration Storage', 'foogallery-migrate' ), 'manage_options', 'foogallery-migrate-storage', array( __CLASS__, 'render' ) );
		add_action( 'admin_notices', array( __CLASS__, 'recommendation_notice' ) );
	}

	/** A suggestion only; this value never changes conversion admission. */
	public static function advice_threshold() {
		$threshold = apply_filters( 'foogallery_migrate_storage_advice_threshold_bytes', self::ADVICE_THRESHOLD_BYTES );
		return is_int( $threshold ) && $threshold > 0 ? $threshold : self::ADVICE_THRESHOLD_BYTES;
	}

	/** Keep the size query and its output on the migration/storage screens only. */
	public static function recommendation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ||
			'foogallery-migrate' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { return; }
		if ( is_multisite() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Table storage recommendations and operations are unavailable on multisite. Existing option-mode flows remain supported, and this screen does not change storage authority.', 'foogallery-migrate' ) . '</p></div>';
			return;
		}
		$status = self::status( false );
		if ( ! self::recommendation_eligible( $status ) ) { return; }
		$url = admin_url( 'options-general.php?page=foogallery-migrate-storage' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Migration state is ', 'foogallery-migrate' ) . '<strong>' . esc_html( self::format_bytes( $status['option_bytes'] ) ) . '</strong>' . esc_html__( '. Table storage can bound ordinary reads and updates to requested pages or affected records. The first copy still decodes the full option and remains subject to the release capacity and safety checks; this recommendation does not authorize a switch. ', 'foogallery-migrate' ) . '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review migration storage', 'foogallery-migrate' ) . '</a></p></div>';
	}

	private static function recommendation_eligible( $status ) {
		return is_array( $status ) && isset( $status['backend'], $status['phase'], $status['option_bytes'], $status['calibrated'] ) &&
			! empty( $status['calibrated'] ) && 'option' === $status['backend'] && 'stable' === $status['phase'] && self::above_advice_threshold( $status['option_bytes'] );
	}

	private static function above_advice_threshold( $bytes ) {
		if ( ! is_string( $bytes ) && ! is_int( $bytes ) ) { return false; }
		$bytes = (string) $bytes;
		if ( ! preg_match( '/^[0-9]+$/D', $bytes ) ) { return false; }
		$bytes = ltrim( $bytes, '0' );
		if ( '' === $bytes ) { $bytes = '0'; }
		$threshold = (string) self::advice_threshold();
		return strlen( $bytes ) > strlen( $threshold ) || ( strlen( $bytes ) === strlen( $threshold ) && strcmp( $bytes, $threshold ) >= 0 );
	}

	private static function format_bytes( $bytes ) {
		if ( ! is_string( $bytes ) && ! is_int( $bytes ) ) { return __( 'Unavailable', 'foogallery-migrate' ); }
		return (string) $bytes . ' ' . __( 'bytes', 'foogallery-migrate' );
	}

	/** Informational metadata only: never hydrate the legacy state or run DDL. */
	public static function status( $include_schema = true ) {
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
			if ( $include_schema ) {
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
			}
			$s['calibrated'] = ! is_wp_error( StorageCapacityPolicy::resolve() );
		} catch ( \Exception $error ) { $s['backend'] = 'unavailable'; $s['problem'] = $error->getMessage(); }
		catch ( \Throwable $error ) { $s['backend'] = 'unavailable'; $s['problem'] = __( 'Storage health could not be read. Current authority has not been changed.', 'foogallery-migrate' ); }
		return $s;
	}

	/** Display choices never authorize a mutation; the receiver revalidates all. */
	public static function actions( $s, $pending_scope = null ) {
		$remove_all = array( 'operation' => 'remove_all', 'target' => 'all', 'confirmation' => 'remove-all-internal-data',
			'purpose' => 'remove_all', 'label' => __( 'Remove all FooGallery Migrate internal data (keep galleries and media)', 'foogallery-migrate' ) );
		$clear = array( 'operation' => 'clear', 'target' => 'state', 'confirmation' => 'clear-current-state',
			'purpose' => 'clear', 'label' => __( 'Clear migration state (this may allow duplicate galleries and media)', 'foogallery-migrate' ) );
		if ( is_multisite() ) { return array(); }
		if ( 1 === func_num_args() ) { $pending_scope = StorageCleanup::pending_scope(); }
		if ( 'remove_all' === $pending_scope ) { return array( $remove_all ); }
		if ( 'clear' === $pending_scope ) { $clear['label'] = __( 'Continue clearing migration state', 'foogallery-migrate' ); return array( $clear ); }
		if ( ! in_array( $s['backend'], array( 'option', 'table' ), true ) ) { return array( $remove_all ); }
		if ( empty( $s['calibrated'] ) ) { return array( $clear, $remove_all ); }
		if ( 'stable' !== $s['phase'] ) {
			$a = array( array( 'operation' => 'recover', 'target' => $s['target'], 'confirmation' => 'recover-storage', 'purpose' => 'recover', 'label' => __( 'Recover recorded switch', 'foogallery-migrate' ) ) );
			if ( ! empty( $s['discardable'] ) ) { $a[] = array( 'operation' => 'discard', 'target' => 'table', 'confirmation' => 'discard-copy', 'purpose' => 'discard', 'label' => __( 'Discard this incomplete table copy (keep current options)', 'foogallery-migrate' ) ); }
			$a[] = $remove_all;
			return $a;
		}
		if ( 'option' === $s['backend'] && 'verified' !== $s['schema'] ) { return array( $clear, $remove_all ); }
		if ( 'table' === $s['backend'] ) { return array( $clear, array( 'operation' => 'switch', 'target' => 'option', 'confirmation' => 'switch-to-option', 'purpose' => 'table_export', 'label' => __( 'Switch this site to options', 'foogallery-migrate' ) ), $remove_all ); }
		if ( $s['retained'] ) { return array( $clear, array( 'operation' => 'replace', 'target' => 'table', 'confirmation' => 'replace-retained-with-table', 'purpose' => 'option_reimport', 'label' => __( 'Replace retained table copy and switch this site to tables', 'foogallery-migrate' ) ), $remove_all ); }
		return array( $clear, array( 'operation' => 'switch', 'target' => 'table', 'confirmation' => 'switch-to-table', 'purpose' => 'option_import', 'label' => __( 'Switch this site to tables', 'foogallery-migrate' ) ), $remove_all );
	}

	public static function render( $status = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Storage access denied.', 'foogallery-migrate' ) ); }
		$s = is_array( $status ) ? $status : self::status();
		$blog = get_current_blog_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'FooGallery Migration Storage', 'foogallery-migrate' ) . '</h1>';
		echo '<p>' . esc_html__( 'Backend switches affect only this site. Schema provisioning creates shared installation tables but does not switch any site. Storage cleanup does not undo migrations or change galleries and attachments; clearing may allow duplicate output if you migrate again. Back up first. Opening this page never provisions or switches storage.', 'foogallery-migrate' ) . '</p><dl>';
		foreach ( array( __( 'Site ID', 'foogallery-migrate' ) => $blog, __( 'Backend', 'foogallery-migrate' ) => $s['backend'], __( 'Switch phase', 'foogallery-migrate' ) => $s['phase'], __( 'Shared schema', 'foogallery-migrate' ) => $s['schema'], __( 'Option bytes', 'foogallery-migrate' ) => $s['option_bytes'], __( 'Site table records (including retained copies)', 'foogallery-migrate' ) => $s['records'] ) as $label => $value ) {
			echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( null === $value ? __( 'Unavailable', 'foogallery-migrate' ) : $value ) . '</dd>';
		}
		if ( is_multisite() ) { $capacity_copy = __( 'Multisite table operations are unsupported; the release capacity policy does not enable them.', 'foogallery-migrate' ); }
		elseif ( ! empty( $s['calibrated'] ) ) { $capacity_copy = __( 'Release capacity policy available. Each operation still checks size, memory, ownership and the deadline.', 'foogallery-migrate' ); }
		else { $capacity_copy = __( 'No approved release capacity decision is available for this runtime and current storage state. Switching and recovery refuse safely. A customer certificate or manually configured profile cannot enable this release. No fallback to unlimited memory or automatic switching.', 'foogallery-migrate' ); }
		echo '</dl><p>' . esc_html( $capacity_copy ) . '</p>';
		if ( ! is_multisite() && self::recommendation_eligible( $s ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Table storage recommendation', 'foogallery-migrate' ) . '</strong> ' . esc_html__( 'Measured migration-state size: ', 'foogallery-migrate' ) . esc_html( self::format_bytes( $s['option_bytes'] ) ) . esc_html__( '. Table storage can bound ordinary reads and updates to requested pages or affected records. The first copy still decodes the full option, and subsequent operations remain limited by the release admission checks.', 'foogallery-migrate' ) . '</p></div>';
		} elseif ( ! is_multisite() && empty( $s['calibrated'] ) ) {
			echo '<p>' . esc_html__( 'Table storage recommendation and switching controls are unavailable until this runtime and current storage authority pass the shipped release checks. Current storage authority is unchanged.', 'foogallery-migrate' ) . '</p>';
		}
		if ( is_multisite() ) {
			echo '<p>' . esc_html__( 'Table storage recommendations, provisioning, switching and recovery are unavailable on multisite. Existing option-mode flows remain supported, and this screen does not change storage authority.', 'foogallery-migrate' ) . '</p>';
		} elseif ( ! empty( $s['calibrated'] ) ) {
			echo '<p>' . esc_html__( 'Workflow: inspect the measured option size, explicitly provision the shared schema if needed, run the read-only decode-budget preflight with WP-CLI, then confirm copy, verification and switch here. Example: wp --user=admin foogallery-migrate storage preflight --target=table --confirm=switch-to-table. A passing preflight is not conversion authorization. Reversal uses current table data and the receiver still checks admission. A recommendation never changes operation limits.', 'foogallery-migrate' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Table switching, provisioning and recovery controls are unavailable on this runtime. Cleanup controls remain available when storage ownership permits them.', 'foogallery-migrate' ) . '</p>';
		}
		if ( '' !== $s['problem'] ) { echo '<p role="alert">' . esc_html( $s['problem'] ) . '</p>'; }
		if ( is_multisite() ) {
			$migration_url = admin_url( 'admin.php?page=foogallery-migrate' );
			echo '<p>' . esc_html__( 'Multisite option mode remains supported; this screen does not enable table provisioning, switching or removal. Existing option-mode clear controls remain on the migration screen: ', 'foogallery-migrate' ) . '<a href="' . esc_url( $migration_url ) . '">' . esc_html__( 'open FooGallery Migrate', 'foogallery-migrate' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'Table migration storage is supported on single-site installations only. Before downgrading this plugin, complete a verified reversal to options using current table data. If reversal is refused, keep this plugin version and all migration data; do not delete history to force a downgrade. Recovery follows only the recorded authoritative phase. Foreign ownership, corrupt storage or insufficient capacity must be resolved before retrying. Never clear an owner because of its age, a missing PID or a closed browser; assisted recovery requires independently established whole-worker and output quiescence.', 'foogallery-migrate' ) . '</p>';
			echo '<p>' . esc_html__( 'Table storage bounds work across unrelated stored records, but a very large gallery or album tree and full source discovery remain limits. A positive page size is recommended on large sites. page_size = 0 keeps the existing show-all behavior; storage reads are internally batched, but the complete response and WordPress, PHP, server or browser output buffering can still use memory proportional to all rows. The plugin will not change the saved page size automatically.', 'foogallery-migrate' ) . '</p>';
		}
		if ( $s['retained'] ) { echo '<p>' . esc_html__( 'A previous table copy is retained after reversing to options. Re-import explicitly replaces that inactive copy; it never merges both backends.', 'foogallery-migrate' ) . '</p>'; }
		$pending_scope = is_multisite() ? null : StorageCleanup::pending_scope();
		$actions = self::actions( $s, $pending_scope );
		if ( ! is_multisite() && null === $pending_scope && ! empty( $s['calibrated'] ) && 'option' === $s['backend'] && 'stable' === $s['phase'] && 'verified' !== $s['schema'] ) {
			array_unshift( $actions, array( 'operation' => 'provision', 'scope' => 'installation', 'target' => 'table', 'confirmation' => 'provision-shared-schema', 'purpose' => 'provision_schema', 'label' => __( 'Provision shared schema only (keep options)', 'foogallery-migrate' ) ) );
		}
		foreach ( $actions as $a ) {
			echo '<form class="foogm-storage-form" method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '">';
			foreach ( array( 'action' => 'foogallery_migrate_storage', 'operation' => $a['operation'], 'blog_id' => $blog, 'scope' => isset( $a['scope'] ) ? $a['scope'] : 'site', 'target' => $a['target'], 'confirmation' => $a['confirmation'] ) as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
			wp_nonce_field( 'foogallery_migrate_storage_' . $a['purpose'] . '_' . $blog, 'nonce', false );
			if ( 'provision' === $a['operation'] ) { $confirmation = __( 'I have a backup and explicitly confirm shared installation schema provisioning only; no site will switch backends.', 'foogallery-migrate' ); }
			elseif ( 'clear' === $a['operation'] ) { $confirmation = __( 'I understand this clears migration state only and does not undo migrations; clearing may allow duplicate galleries and media.', 'foogallery-migrate' ); }
			else { $confirmation = __( 'I have a backup and explicitly confirm this site-local storage operation.', 'foogallery-migrate' ); }
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
				xhr.onload = function () { button.disabled = false; try { var data = JSON.parse(xhr.responseText), detail = data.data || {}; result.textContent = (detail.message || '') + (detail.failed && detail.failed.length ? ' Failed: ' + detail.failed.join(', ') : '') + (detail.residual && detail.residual.length ? ' Still present: ' + detail.residual.join(', ') : ''); if (data.success && detail.complete !== false) { window.location.reload(); } } catch (error) { result.textContent = <?php echo wp_json_encode( __( 'The response could not be read. Reload to inspect the recorded phase before retrying; no automatic retry was sent.', 'foogallery-migrate' ) ); ?>; } };
				xhr.onerror = xhr.ontimeout = function () { button.disabled = false; result.textContent = <?php echo wp_json_encode( __( 'The connection ended. Reload to inspect the recorded phase before retrying; the operation may have persisted.', 'foogallery-migrate' ) ); ?>; };
				xhr.send(new FormData(form));
			}); }
		}());
		</script>
		<?php
	}
}
