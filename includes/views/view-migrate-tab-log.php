<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$migrator = foogallery_migrate_migrator_instance();
$scoped_history = $migrator->uses_scoped_history();
$revision = $scoped_history ? $migrator->get_migrated_revision() : null;
$summary = $migrator->get_migrated_objects_summary();
$migrated_objects = $scoped_history ? null : $migrator->get_migrated_objects();
$log_summary = $scoped_history ? $migrator->get_migrated_log_summary() : null;
$available_types = array();
$status_options = array(
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOT_STARTED => __( 'Not migrated', 'foogallery-migrate' ),
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_QUEUED => __( 'Queued for migration', 'foogallery-migrate' ),
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_STARTED => __( 'Migrating...', 'foogallery-migrate' ),
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_COMPLETED => __( 'Completed', 'foogallery-migrate' ),
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOTHING => __( 'Nothing to migrate', 'foogallery-migrate' ),
	\FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_ERROR => __( 'Error', 'foogallery-migrate' ),
);

if ( $scoped_history ) {
	if ( ! is_array( $log_summary ) || ! isset( $log_summary['total'], $log_summary['by_type'] ) ||
		! is_int( $log_summary['total'] ) || $log_summary['total'] < 0 || ! is_array( $log_summary['by_type'] ) ) {
		throw new \RuntimeException( 'Invalid bounded history log summary.' );
	}
	$counted = 0;
	foreach ( $log_summary['by_type'] as $object_type => $count ) {
		if ( ! is_string( $object_type ) || '' === $object_type || strlen( $object_type ) > 32 ||
			! is_int( $count ) || $count < 1 ) {
			throw new \RuntimeException( 'Invalid bounded history log type.' );
		}
		$available_types[ $object_type ] = ucfirst( $object_type );
		$counted += $count;
	}
	if ( $counted !== $log_summary['total'] ) { throw new \RuntimeException( 'Inconsistent bounded history log summary.' ); }
} elseif ( is_array( $migrated_objects ) ) {
	foreach ( $migrated_objects as $object ) {
		if ( ! is_object( $object ) ) { continue; }
		$object_type = '';
		if ( method_exists( $object, 'type' ) ) { $object_type = $object->type(); }
		elseif ( isset( $object->type ) ) { $object_type = $object->type; }
		if ( is_string( $object_type ) && '' !== $object_type ) {
			$available_types[ $object_type ] = ucfirst( $object_type );
		}
	}
}

if ( $scoped_history && $migrator->get_migrated_revision() !== $revision ) {
	throw new \RuntimeException( 'History changed during log summary.' );
}
if ( $scoped_history ? 0 === $log_summary['total'] : ( empty( $migrated_objects ) || ! is_array( $migrated_objects ) ) ) { ?>
	<p><?php esc_html_e( 'No migrated objects found.', 'foogallery-migrate' ); ?></p>
	<?php
	return;
}

$log_type = 'gallery';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only log filter; the sanitized value is checked against the available types below.
if ( array_key_exists( 'log_type', $_GET ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, not a mutation request; sanitize before using this value in the log view.
	$log_type = sanitize_text_field( wp_unslash( $_GET['log_type'] ) );
}
if ( ! empty( $available_types ) && ! array_key_exists( $log_type, $available_types ) ) {
	$available_type_keys = array_keys( $available_types );
	$log_type = $available_type_keys[0];
}

$filtered_objects = array();
if ( ! $scoped_history ) {
	foreach ( $migrated_objects as $object_id => $object ) {
		if ( ! is_object( $object ) ) { continue; }
		$object_type = '';
		if ( method_exists( $object, 'type' ) ) { $object_type = $object->type(); }
		elseif ( isset( $object->type ) ) { $object_type = $object->type; }
		if ( '' !== $object_type && $object_type === $log_type ) { $filtered_objects[ $object_id ] = $object; }
	}
}

$url = foogallery_migrate_admin_url( 'log' );
$page = 1;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only log pagination; no migration or history data is changed.
if ( array_key_exists( 'log_paged', $_GET ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Unslash and normalize the display page to a positive integer below; no write is authorized here.
	$page = absint( wp_unslash( $_GET['log_paged'] ) );
}
if ( $page < 1 ) {
	$page = 1;
}
$url = add_query_arg( 'log_type', $log_type, $url );
$url = add_query_arg( 'log_paged', $page, $url );
$page_size = (int) apply_filters( 'foogallery_migrate_log_page_size', 100 );
if ( $scoped_history && $page_size < 1 ) { throw new \RuntimeException( 'Invalid bounded history log page size.' ); }
$migrated_objects_count = $scoped_history ? ( isset( $log_summary['by_type'][ $log_type ] ) ? $log_summary['by_type'][ $log_type ] : 0 ) : count( $filtered_objects );

$pagination = new \FooPlugins\FooGalleryMigrate\Pagination();
$pagination->items( $migrated_objects_count );
$pagination->limit( $page_size );
$pagination->parameterName( 'log_paged' );
$pagination->url = $url;
$pagination->currentPage( $page );
$pagination->calculate();
$paginated_objects = $scoped_history ? null : array_slice( $filtered_objects, $pagination->start, $pagination->limit, true );
?>
<h3><?php esc_html_e( 'Migration Stats', 'foogallery-migrate' ); ?></h3>
<?php if ( array_key_exists( 'album', $summary ) ) { ?>
<p>
	<?php esc_html_e( 'Albums : ', 'foogallery-migrate' ); ?>
	<?php echo intval( $summary['album']['count'] ); ?>
</p>
<?php } ?>

<?php if ( array_key_exists( 'gallery', $summary ) ) { ?>
<p>
	<?php esc_html_e( 'Galleries : ', 'foogallery-migrate' ); ?>
	<?php echo intval( $summary['gallery']['count'] ); ?>
</p>
<?php } ?>

<?php if ( array_key_exists( 'image', $summary ) ) { ?>
<p>
	<?php esc_html_e( 'Images : ', 'foogallery-migrate' ); ?>
	<?php echo intval( $summary['image']['count'] ); ?>
	<?php if ( $summary['image']['errors'] > 0 ) { ?>
		<span class="foogallery-migrate-progress-error"><?php /* translators: %s: image error count. */ printf( esc_html__( ' (%s errors)', 'foogallery-migrate' ), intval( $summary['image']['errors'] ) ); ?></span>
	<?php } ?>
</p>
<?php } ?>

<div style="display: flex; align-items: center; gap: 10px; margin: 1em 0;">
	<h3 style="margin: 0;"><?php esc_html_e( 'Migrated Objects', 'foogallery-migrate' ); ?></h3>
	<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin: 0;">
		<label for="foogallery_migrate_log_type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'foogallery-migrate' ); ?></label>
		<input type="hidden" name="post_type" value="<?php echo esc_attr( defined( 'FOOGALLERY_CPT_GALLERY' ) ? FOOGALLERY_CPT_GALLERY : 'foogallery' ); ?>">
		<input type="hidden" name="page" value="foogallery-migrate">
		<input type="hidden" name="tab" value="log">
		<select id="foogallery_migrate_log_type" name="log_type" onchange="this.form.submit()">
			<?php foreach ( $available_types as $type_value => $type_label ) { ?>
				<option value="<?php echo esc_attr( $type_value ); ?>" <?php selected( $log_type, $type_value ); ?>>
					<?php echo esc_html( $type_label ); ?>
				</option>
			<?php } ?>
		</select>
	</form>
</div>
<input type="hidden" id="foogallery_migrate_log_nonce" value="<?php echo esc_attr( wp_create_nonce( 'foogallery_migrate_log' ) ); ?>">
<?php if ( $scoped_history && $migrator->get_migrated_revision() !== $revision ) { throw new \RuntimeException( 'History changed during log rendering.' ); } ?>
<?php if ( 0 === $migrated_objects_count ) { ?>
	<p><?php esc_html_e( 'No migrated objects found for this type.', 'foogallery-migrate' ); ?></p>
	<?php
	return;
}
?>
<table class="wp-list-table widefat fixed striped table-view-list pages">
	<thead>
		<tr>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Type', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Source', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Title', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Migrated', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Status', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Error', 'foogallery-migrate' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Actions', 'foogallery-migrate' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$row_index = 0;
	$render_log_row = function ( $object_id, $object ) use ( &$row_index, $status_options ) {
		if ( ! is_object( $object ) ) {
			return;
		}

		$type_label = '';
		$type_key = '';
		if ( method_exists( $object, 'type' ) ) {
			$type_label = $object->type();
		} elseif ( isset( $object->type ) ) {
			$type_label = $object->type;
		}
		$type_key = strtolower( $type_label );

		$source_value = '';
		$source_url = '';
		if ( isset( $object->source_url ) && is_string( $object->source_url ) && '' !== $object->source_url ) {
			$source_value = $object->source_url;
			$source_url = $object->source_url;
		} elseif ( method_exists( $object, 'unique_identifier' ) ) {
			$source_value = $object->unique_identifier();
		} elseif ( isset( $object->ID ) ) {
			$source_value = $object->ID;
		}

		$title = '';
		if ( isset( $object->title ) && '' !== $object->title ) {
			$title = $object->title;
		} elseif ( isset( $object->migrated_title ) && '' !== $object->migrated_title ) {
			$title = $object->migrated_title;
		}

		$migrated_id = isset( $object->migrated_id ) ? intval( $object->migrated_id ) : 0;
		$migrated_output = '<span>&mdash;</span>';
		if ( $migrated_id > 0 ) {
			if ( 'gallery' === $type_key || 'album' === $type_key ) {
				$edit_link = admin_url( 'post.php?post=' . $migrated_id . '&action=edit' );
				$migrated_output = '<a href="' . esc_url( $edit_link ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $migrated_id ) . '</a>';
			} elseif ( 'image' === $type_key ) {
				$thumb = wp_get_attachment_image( $migrated_id, array( 32, 32 ), true );
				if ( $thumb ) {
					$migrated_output = $thumb;
				} else {
					$migrated_output = esc_html( $migrated_id );
				}
			} else {
				$migrated_output = esc_html( $migrated_id );
			}
		}

		$status_key = isset( $object->migration_status ) ? $object->migration_status : '';
		$status_label = array_key_exists( $status_key, $status_options ) ? $status_options[ $status_key ] : $status_key;

		$error_message = '';
		if ( method_exists( $object, 'has_error' ) && $object->has_error() && method_exists( $object, 'get_error_message' ) ) {
			$error_message = $object->get_error_message();
		}

		$row_index++;
		?>
		<tr class="<?php echo esc_attr( ( $row_index % 2 === 0 ) ? 'alternate' : '' ); ?>">
			<td><?php echo esc_html( ucfirst( $type_label ) ); ?></td>
			<td>
				<?php if ( '' === $source_value ) { ?>
					<span>&mdash;</span>
				<?php } elseif ( $source_url && preg_match( '/^https?:\\/\\//i', $source_url ) ) { ?>
					<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $source_value ); ?></a>
				<?php } else { ?>
					<?php echo esc_html( $source_value ); ?>
				<?php } ?>
			</td>
			<td>
				<?php if ( '' === $title ) { ?>
					<span>&mdash;</span>
				<?php } else { ?>
					<?php echo esc_html( $title ); ?>
				<?php } ?>
			</td>
			<td>
				<?php echo wp_kses_post( $migrated_output ); ?>
			</td>
			<td>
				<?php if ( '' === $status_label ) { ?>
					<span>&mdash;</span>
				<?php } else { ?>
					<span class="foogallery-migrate-log-status"><?php echo esc_html( $status_label ); ?></span>
				<?php } ?>
			</td>
			<td>
				<?php if ( '' === $error_message ) { ?>
					<span>&mdash;</span>
				<?php } else { ?>
					<?php echo esc_html( $error_message ); ?>
				<?php } ?>
			</td>
			<td class="foogallery-migrate-log-actions" data-object-id="<?php echo esc_attr( $object_id ); ?>">
				<button type="button" class="button button-secondary foogallery-log-delete-object"><?php esc_html_e( 'Delete', 'foogallery-migrate' ); ?></button>	
				<button type="button" class="button foogallery-log-change-status"><?php esc_html_e( 'Change Status', 'foogallery-migrate' ); ?></button>
				<div class="foogallery-log-status-editor" style="display: none;">
					<select class="foogallery-log-status-select">
						<?php foreach ( $status_options as $status_value => $status_label_option ) { ?>
							<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $status_key, $status_value ); ?>>
								<?php echo esc_html( $status_label_option ); ?>
							</option>
						<?php } ?>
					</select>
					<button type="button" class="button button-primary foogallery-log-update-status"><?php esc_html_e( 'Update', 'foogallery-migrate' ); ?></button>
				</div>
			</td>
		</tr>
	<?php
	};
	if ( $scoped_history ) {
		// A UI page may exceed the storage page size. Walk and release fixed
		// 100-row pages; never hold the complete selected history in memory.
		$cursor = null; $skip = $pagination->start; $remaining = $pagination->limit;
		$expected_rows = min( $pagination->limit, max( 0, $migrated_objects_count - $pagination->start ) );
		$rendered = 0;
		while ( $remaining > 0 && $pagination->start < $migrated_objects_count ) {
			$previous_cursor = $cursor;
			$batch = $migrator->get_migrated_log_page( (string) $log_type, '', 100, $cursor );
			if ( ! is_array( $batch ) || ! isset( $batch['rows'], $batch['revision'] ) ||
				! is_array( $batch['rows'] ) || count( $batch['rows'] ) > 100 ||
				$batch['revision'] !== $revision ||
				( ! array_key_exists( 'cursor', $batch ) || ( null !== $batch['cursor'] && ! is_array( $batch['cursor'] ) ) ) ||
				( empty( $batch['rows'] ) && null !== $batch['cursor'] ) ) {
				throw new \RuntimeException( 'Invalid or changed bounded history log page.' );
			}
			foreach ( $batch['rows'] as $entry ) {
				if ( ! is_array( $entry ) || ! array_key_exists( 'key', $entry ) || ! isset( $entry['value'] ) ||
					( ! is_string( $entry['key'] ) && ! is_int( $entry['key'] ) ) || ! is_object( $entry['value'] ) ) {
					throw new \RuntimeException( 'Invalid bounded history log row.' );
				}
				if ( $skip > 0 ) { $skip--; continue; }
				if ( $remaining < 1 ) { break; }
				$entry_type = method_exists( $entry['value'], 'type' ) ? $entry['value']->type() :
					( isset( $entry['value']->type ) ? $entry['value']->type : '' );
				if ( $entry_type !== (string) $log_type ) { throw new \RuntimeException( 'Indexed log type differs from hydrated record.' ); }
				$render_log_row( $entry['key'], $entry['value'] ); $remaining--; $rendered++;
			}
			$cursor = $batch['cursor'];
			if ( null !== $cursor && $cursor === $previous_cursor ) { throw new \RuntimeException( 'History log cursor did not advance.' ); }
			if ( null === $cursor ) { break; }
		}
		if ( $rendered !== $expected_rows || $migrator->get_migrated_revision() !== $revision ) {
			throw new \RuntimeException( 'History changed or ended during log rendering.' );
		}
	} else {
		foreach ( $paginated_objects as $object_id => $object ) { $render_log_row( $object_id, $object ); }
	}
	?>
	</tbody>
</table>
<div class="tablenav bottom">
	<div class="tablenav-pages">
		<?php echo wp_kses_post( $pagination->render( false ) ); ?>
	</div>
</div>
<script>
	jQuery(function ($) {
		var $logContainer = $('#foogallery_migrate_log');
		var nonce = $('#foogallery_migrate_log_nonce').val();

		$logContainer.on('click', '.foogallery-log-change-status', function () {
			var $cell = $(this).closest('td');
			$(this).hide();
			$cell.find('.foogallery-log-status-editor').show();
		});

		$logContainer.on('click', '.foogallery-log-update-status', function () {
			var $editor = $(this).closest('.foogallery-log-status-editor');
			var $cell = $(this).closest('td');
			var objectId = $cell.data('object-id');
			var newStatus = $editor.find('.foogallery-log-status-select').val();
			var $statusCell = $cell.closest('tr').find('.foogallery-migrate-log-status');
			var $updateButton = $(this);

			$updateButton.prop('disabled', true);
			$.ajax({
				type: 'POST',
				url: ajaxurl,
				dataType: 'json',
				data: {
					action: 'foogallery_migrate_update_status',
					foogallery_migrate_log: nonce,
					object_id: objectId,
					status: newStatus
				},
				success: function (response) {
					if (response && response.success && response.data && response.data.status_label) {
						$statusCell.text(response.data.status_label);
					}
					$editor.hide();
					$cell.find('.foogallery-log-change-status').show();
				},
				complete: function () {
					$updateButton.prop('disabled', false);
				}
			});
		});

		$logContainer.on('click', '.foogallery-log-delete-object', function () {
			if (!window.confirm(<?php echo wp_json_encode( __( 'Are you sure you want to delete this migrated object from the log?', 'foogallery-migrate' ) ); ?>)) {
				return;
			}
			var $button = $(this);
			var $cell = $button.closest('td');
			var objectId = $cell.data('object-id');

			$button.prop('disabled', true);
			$.ajax({
				type: 'POST',
				url: ajaxurl,
				dataType: 'json',
				data: {
					action: 'foogallery_migrate_delete_object',
					foogallery_migrate_log: nonce,
					object_id: objectId
				},
				success: function (response) {
					if (response && response.success) {
						$cell.closest('tr').remove();
					} else {
						$button.prop('disabled', false);
					}
				},
				error: function () {
					$button.prop('disabled', false);
				}
			});
		});
	});
</script>
