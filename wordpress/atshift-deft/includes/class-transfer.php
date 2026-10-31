<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class Transfer {
    public static function table() { global $wpdb; return $wpdb->prefix . 'sf_transfers'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            transfer_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            article_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            version bigint(20) unsigned NOT NULL,
            digest char(64) NOT NULL,
            token_hash char(64) NOT NULL,
            filename varchar(255) NOT NULL,
            mime varchar(32) NOT NULL,
            bytes bigint(20) unsigned NOT NULL,
            sha256 char(64) NOT NULL,
            purpose varchar(16) NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            connection_id varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            PRIMARY KEY  (transfer_id),
            KEY article_id (article_id)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) update_option( 'sf_transfers_schema_version', 2, false );
    }

    public static function secure_transport() {
        return Access::secure_transport();
    }

    public static function issue( $request, $connection_id = '' ) {
        global $wpdb;
        if ( true !== Assets::permission() || ! Access::article( $request['id'] ) ) return Access::denied();
        if ( '' !== $connection_id && ( ! Connections::can_author() || $connection_id !== Connections::authoring_connection_id() ) ) return Access::denied();
        if ( ! self::secure_transport() ) return new \WP_Error( 'sf_https', __( 'Transfer credentials require HTTPS.', 'atshift-deft' ), array( 'status' => 403 ) );
        try {
            if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 4096 ) throw new \InvalidArgumentException( __( 'Use a small JSON transfer manifest.', 'atshift-deft' ) );
            $b = json_decode( $request->get_body(), true, 16, JSON_THROW_ON_ERROR );
            $keys = array( 'expectedVersion', 'filename', 'mime', 'bytes', 'sha256', 'purpose' );
            if ( ! is_array( $b ) || array_diff( array_keys( $b ), $keys ) || array_diff( $keys, array_keys( $b ) ) ||
                ! is_int( $b['expectedVersion'] ) || ! is_int( $b['bytes'] ) || $b['bytes'] < 1 || $b['bytes'] > min( Assets::MAX_BYTES, wp_max_upload_size() ) ||
                ! is_string( $b['filename'] ) || ! $b['filename'] || strlen( $b['filename'] ) > 255 || sanitize_file_name( $b['filename'] ) !== $b['filename'] ||
                ! is_string( $b['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $b['sha256'] ) ||
                ! in_array( $b['purpose'], array( 'reference', 'publication' ), true ) || ! in_array( $b['mime'], Assets::MIMES, true ) ||
                wp_check_filetype( $b['filename'], Assets::MIMES )['type'] !== $b['mime'] ) throw new \InvalidArgumentException( __( 'Invalid transfer manifest.', 'atshift-deft' ) );
            if ( ! Assets::available() ) return new \WP_Error( 'sf_storage', __( 'Private storage unavailable.', 'atshift-deft' ), array( 'status' => 503 ) );
            $row = Store::read( $request['id'] );
            if ( ! $row || (int) $row['version'] !== $b['expectedVersion'] ) return new \WP_Error( 'sf_conflict', __( 'Reload the current article version.', 'atshift-deft' ), array( 'status' => 409 ) );
            $table = self::table();
            $lock = 'sf_transfer_' . substr( hash( 'sha256', DB_NAME . ':' . $table ), 0, 48 );
            if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
            try {
                // Expired credentials have no further authority and need not accumulate forever.
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires < %d', $table, time() ) );
                if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_by=%d AND revoked=0', $table, get_current_user_id() ) ) >= 20 ) return new \WP_Error( 'sf_limit', __( 'Too many active transfers for this account. Finish existing transfers or wait for expiry.', 'atshift-deft' ), array( 'status' => 429 ) );
                if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) >= 100 ) return new \WP_Error( 'sf_limit', __( 'Too many active transfers.', 'atshift-deft' ), array( 'status' => 429 ) );
                $id = wp_generate_uuid4(); $token = bin2hex( random_bytes( 32 ) ); $expires = time() + 15 * MINUTE_IN_SECONDS;
                $record = array( 'transfer_id' => $id, 'article_id' => $request['id'], 'version' => $row['version'], 'digest' => $row['digest'],
                    'token_hash' => hash( 'sha256', $token ), 'filename' => $b['filename'], 'mime' => $b['mime'], 'bytes' => $b['bytes'],
                    'sha256' => $b['sha256'], 'purpose' => $b['purpose'], 'created_by' => get_current_user_id(), 'expires' => $expires, 'connection_id' => $connection_id );
                if ( false === $wpdb->insert( $table, $record ) ) throw new \RuntimeException( __( 'Could not save transfer.', 'atshift-deft' ) );
                return array( 'transferId' => $id, 'uploadUrl' => rest_url( 'structured-fields/v1/transfers/' . $id ), 'token' => $token,
                    'articleId' => $request['id'], 'version' => (int) $row['version'], 'file' => array_diff_key( $b, array( 'expectedVersion' => true ) ),
                    'expiresAt' => gmdate( 'c', $expires ), 'publishAuthorized' => false );
            } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
        } catch ( \Throwable $e ) { return new \WP_Error( 'sf_transfer_input', $e->getMessage(), array( 'status' => 400 ) ); }
    }

    public static function authenticate( $request ) {
        global $wpdb;
        $token = $request->get_header( 'x-sf-transfer-token' );
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE transfer_id=%s', $table, $request['transfer'] ), ARRAY_A );
        if ( ! self::secure_transport() || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ||
            ! $row || $row['revoked'] || (int) $row['expires'] <= time() || ! hash_equals( $row['token_hash'], hash( 'sha256', $token ) ) ||
            ! Access::article( $row['article_id'], (int) $row['created_by'] ) || ! user_can( (int) $row['created_by'], 'upload_files' ) ||
            ( ! empty( $row['connection_id'] ) && ! Connections::active_authoring( $row['connection_id'], (int) $row['created_by'] ) ) ) {
            return new \WP_Error( 'sf_transfer_denied', __( 'Transfer is invalid, revoked or expired.', 'atshift-deft' ), array( 'status' => 403 ) );
        }
        return $row;
    }

    public static function receive( $request ) {
        global $wpdb;
        $grant = self::authenticate( $request ); if ( is_wp_error( $grant ) ) return $grant;
        $lock = Store::lock_name( $grant['article_id'] );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
        $owner_lock = null;
        try {
            if ( ! empty( $grant['connection_id'] ) ) {
                $owner_lock = Connections::lock_name( (int) $grant['created_by'] );
                if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $owner_lock ) ) ) { $owner_lock = null; return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) ); }
            }
            $grant = self::authenticate( $request ); if ( is_wp_error( $grant ) ) return $grant;
            $current = Store::read( $grant['article_id'] );
            if ( ! $current || $current['version'] !== $grant['version'] || ! hash_equals( $current['digest'], $grant['digest'] ) ) return new \WP_Error( 'sf_conflict', __( 'The article changed; request a new transfer.', 'atshift-deft' ), array( 'status' => 409 ) );
            $request->set_url_params( array( 'id' => $grant['article_id'] ) );
            $result = Assets::receive( $request, $grant );
            if ( is_wp_error( $result ) ) return $result;
            unset( $result['contentUrl'] );
            return $result;
        } finally {
            if ( $owner_lock ) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $owner_lock ) );
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function revoke( $request ) {
        global $wpdb; $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE transfer_id=%s', $table, $request['transfer'] ), ARRAY_A );
        if ( ! $row ) return new \WP_Error( 'sf_missing', __( 'Transfer not found.', 'atshift-deft' ), array( 'status' => 404 ) );
        if ( true !== Assets::permission() || ! Access::article( $row['article_id'] ) ) return Access::denied();
        $lock = Store::lock_name( $row['article_id'] );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            if ( false === $wpdb->update( $table, array( 'revoked' => 1 ), array( 'transfer_id' => $request['transfer'] ) ) ) return new \WP_Error( 'sf_storage', __( 'Could not revoke transfer.', 'atshift-deft' ), array( 'status' => 503 ) );
            return array( 'revoked' => true );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
}
