<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class OAuthStore {
    public static function clients() { global $wpdb; return $wpdb->prefix . 'atshde_oauth_clients'; }
    public static function tokens() { global $wpdb; return $wpdb->prefix . 'atshde_oauth_tokens'; }
    public static function flows() { global $wpdb; return $wpdb->prefix . 'atshde_oauth_flows'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $clients = self::clients(); $tokens = self::tokens(); $flows = self::flows(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $clients (
            id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name varchar(80) NOT NULL,
            provider varchar(24) NOT NULL,
            redirect_uri text NOT NULL,
            authoring tinyint(1) unsigned NOT NULL DEFAULT 1,
            publishing tinyint(1) unsigned NOT NULL DEFAULT 0,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $tokens (
            id char(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            kind varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            connection_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            resource text NOT NULL,
            scopes varchar(100) NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            revoked tinyint(1) unsigned NOT NULL DEFAULT 0,
            access_id char(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            credential_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            created bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id,kind),
            KEY connection_id (connection_id),
            KEY credential_hash (credential_hash),
            KEY expires (expires)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $flows (
            id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            session_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload text NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_id (owner_id),
            KEY expires (expires)
        ) ENGINE=InnoDB $charset;" );
        foreach ( array( $clients, $tokens, $flows ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return;
        }
        update_option( 'atshde_oauth_schema_version', 1, false );
    }

    public static function client( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', self::clients(), $id ), ARRAY_A );
    }

    public static function active_grant( $id, $ignore_expiry = false ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT c.* FROM %i c INNER JOIN %i o ON c.oauth_client=o.id WHERE c.id=%s AND c.auth_type='oauth' AND o.active=1", Connections::table(), self::clients(), $id ), ARRAY_A );
        if ( ! $row || ( ! $ignore_expiry && (int) $row['expires'] <= time() ) || ! Access::workspace( (int) $row['owner_id'] ) ||
            ( $row['publishing'] && ! Access::publisher( (int) $row['owner_id'] ) ) ) return null;
        return $row;
    }

    public static function scopes( $grant ) {
        return array_merge( array( 'sf:drafts' ), empty( $grant['authoring'] ) ? array() : array( 'sf:authoring' ), empty( $grant['publishing'] ) ? array() : array( 'sf:publish' ) );
    }

    public static function token( $id, $kind ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND kind=%s', self::tokens(), $id, $kind ), ARRAY_A );
    }

    public static function credential( $value, $kind ) {
        if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 16384 ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE credential_hash=%s AND kind=%s', self::tokens(), hash( 'sha256', $value ), $kind ), ARRAY_A );
    }

    public static function save_token( $id, $kind, $grant, $resource, $scopes, $expiry, $access_id = '' ) {
        global $wpdb;
        $expires = $expiry instanceof \DateTimeInterface ? $expiry->getTimestamp() : (int) $expiry;
        if ( ! in_array( $kind, array( 'code', 'access', 'refresh' ), true ) || ! preg_match( '/^[a-f0-9]{80}$/D', $id ) ||
            ! self::active_grant( $grant['id'] ) || $expires > (int) $grant['expires'] || $expires <= time() ||
            array_diff( $scopes, self::scopes( $grant ) ) || ! in_array( 'sf:drafts', $scopes, true ) ||
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            OAuth::resource( (bool) $grant['publishing'] ) !== $resource ) throw new \RuntimeException( __( 'Invalid authorization record.', 'atshift-deft' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE connection_id=%s', self::tokens(), $grant['id'] ) ) >= 10000 ) throw new \RuntimeException( __( 'Reconnect to renew the token quota.', 'atshift-deft' ) );
        if ( false === $wpdb->insert( self::tokens(), array( 'id' => $id, 'kind' => $kind, 'connection_id' => $grant['id'], 'client_id' => $grant['oauth_client'],
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            'owner_id' => $grant['owner_id'], 'resource' => $resource, 'scopes' => implode( ' ', $scopes ), 'expires' => $expires, 'access_id' => $access_id, 'created' => time() ) ) ) throw new \RuntimeException( __( 'Authorization record could not be stored.', 'atshift-deft' ) );
    }

    public static function bind_credential( $id, $kind, $credential ) {
        global $wpdb;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $credential ) || '' === $credential || 1 !== $wpdb->update( self::tokens(), array( 'credential_hash' => hash( 'sha256', $credential ) ), array( 'id' => $id, 'kind' => $kind, 'credential_hash' => '' ) ) ) throw new \RuntimeException( __( 'Credential could not be bound.', 'atshift-deft' ) );
    }

    public static function revoke_token( $id, $kind ) {
        global $wpdb;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( false === $wpdb->update( self::tokens(), array( 'revoked' => 1 ), array( 'id' => $id, 'kind' => $kind ) ) ) throw new \RuntimeException( __( 'Token revocation failed.', 'atshift-deft' ) );
    }

    public static function begin() {
        global $wpdb;
        foreach ( array( self::clients(), self::tokens(), self::flows(), Connections::table() ) as $table ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) throw new \RuntimeException( __( 'OAuth requires transactional storage.', 'atshift-deft' ) );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) throw new \RuntimeException( __( 'Authorization transaction unavailable.', 'atshift-deft' ) );
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
    public static function commit() { global $wpdb; if ( false === $wpdb->query( 'COMMIT' ) ) throw new \RuntimeException( __( 'Authorization transaction failed.', 'atshift-deft' ) ); }
    public static function rollback() { global $wpdb; $wpdb->query( 'ROLLBACK' ); }

    public static function lock( $owner ) {
        global $wpdb; $name = Connections::lock_name( $owner );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $name ) ) ) throw new \RuntimeException( __( 'Authorization is busy. Try again.', 'atshift-deft' ) );
        return $name;
    }
    public static function unlock( $name ) { global $wpdb; if ( $name ) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }

    public static function cleanup() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires<%d', self::flows(), time() ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires<%d', self::tokens(), time() - DAY_IN_SECONDS ) );
    }

    public static function delete_user( $owner ) {
        global $wpdb;
        $wpdb->delete( self::tokens(), array( 'owner_id' => $owner ) );
        $wpdb->delete( self::flows(), array( 'owner_id' => $owner ) );
    }
}
