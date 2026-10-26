<?php
namespace FooPlugins\FooGalleryMigrate;

// Loaded only by process-isolated tests. Simulate WordPress translation before
// escaping, including HTML that must never become markup at a plain-text sink.
function __( $text, $domain = 'default' ) {
    return '<em data-domain="' . $domain . '">' . $text . ' & translated</em>';
}

function esc_html__( $text, $domain = 'default' ) {
    return \esc_html( __( $text, $domain ) );
}
