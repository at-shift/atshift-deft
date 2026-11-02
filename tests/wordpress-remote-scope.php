<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use Atshift\Deft\Connections;
use Atshift\Deft\Mcp;
if ( ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Disposable test site required.' );
$checks = 0; $key = null; $outer = null;
function remote_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
$admin = get_user_by( 'login', 'sf_local_admin' );
wp_set_current_user( $admin->ID );
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $admin->ID, time() + HOUR_IN_SECONDS, 'logged_in' );
$request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$request->set_body( wp_json_encode( array( 'name' => 'Disposable scope test', 'authoring' => true ) ) );
try {
    $key = Connections::create( $request ); remote_check( is_array( $key ) && isset( $key['token'] ), 'Administrator issues an explicitly scoped key' );
    wp_set_current_user( 0 ); unset( $_COOKIE[LOGGED_IN_COOKIE] );
    $outer = new WP_REST_Request( 'POST', Connections::ROUTE ); $outer->set_header( 'authorization', 'Bearer ' . $key['token'] );
    remote_check( null === Connections::guard( null, rest_get_server(), $outer ) && Connections::authorized(), 'Scoped administrator context authenticates' );
    foreach ( array( '/wp/v2/users', '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes', '/batch/v1', '/structured-fields/v1/connections', '/structured-fields/v1/behaviors', Connections::ROUTE, Connections::PUBLISH_ROUTE ) as $route ) {
        $nested = new WP_REST_Request( 'POST', $route );
        $response = rest_do_request( $nested );
        remote_check( 403 === $response->get_status(), 'Nested REST request denied: ' . $route );
        Connections::finish( $response, rest_get_server(), $nested );
        remote_check( Connections::authorized() && get_current_user_id() === (int) $admin->ID, 'Nested response cannot overwrite or clear the outer scope' );
    }
    foreach ( array( 'install-plugin', 'activate-plugin', 'update-theme', 'create-user', 'update-settings', 'execute-code', 'register-component', 'set-publication' ) as $tool ) remote_check( is_wp_error( Mcp::execute( $tool, array() ) ), 'Draft connection refuses non-allowlisted/admin tool: ' . $tool );
    Connections::finish( new WP_REST_Response(), rest_get_server(), $outer ); $outer = null;
    remote_check( 0 === get_current_user_id() && ! Connections::authorized(), 'Only the outer response restores the original anonymous identity' );
    foreach ( array( '/wp/v2/users', '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes', '/batch/v1' ) as $route ) {
        $outside = new WP_REST_Request( 'POST', $route ); $outside->set_header( 'authorization', 'Bearer ' . $key['token'] );
        remote_check( 403 === rest_do_request( $outside )->get_status() && 0 === get_current_user_id(), 'Bearer key cannot authorize core administration: ' . $route );
    }
    echo 'Remote scope checks: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    if ( $outer ) Connections::finish( new WP_REST_Response(), rest_get_server(), $outer );
    if ( is_array( $key ) ) $wpdb->delete( Connections::table(), array( 'id' => $key['id'] ) );
}
