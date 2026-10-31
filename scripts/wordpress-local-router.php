<?php
// PHP's built-in server treats dotted well-known paths as static files.
if ( 'cli-server' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
$root = realpath( __DIR__ . '/../.local/wordpress' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { http_response_code( 503 ); exit; }
$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( ! in_array( $path, array( '/.well-known/oauth-authorization-server/structured-fields-oauth', '/structured-fields-oauth/.well-known/oauth-authorization-server' ), true ) ) return false;
require $root . '/index.php';
