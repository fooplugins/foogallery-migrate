<?php
/**
 * Integration regression for WP core gallery migration.
 *
 * Run inside a disposable WordPress site with:
 * wp eval-file tests/integration/core-gallery-migration.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    throw new RuntimeException( 'WordPress must be loaded.' );
}

function foogm_test_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function foogm_test_attachment( $label ) {
    return wp_insert_attachment(
        array(
            'post_title'     => 'FooGallery Migrate Test ' . $label,
            'post_status'    => 'inherit',
            'post_mime_type' => 'image/png',
            'guid'           => trailingslashit( wp_get_upload_dir()['baseurl'] ) . 'foogm-test-' . strtolower( $label ) . '.png',
        )
    );
}

$created_posts = array();
$created_attachments = array();
$created_galleries = array();
$failure = false;

try {
    $created_attachments[] = $first_id = foogm_test_attachment( 'First' );
    $created_attachments[] = $second_id = foogm_test_attachment( 'Second' );
    $missing_id = 99999999;

    $shortcode = '[gallery ids="' . $first_id . ',' . $second_id . ',' . $first_id . ',' . $missing_id . '" columns="3" link="file" size="medium" orderby="post__in"]';
    $identical = '[gallery ids="' . $second_id . ',' . $first_id . '" columns="2"]';
    $block = '<!-- wp:group --><div class="wp-block-group"><!-- wp:gallery {"columns":2,"imageCrop":false,"linkTo":"media"} --><figure class="wp-block-gallery has-nested-images columns-2"><!-- wp:image {"id":' . $second_id . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"></figure><!-- /wp:image --><!-- wp:image {"id":' . $first_id . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"></figure><!-- /wp:image --></figure><!-- /wp:gallery --></div><!-- /wp:group -->';
    $content = 'Escaped mention: [[gallery ids="' . $first_id . '"]]' . "\n\n" . $shortcode . "\n\n" . $block . "\n\n" . $identical . "\n\n" . $identical;

    $created_posts[] = $post_id = wp_insert_post(
        array(
            'post_title'   => 'FooGallery Migrate Integration Fixture',
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_content' => $content,
        )
    );
    $created_posts[] = wp_insert_post(
        array(
            'post_title'   => 'FooGallery Migrate Draft Fixture',
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_content' => $shortcode,
        )
    );
    $created_posts[] = wp_insert_post(
        array(
            'post_title'   => 'FooGallery Migrate Malformed Fixture',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[gallery ids=""] <!-- wp:gallery --><p>empty</p><!-- /wp:gallery -->',
        )
    );
    $created_posts[] = $attached_post_id = wp_insert_post(
        array(
            'post_title'   => 'FooGallery Migrate Attached Images Fixture',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '[gallery columns="2" exclude="' . $missing_id . '"]',
        )
    );
    wp_update_post( array( 'ID' => $first_id, 'post_parent' => $attached_post_id, 'menu_order' => 1 ) );
    wp_update_post( array( 'ID' => $second_id, 'post_parent' => $attached_post_id, 'menu_order' => 2 ) );

    delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );
    $migrator = foogallery_migrate_migrator_instance();
    $plugins = $migrator->run_detection();
    $core_plugin = false;
    foreach ( $plugins as $plugin ) {
        if ( 'WordPress Core' === $plugin->name() ) {
            $core_plugin = $plugin;
            break;
        }
    }

    foogm_test_assert( false !== $core_plugin, 'WP core must be a first-class migration source.' );
    foogm_test_assert( $core_plugin->is_detected, 'Published WP core galleries must be detected.' );
    $test_init = new \FooPlugins\FooGalleryMigrate\Init();
    foogm_test_assert( false !== has_action( 'admin_post_foogallery_migrate_detect_wordpress_core' ), 'The no-JS core detection handler must be registered.' );
    foogm_test_assert( false !== has_action( 'admin_post_foogallery_migrate_content' ), 'The no-JS content migration handler must be registered.' );

    ob_start();
    require FOOGM_DIR . '/includes/views/view-migrate-tab-sources.php';
    $source_view = ob_get_clean();
    foogm_test_assert( false !== strpos( $source_view, 'Detect WordPress Galleries' ), 'The Plugins tab must provide a clear core gallery detection action.' );
    foogm_test_assert( false !== strpos( $source_view, 'admin-post.php' ), 'Core detection must work without JavaScript.' );

    $attached_occurrences = $core_plugin->find_content_occurrences( get_post( $attached_post_id ) );
    foogm_test_assert( 1 === count( $attached_occurrences ), 'A classic [gallery] without explicit IDs must resolve attached images.' );
    foogm_test_assert( array( $first_id, $second_id ) === $attached_occurrences[0]['attachment_ids'], 'Attached-image gallery order must follow menu order.' );

    $spaced_block = '<!-- wp:gallery { "ids" : [' . $second_id . ', ' . $first_id . '], "columns" : 2 } /-->';
    $hybrid_block = '<!-- wp:gallery {"ids":[' . $first_id . ',' . $second_id . ']} --><figure class="wp-block-gallery"><!-- wp:image {"id":' . $second_id . '} --><figure></figure><!-- /wp:image --><!-- wp:image {"id":' . $first_id . '} --><figure></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
    $ordered_shortcode = '[gallery include="' . $first_id . ',' . $second_id . '" orderby="ID" order="DESC"]';
    $created_posts[] = $formatting_post_id = wp_insert_post(
        array(
            'post_title'   => 'FooGallery Migrate Formatting Fixture',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $spaced_block . "\n" . $spaced_block . "\n" . $hybrid_block . "\n" . $ordered_shortcode,
        )
    );
    $formatting_occurrences = $core_plugin->find_content_occurrences( get_post( $formatting_post_id ) );
    foogm_test_assert( 4 === count( $formatting_occurrences ), 'Noncanonical and repeated gallery blocks must be detected exactly.' );
    foogm_test_assert( $spaced_block === $formatting_occurrences[0]['original_content'], 'Stored block formatting must remain exact.' );
    foogm_test_assert( $formatting_occurrences[0]['match_offset'] !== $formatting_occurrences[1]['match_offset'], 'Repeated blocks must have distinct offsets.' );
    foogm_test_assert( array( $second_id, $first_id ) === $formatting_occurrences[0]['attachment_ids'], 'Block image order must follow source IDs.' );
    foogm_test_assert( array( $second_id, $first_id ) === $formatting_occurrences[2]['attachment_ids'], 'Nested image order must override conflicting legacy parent IDs.' );
    foogm_test_assert( array( max( $first_id, $second_id ), min( $first_id, $second_id ) ) === $formatting_occurrences[3]['attachment_ids'], 'Shortcode orderby and order attributes must match WordPress core.' );

    $galleries = array();
    foreach ( $core_plugin->find_galleries() as $gallery ) {
        if ( isset( $gallery->data['post_id'] ) && (int) $gallery->data['post_id'] === (int) $post_id ) {
            $galleries[] = $gallery;
        }
    }
    foogm_test_assert( 4 === count( $galleries ), 'Expected shortcode, nested block, and two identical shortcode occurrences; got ' . count( $galleries ) . '.' );
    foogm_test_assert( 2 === count( $galleries[0]->children ), 'Missing and duplicate attachment IDs must be removed.' );
    foogm_test_assert( $first_id === (int) $galleries[0]->children[0]->migrated_id, 'Attachment order must preserve the first image.' );
    foogm_test_assert( $second_id === (int) $galleries[0]->children[1]->migrated_id, 'Attachment order must preserve the second image.' );
    foogm_test_assert( '3' === (string) $galleries[0]->data['attributes']['columns'], 'Safely preservable shortcode attributes must remain available.' );

    $items = $migrator->get_content_migrator()->scan_content( true );
    $core_items = array();
    foreach ( $items as $key => $item ) {
        if ( 'WordPress Core' === $item['plugin_name'] && (int) $item['post_id'] === (int) $post_id ) {
            $core_items[ $key ] = $item;
        }
    }
    foogm_test_assert( 4 === count( $core_items ), 'Blocks / Shortcodes must list every valid core occurrence.' );
    foogm_test_assert( 'block' === array_values( $core_items )[1]['type'], 'Nested core/gallery must be listed as a block.' );
    foogm_test_assert( ! empty( array_values( $core_items )[0]['source_context'] ), 'Each occurrence must include source context.' );

    ob_start();
    require FOOGM_DIR . '/includes/views/view-migrate-tab-content.php';
    $content_view = ob_get_clean();
    foogm_test_assert( false !== strpos( $content_view, 'Migrate &amp; Replace Selected' ), 'Core occurrences must expose a batch migrate-and-replace action.' );
    foogm_test_assert( false !== strpos( $content_view, 'admin-post.php' ), 'Content migration must submit to the admin-post controller without JavaScript.' );
    foogm_test_assert( false !== strpos( $content_view, 'value="foogallery_migrate_content"' ), 'Content migration must retain a no-JS submit action.' );
    foogm_test_assert( false !== strpos( $content_view, 'Gallery shortcode' ), 'The content table must show per-occurrence source context.' );

    $identical_keys = array();
    foreach ( $core_items as $key => $item ) {
        if ( $identical === $item['original_content'] ) {
            $identical_keys[] = $key;
        }
    }
    foogm_test_assert( 2 === count( $identical_keys ), 'Both identical shortcode occurrences must be independently addressable.' );

    $mixed_items = $items;
    $mixed_items[] = array_merge(
        $mixed_items[ $identical_keys[0] ],
        array(
            'plugin_name'             => 'Legacy Fixture',
            'original_content'        => '[stale-legacy-gallery]',
            'match_offset'            => 0,
            'migrated'                => true,
            'migrated_foogallery_id'  => 1,
        )
    );
    $mixed_item_key = count( $mixed_items ) - 1;
    $migrator->get_content_migrator()->set_setting( 'content', $mixed_items );
    $gallery_count_before_mixed_attempt = (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish;
    $mixed_result = $migrator->get_content_migrator()->migrate_and_replace_content( array( $identical_keys[0], $mixed_item_key ) );
    foogm_test_assert( 0 === $mixed_result['success'] && ! empty( $mixed_result['errors'] ), 'A stale mixed-selection item must fail the complete preflight.' );
    foogm_test_assert( $gallery_count_before_mixed_attempt === (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish, 'Mixed-selection preflight failure must not create a core FooGallery entity.' );
    $migrator->get_content_migrator()->set_setting( 'content', $items );

    $gallery_count_before_stale_attempt = (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish;
    $stale_item = $core_items[ $identical_keys[0] ];
    $stale_content = substr_replace( $content, '[gallery ids="changed"]', $stale_item['match_offset'], strlen( $stale_item['original_content'] ) );
    wp_update_post( array( 'ID' => $post_id, 'post_content' => $stale_content ) );
    $stale_result = $migrator->get_content_migrator()->migrate_and_replace_content( array( $identical_keys[0] ) );
    foogm_test_assert( 0 === $stale_result['success'] && ! empty( $stale_result['errors'] ), 'Changed content must fail preflight before migration.' );
    foogm_test_assert( $gallery_count_before_stale_attempt === (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish, 'A stale occurrence must not create a FooGallery entity.' );

    wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
    $items = $migrator->get_content_migrator()->scan_content( true );
    $identical_keys = array();
    foreach ( $items as $key => $item ) {
        if ( 'WordPress Core' === $item['plugin_name'] && (int) $item['post_id'] === (int) $post_id && $identical === $item['original_content'] ) {
            $identical_keys[] = $key;
        }
    }
    foogm_test_assert( 2 === count( $identical_keys ), 'Restored identical occurrences must be rescanned.' );

    $gallery_count_before_draft_attempt = (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish;
    wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
    $draft_result = $migrator->get_content_migrator()->migrate_and_replace_content( array( $identical_keys[0] ) );
    foogm_test_assert( 0 === $draft_result['success'] && ! empty( $draft_result['errors'] ), 'A post that is no longer published must fail migration preflight.' );
    foogm_test_assert( $gallery_count_before_draft_attempt === (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish, 'A draft source must not create a FooGallery entity.' );
    wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

    $items = $migrator->get_content_migrator()->scan_content( true );
    $identical_keys = array();
    foreach ( $items as $key => $item ) {
        if ( 'WordPress Core' === $item['plugin_name'] && (int) $item['post_id'] === (int) $post_id && $identical === $item['original_content'] ) {
            $identical_keys[] = $key;
        }
    }
    foogm_test_assert( 2 === count( $identical_keys ), 'Republished occurrences must be rescanned.' );

    $result = $migrator->get_content_migrator()->migrate_and_replace_content( array( $identical_keys[0] ) );
    foogm_test_assert( 1 === $result['success'], 'One selected occurrence must migrate and replace successfully.' );
    $after_first = get_post( $post_id )->post_content;
    foogm_test_assert( 1 === substr_count( $after_first, $identical ), 'Replacing one occurrence must not replace an identical sibling.' );
    foogm_test_assert( 1 === substr_count( $after_first, '[foogallery id="' ), 'The exact selected occurrence must become one FooGallery reference.' );

    $gallery_count = (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish;
    $retry = $migrator->get_content_migrator()->migrate_and_replace_content( array( $identical_keys[0] ) );
    foogm_test_assert( 0 === $retry['success'], 'Retrying a replaced occurrence must be a no-op.' );
    foogm_test_assert( $gallery_count === (int) wp_count_posts( FOOGALLERY_CPT_GALLERY )->publish, 'Retry must not duplicate FooGallery entities.' );

    foreach ( $migrator->get_migrated_objects() as $object ) {
        if ( is_object( $object ) && 'gallery' === $object->type() && 'WordPress Core' === $object->plugin->name() ) {
            $created_galleries[] = (int) $object->migrated_id;
        }
    }
} catch ( Exception $exception ) {
    $failure = $exception;
}

foreach ( array_unique( $created_galleries ) as $gallery_id ) {
    if ( $gallery_id > 0 ) {
        wp_delete_post( $gallery_id, true );
    }
}
foreach ( array_unique( $created_posts ) as $post_id ) {
    if ( $post_id > 0 ) {
        wp_delete_post( $post_id, true );
    }
}
foreach ( array_unique( $created_attachments ) as $attachment_id ) {
    if ( $attachment_id > 0 ) {
        wp_delete_attachment( $attachment_id, true );
    }
}
delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );

if ( $failure ) {
    throw $failure;
}

echo "PASS: WP core gallery detection, parsing, exact replacement, order, attributes, and idempotency.\n";
