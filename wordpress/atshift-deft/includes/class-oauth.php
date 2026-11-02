<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class OAuth {
    const NS = 'structured-fields-oauth/v1';
    const KEY_OPTION = 'atshde_oauth_server_keys';

    public static function libraries() {
        if ( PHP_VERSION_ID < 80200 ) return false;
        foreach ( array( 'filter', 'json', 'mbstring', 'openssl', 'sodium' ) as $extension ) if ( ! extension_loaded( $extension ) ) return false;
        $file = __DIR__ . '/../vendor/autoload.php';
        if ( ! is_file( $file ) || ! is_file( __DIR__ . '/oauth-repositories.php' ) ) return false;
        require_once $file;
        require_once __DIR__ . '/oauth-entities.php';
        require_once __DIR__ . '/oauth-repositories.php';
        return class_exists( Vendor\League\OAuth2\Server\AuthorizationServer::class );
    }

    public static function ready() {
        $keys = get_option( self::KEY_OPTION );
        return Connections::ready() && self::libraries() && is_array( $keys ) && isset( $keys['public'], $keys['encrypted'] );
    }

    public static function resource( $publishing = false ) { return rest_url( ltrim( $publishing ? Connections::PUBLISH_ROUTE : Connections::ROUTE, '/' ) ); }
    public static function issuer() { return home_url( '/structured-fields-oauth' ); }
    public static function authorize_url() { return admin_url( 'admin-post.php?action=atshde_oauth_authorize' ); }
    public static function endpoint( $name ) { return rest_url( self::NS . '/' . $name ); }
    public static function metadata_url() {
        $parts = wp_parse_url( self::issuer() );
        return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/.well-known/oauth-authorization-server' . ( $parts['path'] ?? '' );
    }
    public static function resource_metadata_url( $publishing = false ) { return add_query_arg( 'publishing', $publishing ? '1' : '0', self::endpoint( 'resource' ) ); }
    public static function challenge( $publishing = false ) {
        return 'Bearer resource_metadata="' . self::resource_metadata_url( $publishing ) . '", scope="sf:drafts' . ( $publishing ? ' sf:publish' : '' ) . '"';
    }

    public static function metadata() {
        return array( 'issuer' => self::issuer(), 'authorization_endpoint' => self::authorize_url(), 'token_endpoint' => self::endpoint( 'token' ),
            'revocation_endpoint' => self::endpoint( 'revoke' ), 'authorization_response_iss_parameter_supported' => true,
            'response_types_supported' => array( 'code' ), 'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
            'token_endpoint_auth_methods_supported' => array( 'none' ), 'revocation_endpoint_auth_methods_supported' => array( 'none' ),
            'code_challenge_methods_supported' => array( 'S256' ), 'scopes_supported' => array( 'sf:drafts', 'sf:authoring', 'sf:publish' ) );
    }

    public static function discovery() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only an exact path allowlist below can select this read-only public metadata route; do not normalize a different request into it.
        $path = wp_parse_url( is_string( $_SERVER['REQUEST_URI'] ?? null ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
        $canonical = wp_parse_url( self::metadata_url(), PHP_URL_PATH );
        $fallback = wp_parse_url( self::issuer(), PHP_URL_PATH ) . '/.well-known/oauth-authorization-server';
        if ( ! in_array( $path, array( $canonical, $fallback ), true ) ) return;
        nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'Access-Control-Allow-Origin: *' );
        if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) wp_send_json( array( 'error' => 'invalid_request' ), 405 );
        if ( ! Access::secure_transport() || ! self::ready() ) wp_send_json( array( 'error' => 'temporarily_unavailable' ), 503 );
        if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) { header( 'Content-Type: application/json; charset=UTF-8' ); exit; }
        wp_send_json( self::metadata() );
    }

    public static function routes() {
        register_rest_route( self::NS, '/resource', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static function( $request ) {
            if ( ! self::ready() || ! Access::secure_transport() ) return self::error( 'temporarily_unavailable', 503 );
            $value = $request->get_param( 'publishing' ) ?? '0';
            if ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) ) return self::error( 'invalid_request' );
            $publishing = '1' === $value;
            return self::response( array( 'resource' => self::resource( $publishing ), 'authorization_servers' => array( self::issuer() ),
                'scopes_supported' => $publishing ? array( 'sf:drafts', 'sf:authoring', 'sf:publish' ) : array( 'sf:drafts', 'sf:authoring' ), 'bearer_methods_supported' => array( 'header' ) ) );
        } ) );
        foreach ( array( 'token', 'revoke' ) as $name ) register_rest_route( self::NS, '/' . $name, array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, $name ) ) );
        register_rest_route( 'structured-fields/v1', '/oauth-clients', array(
            array( 'methods' => 'GET', 'permission_callback' => array( Connections::class, 'permission' ), 'callback' => array( self::class, 'listing' ) ),
            array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'admin_permission' ), 'callback' => array( self::class, 'register_client' ) ),
        ) );
        register_rest_route( 'structured-fields/v1', '/oauth-clients/(?P<id>[a-f0-9-]{36})/revoke', array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'admin_permission' ), 'callback' => array( self::class, 'disable_client' ) ) );
    }

    public static function response( $data, $status = 200 ) { return new \WP_REST_Response( $data, $status, array( 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff' ) ); }
    public static function error( $code, $status = 400 ) { return self::response( array( 'error' => $code ), $status ); }
    public static function admin_permission( $request ) { return true === Connections::permission( $request ) && current_user_can( 'manage_options' ) ? true : Access::denied(); }

    public static function listing() {
        global $wpdb;
        return array( 'available' => self::libraries(), 'configured' => self::ready(), 'secure' => Access::secure_transport(),
            'issuer' => self::issuer(), 'metadataUrl' => self::metadata_url(), 'authorizationEndpoint' => self::authorize_url(),
            'tokenEndpoint' => self::endpoint( 'token' ), 'draftEndpoint' => self::resource(), 'publishingEndpoint' => self::resource( true ),
            'canManage' => current_user_can( 'manage_options' ), 'canPublish' => Access::publisher(), 'dynamicRegistration' => false,
            'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,provider,redirect_uri,authoring,publishing,active FROM %i WHERE active=1 ORDER BY created', OAuthStore::clients() ), ARRAY_A ) );
    }

    private static function ensure_keys() {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! self::libraries() ) throw new \RuntimeException( __( 'OAuth dependencies are unavailable.', 'atshift-deft' ) );
        if ( false !== get_option( self::KEY_OPTION, false ) ) { self::keys(); return; }
        $key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $key || ! openssl_pkey_export( $key, $private ) ) throw new \RuntimeException( __( 'Signing key creation failed.', 'atshift-deft' ) );
        $details = openssl_pkey_get_details( $key );
        $payload = wp_json_encode( array( 'private' => $private, 'encryption' => Vendor\Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString() ), JSON_THROW_ON_ERROR );
        $encrypted = Vendor\Defuse\Crypto\Crypto::encryptWithPassword( $payload, wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! add_option( self::KEY_OPTION, array( 'public' => $details['key'], 'encrypted' => $encrypted ), '', false ) ) throw new \RuntimeException( __( 'Signing keys could not be stored.', 'atshift-deft' ) );
    }

    private static function keys() {
        $keys = get_option( self::KEY_OPTION );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_array( $keys ) || ! isset( $keys['encrypted'], $keys['public'] ) ) throw new \RuntimeException( __( 'OAuth is not configured.', 'atshift-deft' ) );
        // Database backups alone cannot expose the signing key without the WordPress salts.
        $payload = json_decode( Vendor\Defuse\Crypto\Crypto::decryptWithPassword( $keys['encrypted'], wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), true, 8, JSON_THROW_ON_ERROR );
        return array( 'private' => $payload['private'], 'public' => $keys['public'], 'encryption' => Vendor\Defuse\Crypto\Key::loadFromAsciiSafeString( $payload['encryption'] ) );
    }

    public static function valid_redirect( $uri ) {
        if ( ! is_string( $uri ) || strlen( $uri ) > 2048 || ! filter_var( $uri, FILTER_VALIDATE_URL ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) ) return false;
        $p = wp_parse_url( $uri );
        if ( ! $p || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) ) return false;
        if ( 'https' !== ( $p['scheme'] ?? '' ) && ! ( 'local' === wp_get_environment_type() && 'http' === ( $p['scheme'] ?? '' ) && in_array( $p['host'] ?? '', array( '127.0.0.1', 'localhost', '[::1]' ), true ) ) ) return false;
        parse_str( $p['query'] ?? '', $query );
        return ! array_intersect( array_keys( $query ), array( 'code', 'state', 'error', 'iss' ) );
    }

    public static function register_client( $request ) {
        if ( true !== self::admin_permission( $request ) ) return Access::denied();
        if ( ! self::libraries() || ! Connections::ready() || ! Access::secure_transport() ) return self::error( 'temporarily_unavailable', 503 );
        $lock = null;
        try {
            if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 4096 ) throw new \InvalidArgumentException();
            $b = json_decode( $request->get_body(), false, 8, JSON_THROW_ON_ERROR );
            Snapshot::shape( $b, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name', 'provider', 'redirectUri', 'authoring', 'publishing' ), 'properties' => array(
                'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ), 'provider' => array( 'type' => 'string', 'enum' => array( 'chatgpt', 'claude', 'gemini', 'other' ) ),
                'redirectUri' => array( 'type' => 'string', 'maxLength' => 2048 ), 'authoring' => array( 'type' => 'boolean' ), 'publishing' => array( 'type' => 'boolean' ) ) ) );
            $name = sanitize_text_field( $b->name );
            if ( '' === $name || ! self::valid_redirect( $b->redirectUri ) ) throw new \InvalidArgumentException();
            $lock = OAuthStore::lock( 0 ); global $wpdb;
            if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', OAuthStore::clients() ) ) >= 50 ) return self::error( 'client_limit', 409 );
            self::ensure_keys(); $id = wp_generate_uuid4();
            if ( false === $wpdb->insert( OAuthStore::clients(), array( 'id' => $id, 'name' => $name, 'provider' => $b->provider, 'redirect_uri' => $b->redirectUri,
                'authoring' => (int) $b->authoring, 'publishing' => (int) $b->publishing, 'created' => time() ) ) ) throw new \RuntimeException();
            return array( 'id' => $id, 'name' => $name, 'client_id' => $id, 'redirect_uri' => $b->redirectUri, 'token_endpoint_auth_method' => 'none' );
        } catch ( \InvalidArgumentException | \JsonException $e ) { return self::error( 'invalid_client_metadata' ); }
        catch ( \Throwable $e ) { return self::error( 'server_error', 503 ); }
        finally { OAuthStore::unlock( $lock ); }
    }

    public static function disable_client( $request ) {
        if ( true !== self::admin_permission( $request ) ) return Access::denied();
        global $wpdb; $client = OAuthStore::client( $request['id'] );
        if ( ! $client ) return self::error( 'invalid_client', 404 );
        if ( false === $wpdb->update( OAuthStore::clients(), array( 'active' => 0 ), array( 'id' => $client['id'] ) ) ) return self::error( 'server_error', 503 );
        $grants = $wpdb->get_results( $wpdb->prepare( "SELECT id,owner_id FROM %i WHERE oauth_client=%s AND auth_type='oauth'", Connections::table(), $client['id'] ), ARRAY_A );
        $pending = false;
        foreach ( $grants as $grant ) {
            $lock = null;
            try {
                // Match publication admission and transfer commit without taking an article lock.
                $lock = OAuthStore::lock( (int) $grant['owner_id'] );
                Publications::revoke_connection( $grant['id'] );
            } catch ( \Throwable $e ) { $pending = true; }
            finally { OAuthStore::unlock( $lock ); }
        }
        if ( $pending ) return self::error( 'client_disabled_retry_pending_cancellation', 409 );
        return array( 'revoked' => true );
    }

    public static function psr_request( $params, $method = 'POST' ) {
        $request = new Vendor\Nyholm\Psr7\ServerRequest( $method, 'GET' === $method ? self::authorize_url() : self::endpoint( 'token' ) );
        return 'GET' === $method ? $request->withQueryParams( $params ) : $request->withParsedBody( $params );
    }

    public static function server( $context ) {
        $keys = self::keys();
        $server = new Vendor\League\OAuth2\Server\AuthorizationServer( new OAuth\Clients( $context ), new OAuth\AccessTokens( $context ), new OAuth\Scopes( $context ), $keys['private'], $keys['encryption'] );
        $remaining = max( 1, min( 300, (int) $context->grant['expires'] - time() ) );
        $codes = new Vendor\League\OAuth2\Server\Grant\AuthCodeGrant( new OAuth\Codes( $context ), new OAuth\RefreshTokens( $context ), new \DateInterval( 'PT' . $remaining . 'S' ) );
        $codes->setRefreshTokenTTL( new \DateInterval( 'P30D' ) );
        $refresh = new Vendor\League\OAuth2\Server\Grant\RefreshTokenGrant( new OAuth\RefreshTokens( $context ) );
        $refresh->setRefreshTokenTTL( new \DateInterval( 'P30D' ) );
        $server->enableGrantType( $codes, new \DateInterval( 'PT10M' ) );
        $server->enableGrantType( $refresh, new \DateInterval( 'PT10M' ) );
        return $server;
    }

    public static function validate_scope( $scope, $publishing, $client ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $scope ) || strlen( $scope ) > 100 || ! preg_match( '/^sf:[a-z]+(?: sf:[a-z]+)*$/D', $scope ) ) throw new \InvalidArgumentException( __( 'Invalid scope.', 'atshift-deft' ) );
        $scopes = explode( ' ', $scope );
        if ( count( array_unique( $scopes ) ) !== count( $scopes ) || ! in_array( 'sf:drafts', $scopes, true ) ||
            array_diff( $scopes, array( 'sf:drafts', 'sf:authoring', 'sf:publish' ) ) ||
            ( in_array( 'sf:authoring', $scopes, true ) && ! $client['authoring'] ) ||
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            ( in_array( 'sf:publish', $scopes, true ) !== $publishing ) || ( $publishing && ! $client['publishing'] ) ) throw new \InvalidArgumentException( __( 'Scope is not available.', 'atshift-deft' ) );
        return $scopes;
    }

    public static function decode_form( $encoded, $allowed ) {
        // Bound work before parsing; preserve names that PHP would otherwise normalize or collapse.
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 32768 || substr_count( $encoded, '&' ) >= 32 ||
            preg_match( '/%(?![0-9a-f]{2})/i', $encoded ) ) throw new \InvalidArgumentException();
        $params = array();
        foreach ( Vendor\League\Uri\QueryString::parse( $encoded, '&', PHP_QUERY_RFC1738 ) as list( $name, $value ) ) {
            if ( '' === $name && null === $value ) continue;
            if ( ! in_array( $name, $allowed, true ) || array_key_exists( $name, $params ) ) throw new \InvalidArgumentException();
            $params[$name] = $value ?? '';
        }
        return $params;
    }

    private static function form_params( $request, $allowed ) {
        $content_type = $request->get_header( 'content-type' );
        if ( ! is_string( $content_type ) || ! preg_match( '/^application\/x-www-form-urlencoded(?:\s*;|$)/i', $content_type ) ) throw new \InvalidArgumentException();
        return self::decode_form( $request->get_body(), $allowed );
    }

    public static function token( $request ) {
        if ( ! self::ready() || ! Access::secure_transport() ) return self::error( 'temporarily_unavailable', 503 );
        $lock = null; $transaction = false;
        try {
            $p = self::form_params( $request, array( 'grant_type', 'client_id', 'code', 'redirect_uri', 'code_verifier', 'refresh_token', 'resource', 'scope' ) );
            $kind = ( $p['grant_type'] ?? '' ) === 'authorization_code' ? 'code' : ( ( $p['grant_type'] ?? '' ) === 'refresh_token' ? 'refresh' : '' );
            if ( ! $kind ) return self::error( 'unsupported_grant_type' );
            $record = OAuthStore::credential( $p['code' === $kind ? 'code' : 'refresh_token'] ?? '', $kind );
            if ( ! $record || ( $p['client_id'] ?? '' ) !== $record['client_id'] || ( $p['resource'] ?? '' ) !== $record['resource'] ) return self::error( 'invalid_grant' );
            $lock = OAuthStore::lock( (int) $record['owner_id'] );
            $record = OAuthStore::token( $record['id'], $kind );
            $grant = OAuthStore::active_grant( $record['connection_id'] );
            if ( ! $grant || (int) $record['expires'] <= time() ) return self::error( 'invalid_grant' );
            if ( $record['revoked'] ) {
                if ( 'refresh' === $kind ) self::revoke_grant( $grant['id'], (int) $grant['owner_id'] );
                return self::error( 'invalid_grant' );
            }
            $client = OAuthStore::client( $record['client_id'] );
            if ( 'code' === $kind && ( ( $p['redirect_uri'] ?? '' ) !== $client['redirect_uri'] || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $p['code_verifier'] ?? '' ) ) ) return self::error( 'invalid_grant' );
            $scopes = self::validate_scope( $p['scope'] ?? $record['scopes'], (bool) $grant['publishing'], $client );
            if ( array_diff( $scopes, explode( ' ', $record['scopes'] ) ) ) return self::error( 'invalid_scope' );
            if ( 'code' === $kind && isset( $p['scope'] ) && $p['scope'] !== $record['scopes'] ) return self::error( 'invalid_scope' );
            $context = new OAuth\Context( $grant, $record['resource'], self::issuer(), $record );
            OAuthStore::begin(); $transaction = true;
            $response = self::server( $context )->respondToAccessTokenRequest( self::psr_request( $p ), new Vendor\Nyholm\Psr7\Response() );
            $body = json_decode( (string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR );
            OAuthStore::bind_credential( $context->issuedAccessId, 'access', $body['access_token'] );
            if ( isset( $body['refresh_token'] ) ) OAuthStore::bind_credential( $context->issuedRefreshId, 'refresh', $body['refresh_token'] );
            if ( ! OAuthStore::active_grant( $grant['id'] ) ) throw new \RuntimeException( __( 'Consent was revoked.', 'atshift-deft' ) );
            OAuthStore::commit(); $transaction = false;
            return self::response( $body );
        } catch ( Vendor\League\OAuth2\Server\Exception\OAuthServerException $e ) { return self::error( $e->getErrorType(), $e->getHttpStatusCode() ); }
        catch ( \InvalidArgumentException | \JsonException $e ) { return self::error( 'invalid_request' ); }
        catch ( \Throwable $e ) { return self::error( 'server_error', 503 ); }
        finally { if ( $transaction ) OAuthStore::rollback(); OAuthStore::unlock( $lock ); }
    }

    // Caller holds the owner mutex. Credential rotation never changes this grant identity.
    public static function revoke_grant( $id, $owner ) {
        global $wpdb;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( false === $wpdb->delete( Connections::table(), array( 'id' => $id, 'owner_id' => $owner, 'auth_type' => 'oauth' ) ) ) throw new \RuntimeException( __( 'Revocation failed.', 'atshift-deft' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( false === $wpdb->update( OAuthStore::tokens(), array( 'revoked' => 1 ), array( 'connection_id' => $id ) ) ) throw new \RuntimeException( __( 'Token revocation failed.', 'atshift-deft' ) );
        Publications::revoke_connection( $id );
    }

    public static function revoke( $request ) {
        if ( ! self::ready() || ! Access::secure_transport() ) return self::error( 'temporarily_unavailable', 503 );
        $lock = null;
        try {
            $p = self::form_params( $request, array( 'token', 'client_id', 'token_type_hint' ) );
            $record = OAuthStore::credential( $p['token'] ?? '', 'refresh' ) ?: OAuthStore::credential( $p['token'] ?? '', 'access' );
            if ( $record && ( $p['client_id'] ?? '' ) === $record['client_id'] ) {
                $lock = OAuthStore::lock( (int) $record['owner_id'] ); self::revoke_grant( $record['connection_id'], (int) $record['owner_id'] );
            }
            return self::response( new \stdClass() );
        } catch ( \InvalidArgumentException $e ) { return self::error( 'invalid_request' ); }
        catch ( \Throwable $e ) { return self::error( 'server_error', 503 ); }
        finally { OAuthStore::unlock( $lock ); }
    }

    public static function authenticate( $header, $resource ) {
        try {
            if ( ! self::ready() || ! preg_match( '/^Bearer (sfo_[A-Za-z0-9_.-]+)$/Di', $header, $m ) || strlen( $m[1] ) > 16384 ) return null;
            $raw = substr( $m[1], 4 );
            $parsed = ( new Vendor\Lcobucci\JWT\Token\Parser( new Vendor\Lcobucci\JWT\Encoding\JoseEncoder() ) )->parse( $raw );
            $id = $parsed->claims()->get( 'jti', '' );
            if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{80}$/D', $id ) ) return null;
            $record = OAuthStore::token( $id, 'access' );
            $grant = $record ? OAuthStore::active_grant( $record['connection_id'] ) : null;
            if ( ! $grant || $record['revoked'] || (int) $record['expires'] <= time() || $record['resource'] !== $resource || ! hash_equals( $record['credential_hash'], hash( 'sha256', $m[1] ) ) ) return null;
            $context = new OAuth\Context( $grant, $resource, self::issuer(), $record );
            $keys = get_option( self::KEY_OPTION );
            $server = new Vendor\League\OAuth2\Server\ResourceServer( new OAuth\AccessTokens( $context ), $keys['public'] );
            $request = $server->validateAuthenticatedRequest( ( new Vendor\Nyholm\Psr7\ServerRequest( 'POST', $resource ) )->withHeader( 'Authorization', 'Bearer ' . $raw ) );
            $scopes = $request->getAttribute( 'oauth_scopes' );
            if ( $parsed->claims()->get( 'iss', '' ) !== self::issuer() || $parsed->claims()->get( 'aud', array() ) !== array( $resource ) ||
                $parsed->claims()->get( 'client_id', '' ) !== $record['client_id'] || $request->getAttribute( 'oauth_user_id' ) !== (string) $grant['owner_id'] ||
                ! is_array( $scopes ) || $scopes !== explode( ' ', $record['scopes'] ) || array_diff( $scopes, OAuthStore::scopes( $grant ) ) ) return null;
            $grant['authoring'] = in_array( 'sf:authoring', $scopes, true ) ? 1 : 0;
            $grant['publishing'] = in_array( 'sf:publish', $scopes, true ) ? 1 : 0;
            $grant['_oauth_access_id'] = $id; $grant['_oauth_scopes'] = $scopes;
            return $grant;
        } catch ( \Throwable $e ) { return null; }
    }

    public static function authorized( $row ) {
        $grant = OAuthStore::active_grant( $row['id'] );
        $token = OAuthStore::token( $row['_oauth_access_id'] ?? '', 'access' );
        return $grant && $token && ! $token['revoked'] && (int) $token['expires'] > time() && $token['connection_id'] === $grant['id'] &&
            ! array_diff( $row['_oauth_scopes'], OAuthStore::scopes( $grant ) );
    }
}
