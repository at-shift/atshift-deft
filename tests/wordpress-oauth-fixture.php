<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use StructuredFields\Connections;
use StructuredFields\OAuthStore;

if ( DB_NAME !== 'sf_prototype_local' || home_url() !== 'http://127.0.0.1:8128' || wp_get_environment_type() !== 'local' ) {
    throw new RuntimeException( 'Use the explicitly local, isolated WordPress site.' );
}
$action = $argv[1] ?? '';
$run = $argv[2] ?? '';
if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) ) throw new RuntimeException( 'Disposable run ID required.' );
$login = 'sf_oauth_admin_' . $run;
$prefix = 'sf-oauth-test-' . $run . '-';

function sf_oauth_test_owner( $id, $run ) {
    $user = get_user_by( 'id', (int) $id );
    if ( ! $user || get_user_meta( $user->ID, '_sf_test_run', true ) !== $run ) throw new RuntimeException( 'Not a disposable fixture owner.' );
    return $user;
}

if ( 'create' === $action ) {
    if ( get_user_by( 'login', $login ) ) throw new RuntimeException( 'Fixture already exists.' );
    $password = wp_generate_password( 32 );
    $id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => $password, 'role' => 'administrator' ) );
    if ( is_wp_error( $id ) ) throw new RuntimeException( 'Could not create disposable administrator.' );
    update_user_meta( $id, '_sf_test_run', $run );
    echo wp_json_encode( array( 'username' => $login, 'password' => $password, 'uid' => $id ) );
} elseif ( 'cleanup' === $action ) {
    $owners = get_users( array( 'meta_key' => '_sf_test_run', 'meta_value' => $run, 'fields' => 'ID' ) );
    foreach ( $owners as $owner ) {
        sf_oauth_test_owner( $owner, $run );
        if ( class_exists( OAuthStore::class ) ) {
            $wpdb->delete( OAuthStore::tokens(), array( 'owner_id' => $owner ) );
            $wpdb->delete( OAuthStore::flows(), array( 'owner_id' => $owner ) );
        }
        $wpdb->delete( Connections::table(), array( 'owner_id' => $owner ) );
    }
    if ( class_exists( OAuthStore::class ) ) {
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE name LIKE %s', OAuthStore::clients(), $wpdb->esc_like( $prefix ) . '%' ) );
    }
    $admin = get_user_by( 'login', $login );
    if ( $admin ) { sf_oauth_test_owner( $admin->ID, $run ); wp_delete_user( $admin->ID ); }
    echo 'fixture-ok';
} elseif ( 'keys' === $action ) {
    $keys = get_option( StructuredFields\OAuth::KEY_OPTION, null );
    $details = is_array( $keys ) && isset( $keys['public'] ) ? openssl_pkey_get_details( openssl_pkey_get_public( $keys['public'] ) ) : null;
    echo wp_json_encode( array( 'configured' => is_array( $keys ), 'type' => $details['type'] ?? null, 'bits' => $details['bits'] ?? 0,
        'publicHash' => isset( $keys['public'] ) ? hash( 'sha256', $keys['public'] ) : null,
        'encrypted' => is_string( $keys['encrypted'] ?? null ) && '' !== $keys['encrypted'],
        'hasPlaintextPrivateKey' => str_contains( wp_json_encode( $keys ), 'PRIVATE KEY' ) ) );
} elseif ( 'session-nonce' === $action ) {
    $user = sf_oauth_test_owner( $argv[3] ?? 0, $run );
    $input = json_decode( stream_get_contents( STDIN, 16384 ), true, 8, JSON_THROW_ON_ERROR );
    if ( ! is_string( $input['flow'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['flow'] ) || ! is_string( $input['cookie'] ?? null ) ) throw new RuntimeException( 'Invalid fixture session.' );
    $flow = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', OAuthStore::flows(), hash( 'sha256', $input['flow'] ) ), ARRAY_A );
    if ( ! $flow ) throw new RuntimeException( 'No fixture consent flow.' );
    sf_oauth_test_owner( $flow['owner_id'], $run );
    if ( wp_validate_auth_cookie( $input['cookie'], 'logged_in' ) !== (int) $user->ID ) throw new RuntimeException( 'Invalid logged-in fixture cookie.' );
    $_COOKIE[LOGGED_IN_COOKIE] = $input['cookie'];
    wp_set_current_user( $user->ID );
    echo wp_create_nonce( 'sf-oauth-consent-' . $input['flow'] );
} elseif ( 'clear-flows' === $action ) {
    foreach ( get_users( array( 'meta_key' => '_sf_test_run', 'meta_value' => $run, 'fields' => 'ID' ) ) as $owner ) {
        sf_oauth_test_owner( $owner, $run );
        $wpdb->delete( OAuthStore::flows(), array( 'owner_id' => $owner ) );
    }
    echo 'fixture-ok';
} elseif ( in_array( $action, array( 'grant', 'expire-grant', 'expire-access', 'expire-code', 'sign-access' ), true ) ) {
    $id = $argv[3] ?? '';
    if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) throw new RuntimeException( 'Invalid fixture grant ID.' );
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', Connections::table(), $id ), ARRAY_A );
    if ( ! $row || 'oauth' !== ( $row['auth_type'] ?? '' ) ) throw new RuntimeException( 'No OAuth fixture grant.' );
    sf_oauth_test_owner( $row['owner_id'], $run );
    if ( 'sign-access' === $action ) {
        $input = json_decode( stream_get_contents( STDIN, 32768 ), true, 16, JSON_THROW_ON_ERROR );
        if ( ! is_string( $input['token'] ?? null ) || ! str_starts_with( $input['token'], 'sfo_' ) || ! is_array( $input['claims'] ?? null ) ||
            array_diff( array_keys( $input['claims'] ), array( 'iss', 'aud', 'scopes', 'client_id', 'sub', 'exp' ) ) ) throw new RuntimeException( 'Invalid fixture claim mutation.' );
        $parts = explode( '.', substr( $input['token'], 4 ) );
        if ( count( $parts ) !== 3 ) throw new RuntimeException( 'Invalid fixture JWT.' );
        $claims = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true, 16, JSON_THROW_ON_ERROR );
        $record = OAuthStore::token( $claims['jti'] ?? '', 'access' );
        if ( ! $record || $record['connection_id'] !== $id || ! hash_equals( $record['credential_hash'], hash( 'sha256', $input['token'] ) ) ) throw new RuntimeException( 'Token does not belong to this fixture grant.' );
        if ( ! StructuredFields\OAuth::libraries() ) throw new RuntimeException( 'OAuth libraries unavailable.' );
        $keys = get_option( StructuredFields\OAuth::KEY_OPTION );
        if ( 1 !== openssl_verify( $parts[0] . '.' . $parts[1], base64_decode( strtr( $parts[2], '-_', '+/' ), true ), $keys['public'], OPENSSL_ALGO_SHA256 ) ) throw new RuntimeException( 'Fixture token signature is invalid.' );
        $secret = json_decode( StructuredFields\Vendor\Defuse\Crypto\Crypto::decryptWithPassword( $keys['encrypted'], wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), true, 8, JSON_THROW_ON_ERROR );
        $encode = static function( $value ) { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); };
        $payload = $parts[0] . '.' . $encode( wp_json_encode( array_replace( $claims, $input['claims'] ), JSON_THROW_ON_ERROR ) );
        if ( ! openssl_sign( $payload, $signature, $secret['private'], OPENSSL_ALGO_SHA256 ) ) throw new RuntimeException( 'Fixture signing failed.' );
        $token = 'sfo_' . $payload . '.' . $encode( $signature );
        // Bind only this disposable token: rejection must check claims, not just the credential hash.
        if ( 1 !== $wpdb->update( OAuthStore::tokens(), array( 'credential_hash' => hash( 'sha256', $token ) ), array( 'id' => $record['id'], 'kind' => 'access' ) ) ) throw new RuntimeException( 'Fixture token binding failed.' );
        echo $token;
    } elseif ( 'expire-grant' === $action ) {
        $wpdb->update( Connections::table(), array( 'expires' => time() - 1 ), array( 'id' => $id ) );
        echo 'fixture-ok';
    } elseif ( 'expire-access' === $action || 'expire-code' === $action ) {
        $wpdb->update( OAuthStore::tokens(), array( 'expires' => time() - 1 ), array( 'connection_id' => $id, 'kind' => 'expire-code' === $action ? 'code' : 'access' ) );
        echo 'fixture-ok';
    } else {
        $tokens = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE connection_id=%s ORDER BY kind,id', OAuthStore::tokens(), $id ), ARRAY_A );
        echo wp_json_encode( array( 'grant' => $row, 'tokens' => $tokens ) );
    }
} elseif ( 'publication-cas-cancel' === $action ) {
    $id = $argv[3] ?? '';
    $job = StructuredFields\Publications::read( $id );
    if ( ! $job || 'scheduled' !== $job['state'] || $job['post_id'] ) throw new RuntimeException( 'No pending fixture publication.' );
    sf_oauth_test_owner( $job['owner_id'], $run );
    $fired = false; $table = StructuredFields\Publications::table();
    $barrier = static function( $sql ) use ( &$fired, $wpdb, $table, $id, $job ) {
        if ( ! $fired && str_starts_with( $sql, 'UPDATE `' . $table . '`' ) && str_contains( $sql, "`state` = 'processing'" ) && str_contains( $sql, $wpdb->prepare( '`article_id` = %s', $id ) ) ) {
            $fired = true;
            $wpdb->update( $table, array( 'state' => 'cancelled', 'version' => (int) $job['version'] + 1 ), array( 'article_id' => $id ) );
        }
        return $sql;
    };
    add_filter( 'query', $barrier );
    try { StructuredFields\Publications::run( $id, (int) $job['version'] ); }
    finally { remove_filter( 'query', $barrier ); }
    if ( ! $fired ) throw new RuntimeException( 'Publication admission barrier was not reached.' );
    echo 'fixture-ok';
} elseif ( 'expire-flow' === $action ) {
    $id = $argv[3] ?? '';
    if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ) throw new RuntimeException( 'Invalid fixture flow ID.' );
    $id = hash( 'sha256', $id );
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', OAuthStore::flows(), $id ), ARRAY_A );
    if ( ! $row ) throw new RuntimeException( 'No fixture flow.' );
    sf_oauth_test_owner( $row['owner_id'], $run );
    $wpdb->update( OAuthStore::flows(), array( 'expires' => time() - 1 ), array( 'id' => $id ) );
    echo 'fixture-ok';
} else {
    throw new RuntimeException( 'Unknown OAuth fixture operation.' );
}
