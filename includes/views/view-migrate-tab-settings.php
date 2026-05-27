<?php
$migrator = foogallery_migrate_migrator_instance();
$settings = $migrator->get_settings();
$gallery_templates = $migrator->get_available_gallery_templates();
$gallery_settings_sources = $migrator->get_available_gallery_settings_sources();
$album_settings_sources = $migrator->get_available_album_settings_sources();
?>
<?php if ( array_key_exists( 'settings-updated', $_GET ) ) { ?>
	<div class="notice notice-success inline">
		<p><?php esc_html_e( 'Settings saved.', 'foogallery-migrate' ); ?></p>
	</div>
<?php } ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="foogallery_migrate_save_settings">
	<?php wp_nonce_field( 'foogallery_migrate_settings', 'foogallery_migrate_settings', false ); ?>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="foogallery_migrate_override_gallery_layout"><?php esc_html_e( 'Override Gallery Layout', 'foogallery-migrate' ); ?></label>
				</th>
				<td>
					<select id="foogallery_migrate_override_gallery_layout" name="override_gallery_layout">
						<option value=""><?php esc_html_e( 'Do not override', 'foogallery-migrate' ); ?></option>
						<?php foreach ( $gallery_templates as $template_slug => $template_name ) { ?>
							<option value="<?php echo esc_attr( $template_slug ); ?>" <?php selected( $settings['override_gallery_layout'], $template_slug ); ?>>
								<?php echo esc_html( $template_name ); ?>
							</option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="foogallery_migrate_override_gallery_settings"><?php esc_html_e( 'Override Gallery Settings', 'foogallery-migrate' ); ?></label>
				</th>
				<td>
					<select id="foogallery_migrate_override_gallery_settings" name="override_gallery_settings">
						<option value="0"><?php esc_html_e( 'Default', 'foogallery-migrate' ); ?></option>
						<?php foreach ( $gallery_settings_sources as $gallery_id => $gallery_title ) { ?>
							<option value="<?php echo esc_attr( $gallery_id ); ?>" <?php selected( $settings['override_gallery_settings'], $gallery_id ); ?>>
								<?php echo esc_html( $gallery_title ); ?>
							</option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="foogallery_migrate_override_album_settings"><?php esc_html_e( 'Override Album Settings', 'foogallery-migrate' ); ?></label>
				</th>
				<td>
					<select id="foogallery_migrate_override_album_settings" name="override_album_settings">
						<option value="0"><?php esc_html_e( 'Default', 'foogallery-migrate' ); ?></option>
						<?php foreach ( $album_settings_sources as $album_id => $album_title ) { ?>
							<option value="<?php echo esc_attr( $album_id ); ?>" <?php selected( $settings['override_album_settings'], $album_id ); ?>>
								<?php echo esc_html( $album_title ); ?>
							</option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="foogallery_migrate_page_size"><?php esc_html_e( 'Page Size', 'foogallery-migrate' ); ?></label>
				</th>
				<td>
					<input id="foogallery_migrate_page_size" name="page_size" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr( $settings['page_size'] ); ?>">
					<p class="description"><?php esc_html_e( 'The number of items per page in the migration. Set to zero to disable pagination.', 'foogallery-migrate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="foogallery_migrate_images_per_turn"><?php esc_html_e( 'Images Per Turn', 'foogallery-migrate' ); ?></label>
				</th>
				<td>
					<input id="foogallery_migrate_images_per_turn" name="images_per_turn" type="number" min="1" step="1" class="small-text" value="<?php echo esc_attr( $settings['images_per_turn'] ); ?>">
					<p class="description"><?php esc_html_e( 'The number of images to import during each migration AJAX request.', 'foogallery-migrate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug Enabled', 'foogallery-migrate' ); ?></th>
				<td>
					<label for="foogallery_migrate_debug_enabled">
						<input id="foogallery_migrate_debug_enabled" name="debug_enabled" type="checkbox" value="1" <?php checked( $settings['debug_enabled'] ); ?>>
						<?php esc_html_e( 'Enable debug output for migration views.', 'foogallery-migrate' ); ?>
					</label>
				</td>
			</tr>
		</tbody>
	</table>
	<?php submit_button( __( 'Save Settings', 'foogallery-migrate' ) ); ?>
</form>
