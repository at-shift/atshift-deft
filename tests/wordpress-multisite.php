<?php
// Invoked through WP-CLI --url in a disposable network, once for each actual bootstrap.
if ( ! defined( 'ABSPATH' ) ) require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use Atshift\Deft\{PrivateBlobs, PrefixMigration, Connections, Access};
if ( ! is_multisite() || ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) || ! str_starts_with( home_url(), 'http://127.0.0.1:8128' ) ) throw new RuntimeException( 'Disposable network required.' );
$state_file = dirname( __DIR__ ) . '/.local/multisite-state.json';
$GLOBALS['checks'] = 0; $identity = 'multisite-isolation-fixture';
function network_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
function network_meta( $bytes ) { return array( 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ); }
$phase = getenv( 'DEFT_SITE_PHASE' );
$admin = get_user_by( 'login', 'sf_local_admin' ); wp_set_current_user( $admin->ID );
network_check( 'complete' === get_option( PrefixMigration::MARKER ) && PrivateBlobs::available(), 'Current site initializes canonical schema independently' );
network_check( (bool) get_role( 'atshde_author' ) && Access::workspace(), 'Current site has its own migrated roles' );
if ( 'seed' === $phase ) {
    network_check( 1 === get_current_blog_id() && ! file_exists( $state_file ), 'Only the main disposable site seeds state' );
    $bytes = "main-site\0private\xff"; $meta = network_meta( $bytes );
    PrivateBlobs::put( 'asset', $identity, $bytes, $meta );
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $admin->ID, time() + HOUR_IN_SECONDS, 'logged_in' );
    $request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $request->set_body( wp_json_encode( array( 'name' => 'Network isolation fixture', 'authoring' => true ) ) );
    $connection = Connections::create( $request ); network_check( is_array( $connection ) && isset( $connection['token'] ), 'Main-site scoped credential created' );
    file_put_contents( $state_file, wp_json_encode( array( 'table' => PrivateBlobs::table(), 'meta' => $meta, 'token' => $connection['token'] ) ) ); chmod( $state_file, 0600 );
} elseif ( 'verify' === $phase ) {
    $state = json_decode( file_get_contents( $state_file ), true );
    network_check( get_current_blog_id() > 1 && PrivateBlobs::table() !== $state['table'], 'Second site resolves a different private table' );
    network_check( null === PrivateBlobs::get( 'asset', $identity, $state['meta'] ), 'Same asset identity cannot read another site\'s bytes' );
    $bytes = 'second-site-private'; $meta = network_meta( $bytes );
    PrivateBlobs::put( 'asset', $identity, $bytes, $meta );
    network_check( PrivateBlobs::get( 'asset', $identity, $meta ) === $bytes, 'Second site can store its own independent bytes' );
    wp_set_current_user( 0 ); unset( $_COOKIE[LOGGED_IN_COOKIE] );
    $request = new WP_REST_Request( 'POST', Connections::ROUTE ); $request->set_header( 'authorization', 'Bearer ' . $state['token'] );
    $result = Connections::guard( null, rest_get_server(), $request );
    network_check( is_wp_error( $result ) && 0 === get_current_user_id(), 'Main-site credential cannot authenticate at the second site' );
    PrivateBlobs::delete( 'asset', $identity );
    switch_to_blog( 1 );
    network_check( PrivateBlobs::get( 'asset', $identity, $state['meta'] ) === "main-site\0private\xff", 'Second-site writes leave main-site private bytes unchanged' );
    PrivateBlobs::delete( 'asset', $identity ); restore_current_blog();
} else throw new RuntimeException( 'Expected a network fixture phase.' );
echo 'Multisite checks: ' . $GLOBALS['checks'] . ' passed.' . PHP_EOL;
