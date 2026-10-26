<?php
// Deliberately small parser double; native WordPress parsing is checked separately.
if (!function_exists('parse_blocks')) {
function parse_blocks( $content ) {
    return isset( $GLOBALS['fgm_test_block_parses'][$content] ) ? $GLOBALS['fgm_test_block_parses'][$content] : array();
}
}
if (!function_exists('serialize_block')) {
function serialize_block( $block ) {
    return $block['serialized'];
}
}
