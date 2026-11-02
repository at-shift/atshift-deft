<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class Connections {
    const ROUTE = '/structured-fields-mcp/v1/mcp';
    const PUBLISH_ROUTE = '/structured-fields-mcp/v1/publishing';
    private static $authenticated = null;
    private static $previous_user = null;
    private static $active_request = null;

    public static function table() { global $wpdb; return $wpdb->prefix . 'atshde_connections'; }
    public static function lock_name( $owner ) { return substr( 'sf_conn_' . hash( 'sha256', DB_NAME . ':' . self::table() . ':' . $owner ), 0, 64 ); }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            name varchar(80) NOT NULL,
            secret_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            last_used bigint(20) unsigned NOT NULL DEFAULT 0,
            publishing tinyint(1) unsigned NOT NULL DEFAULT 0,
            authoring tinyint(1) unsigned NOT NULL DEFAULT 0,
            auth_type varchar(8) NOT NULL DEFAULT 'key',
            oauth_client char(36) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY owner_id (owner_id),
            KEY oauth_client (oauth_client)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        update_option( 'atshde_connections_schema_version', 4, false );
    }

    public static function ready() {
        return function_exists( 'wp_register_ability' ) && defined( 'WP_MCP_VERSION' ) &&
            version_compare( WP_MCP_VERSION, '0.7.1', '>=' ) && class_exists( '\WP\MCP\Core\McpAdapter' );
    }

    public static function secure() {
        return Access::secure_transport();
    }

    public static function permission( $request ) {
        return Access::workspace() && wp_get_session_token() && Access::verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ? true : Access::denied();
    }

    public static function listing() {
        global $wpdb; $table = self::table();
        return array( 'ready' => self::ready(), 'secure' => self::secure(), 'endpoint' => rest_url( ltrim( self::ROUTE, '/' ) ),
            'publishingEndpoint' => rest_url( ltrim( self::PUBLISH_ROUTE, '/' ) ), 'canCreatePublishingKey' => Access::publisher(),
            'scope' => array( 'catalog:read', 'own-drafts:read', 'own-drafts:write' ), 'publish' => false,
            'items' => $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,created,expires,last_used,publishing,authoring,auth_type,oauth_client FROM %i WHERE owner_id=%d ORDER BY created DESC', $table, get_current_user_id() ), ARRAY_A ) );
    }

    public static function create( $request ) {
        if ( true !== self::permission( $request ) ) return Access::denied();
        if ( ! self::ready() || ! self::secure() ) return new \WP_Error( 'sf_connection_unavailable', __( 'Activate MCP Adapter 0.7.1 or newer and use HTTPS (local loopback is allowed).', 'atshift-deft' ), array( 'status' => 503 ) );
        try {
            if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 1024 ) throw new \InvalidArgumentException();
            $body = json_decode( $request->get_body(), false, 8, JSON_THROW_ON_ERROR );
            Snapshot::shape( $body, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name' ),
                'properties' => array( 'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ), 'publishing' => array( 'type' => 'boolean' ), 'authoring' => array( 'type' => 'boolean' ) ) ) );
            $name = sanitize_text_field( $body->name );
            if ( '' === $name ) throw new \InvalidArgumentException();
            $publishing = $body->publishing ?? false;
            if ( $publishing && ! Access::publisher() ) return Access::denied();
        } catch ( \Throwable $e ) { return new \WP_Error( 'sf_connection_name', __( 'Supply a connection name of 1 to 80 characters.', 'atshift-deft' ), array( 'status' => 400 ) ); }
        global $wpdb; $table = self::table(); $owner = get_current_user_id();
        $lock = self::lock_name( $owner );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Try again.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            $publications = Publications::table();
            // Retain expired issuers while their already-authorized reservations are pending.
            $wpdb->query( $wpdb->prepare( "DELETE c FROM %i c WHERE c.owner_id=%d AND c.expires<=%d AND NOT EXISTS (SELECT 1 FROM %i p WHERE p.connection_id=c.id AND p.state IN ('scheduled','processing'))", $table, $owner, time(), $publications ) );
            if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_id=%d AND expires>%d', $table, $owner, time() ) ) >= 10 ) return new \WP_Error( 'sf_connection_limit', __( 'Revoke an existing connection first (maximum 10 active).', 'atshift-deft' ), array( 'status' => 409 ) );
            $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) ); $expires = time() + 30 * DAY_IN_SECONDS;
            if ( false === $wpdb->insert( $table, array( 'id' => $id, 'owner_id' => $owner, 'name' => $name,
                'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => $expires, 'publishing' => (int) $publishing, 'authoring' => (int) ( $body->authoring ?? false ) ) ) ) throw new \RuntimeException();
            return array( 'id' => $id, 'token' => 'sfc_' . $id . '_' . $secret, 'expires' => $expires,
                'publishing' => $publishing, 'authoring' => (bool) ( $body->authoring ?? false ), 'endpoint' => rest_url( ltrim( $publishing ? self::PUBLISH_ROUTE : self::ROUTE, '/' ) ) );
        } catch ( \Throwable $e ) { return new \WP_Error( 'sf_connection_storage', __( 'Connection could not be created.', 'atshift-deft' ), array( 'status' => 500 ) ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function revoke( $request ) {
        if ( true !== self::permission( $request ) ) return Access::denied();
        global $wpdb; $lock = self::lock_name( get_current_user_id() );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Connection is busy. Retry revocation.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            $deleted = $wpdb->delete( self::table(), array( 'id' => $request['id'], 'owner_id' => get_current_user_id() ) );
            if ( $deleted ) Publications::revoke_connection( $request['id'] );
            return $deleted ? array( 'revoked' => true ) : new \WP_Error( 'sf_missing', __( 'Connection not found.', 'atshift-deft' ), array( 'status' => 404 ) );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function delete_user( $id ) { global $wpdb; $wpdb->delete( self::table(), array( 'owner_id' => $id ) ); }

    public static function guard( $result, $server, $request ) {
        // A tool's scoped owner context must not authorize a nested/core REST request.
        if ( null !== self::$active_request ) return new \WP_Error( 'sf_connection_scope', __( 'Nested requests are not permitted in this AI connection.', 'atshift-deft' ), array( 'status' => 403 ) );
        $header = $request->get_header( 'authorization' ) ?? '';
        $target = in_array( $request->get_route(), array( self::ROUTE, self::PUBLISH_ROUTE ), true );
        if ( ! $target && ! preg_match( '/^Bearer\s+sf[co]_/i', $header ) ) return $result;
        self::$authenticated = null;
        if ( ! $target ) return new \WP_Error( 'sf_connection_scope', __( 'This key is only valid at the atshift DeFt MCP endpoint.', 'atshift-deft' ), array( 'status' => 403 ) );
        if ( null !== $result ) return $result;
        if ( ! self::ready() || ! self::secure() ) return new \WP_Error( 'sf_connection_unavailable', __( 'Secure MCP connection unavailable.', 'atshift-deft' ), array( 'status' => 503 ) );
        if ( strlen( $request->get_body() ?? '' ) > 4 * 1024 * 1024 ) return new \WP_Error( 'sf_size', __( 'Payload exceeds 4 MB.', 'atshift-deft' ), array( 'status' => 413 ) );
        global $wpdb; $table = self::table();
        if ( preg_match( '/^Bearer\s+sfo_/i', $header ) ) {
            $row = OAuth::authenticate( $header, OAuth::resource( self::PUBLISH_ROUTE === $request->get_route() ) );
            if ( ! $row ) return self::unauthorized();
        } else {
            if ( ! preg_match( '/^Bearer sfc_([a-f0-9-]{36})_([a-f0-9]{64})$/D', $header, $match ) ) return self::unauthorized();
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $table, $match[1] ), ARRAY_A );
            if ( ! $row || 'key' !== $row['auth_type'] || (int) $row['expires'] <= time() || ! hash_equals( $row['secret_hash'], hash( 'sha256', $match[2] ) ) || ! Access::workspace( (int) $row['owner_id'] ) ) return self::unauthorized();
        }
        if ( ( self::PUBLISH_ROUTE === $request->get_route() ) !== (bool) $row['publishing'] ||
            ( $row['publishing'] && ! Access::publisher( (int) $row['owner_id'] ) ) ) return self::unauthorized();
        self::$previous_user = get_current_user_id();
        wp_set_current_user( (int) $row['owner_id'] );
        self::$authenticated = $row;
        self::$active_request = $request;
        $wpdb->update( $table, array( 'last_used' => time() ), array( 'id' => $row['id'] ) );
        return $result;
    }

    private static function unauthorized() { return new \WP_Error( 'sf_connection_auth', __( 'A valid, unexpired connection credential is required.', 'atshift-deft' ), array( 'status' => 401 ) ); }

    public static function authorized() {
        if ( ! self::$authenticated || ! Access::workspace() || get_current_user_id() !== (int) self::$authenticated['owner_id'] ) return false;
        if ( 'oauth' === self::$authenticated['auth_type'] ) return OAuth::authorized( self::$authenticated );
        global $wpdb; $table = self::table();
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%s AND expires>%d', $table, self::$authenticated['id'], time() ) );
    }

    public static function can_publish() { return self::authorized() && ! empty( self::$authenticated['publishing'] ) && Access::publisher(); }
    public static function can_author() { return self::authorized() && ! empty( self::$authenticated['authoring'] ); }
    public static function authoring_connection_id() { return self::can_author() ? self::$authenticated['id'] : ''; }
    public static function active_authoring( $id, $owner ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND owner_id=%d AND authoring=1 AND expires>%d', self::table(), $id, $owner, time() ), ARRAY_A );
        return Access::workspace( $owner ) && $row && ( 'key' === $row['auth_type'] || ( 'oauth' === $row['auth_type'] && OAuthStore::active_grant( $id ) ) );
    }
    public static function active_publishing_issuer( $id, $owner ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND owner_id=%d AND publishing=1', self::table(), $id, $owner ), ARRAY_A );
        // Expiry ends new requests; explicit revocation also cancels previously authorized reservations.
        return $row && ( 'key' === $row['auth_type'] || ( 'oauth' === $row['auth_type'] && OAuthStore::active_grant( $id, true ) ) );
    }
    public static function publishing_connection_id() {
        if ( null === self::$authenticated ) return '';
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! self::can_publish() ) throw new \RuntimeException( __( 'The publishing connection is no longer authorized.', 'atshift-deft' ) );
        return self::$authenticated['id'];
    }

    public static function finish( $response, $server, $request ) {
        if ( in_array( $request->get_route(), array( self::ROUTE, self::PUBLISH_ROUTE ), true ) ) {
            if ( self::$active_request === $request ) {
                if ( null !== self::$previous_user ) wp_set_current_user( self::$previous_user );
                self::$authenticated = self::$previous_user = self::$active_request = null;
            }
            $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
            $response->header( 'X-Robots-Tag', 'noindex, nofollow' );
            if ( 401 === $response->get_status() && OAuth::ready() ) $response->header( 'WWW-Authenticate', OAuth::challenge( self::PUBLISH_ROUTE === $request->get_route() ) );
        }
        return $response;
    }
}
