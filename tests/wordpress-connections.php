<?php
require __DIR__ . '/wordpress-media-fixture.php';
use StructuredFields\Connections;
use StructuredFields\Mcp;

$checks = 0;
function sfc_check( $ok, $message ) { global $checks; if ( ! $ok ) throw new RuntimeException( $message ); echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL; }
$address = $_SERVER['REMOTE_ADDR'] ?? null;
try {
    sfc_check( ! Connections::authorized(), 'A WordPress login alone is not an MCP connection' );
    $request = new WP_REST_Request( 'POST' );
    sfc_check( is_wp_error( Connections::permission( $request ) ), 'Issuing keys requires a browser session and nonce, not just current user' );
    sfc_check( is_wp_error( Connections::create( $request ) ), 'Direct key creation without the session is denied' );
    sfc_check( is_wp_error( Connections::revoke( $request ) ), 'Direct key revocation without the session is denied' );
    foreach ( array( 'context', 'list-drafts', 'get-draft', 'save-draft' ) as $key ) {
        sfc_check( is_wp_error( Mcp::execute( $key, array() ) ), 'Direct ability invocation is denied without scoped context: ' . $key );
        $ability = wp_get_ability( 'structured-fields/' . $key );
        $meta = $ability->get_meta();
        sfc_check( false === $meta['show_in_rest'] && false === $meta['public'] && false === $meta['mcp']['public'], 'Ability is not exposed via core REST/global discovery: ' . $key );
        sfc_check( false === $ability->get_input_schema()['additionalProperties'], 'Tool input schema rejects extra authority flags: ' . $key );
    }
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    sfc_check( Connections::secure(), 'Explicit local environment and loopback request permit development HTTP' );
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    sfc_check( ! Connections::secure(), 'Non-loopback requests cannot use local HTTP credentials' );
    if ( ! defined( 'WP_MCP_VERSION' ) ) sfc_check( ! Connections::ready(), 'Missing adapter disables connection readiness without breaking the plugin' );
    echo 'Connection boundary checks: ' . $checks . ' passed.', PHP_EOL;
} finally {
    if ( null === $address ) unset( $_SERVER['REMOTE_ADDR'] ); else $_SERVER['REMOTE_ADDR'] = $address;
}
