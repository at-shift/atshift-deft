<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

/** Private binary storage; no publicly addressable file is created. */
final class PrivateBlobs {
    const CHUNK_BYTES = 65536;

    public static function table() { global $wpdb; return $wpdb->prefix . 'atshde_blob_chunks'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            kind varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            identity char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            piece smallint unsigned NOT NULL,
            payload mediumblob NOT NULL,
            PRIMARY KEY  (kind,identity,piece)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        if ( self::available() ) update_option( 'atshde_blob_schema_version', 1, false );
    }

    public static function available() {
        global $wpdb;
        return 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table() ) );
    }

    private static function key( $kind, $identity ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( ! in_array( $kind, array( 'asset', 'publication' ), true ) || ! is_string( $identity ) || '' === $identity || strlen( $identity ) > 160 ) throw new \InvalidArgumentException( __( 'Invalid private storage identity.', 'atshift-deft' ) );
        return hash( 'sha256', $identity );
    }

    public static function get( $kind, $identity, $meta ) {
        global $wpdb;
        $identity = self::key( $kind, $identity );
        $size = (int) ( $meta['bytes'] ?? 0 );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( $size < 1 || $size > Assets::MAX_BYTES || ! is_string( $meta['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $meta['sha256'] ) ) throw new \RuntimeException( __( 'Invalid private media metadata.', 'atshift-deft' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT piece,payload FROM %i WHERE kind=%s AND identity=%s ORDER BY piece LIMIT %d', self::table(), $kind, $identity, (int) ceil( Assets::MAX_BYTES / self::CHUNK_BYTES ) + 1 ), ARRAY_A );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( null === $rows || $wpdb->last_error ) throw new \RuntimeException( __( 'Private media storage unavailable.', 'atshift-deft' ) );
        if ( ! $rows ) return null;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( count( $rows ) !== (int) ceil( $size / self::CHUNK_BYTES ) ) throw new \RuntimeException( __( 'Incomplete private media.', 'atshift-deft' ) );
        $bytes = '';
        foreach ( $rows as $index => $row ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
            if ( (int) $row['piece'] !== $index || strlen( $row['payload'] ) !== min( self::CHUNK_BYTES, $size - strlen( $bytes ) ) ) throw new \RuntimeException( __( 'Incomplete private media.', 'atshift-deft' ) );
            $bytes .= $row['payload'];
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( strlen( $bytes ) !== $size || ! hash_equals( $meta['sha256'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Private media integrity check failed.', 'atshift-deft' ) );
        return $bytes;
    }

    public static function put( $kind, $identity, $bytes, $meta, $capacity = Assets::SITE_BYTES ) {
        global $wpdb;
        $key = self::key( $kind, $identity );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( ! is_string( $bytes ) || strlen( $bytes ) < 1 || strlen( $bytes ) > Assets::MAX_BYTES || strlen( $bytes ) !== (int) ( $meta['bytes'] ?? 0 ) || ! hash_equals( (string) ( $meta['sha256'] ?? '' ), hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Private media integrity check failed.', 'atshift-deft' ) );
        $lock = 'atshde_blob_' . substr( hash( 'sha256', DB_NAME . ':' . self::table() ), 0, 48 );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) throw new \RuntimeException( __( 'Private media storage is busy.', 'atshift-deft' ) );
        $writing = false;
        try {
            if ( null !== self::get( $kind, $identity, $meta ) ) return;
            $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(OCTET_LENGTH(payload)),0) FROM %i WHERE kind=%s', self::table(), $kind ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
            if ( ! is_int( $capacity ) || $capacity < 0 || $capacity > Assets::SITE_BYTES || null === $total || (int) $total + strlen( $bytes ) > $capacity ) throw new \RuntimeException( __( 'Private media capacity reached.', 'atshift-deft' ) );
            $writing = true;
            for ( $offset = 0, $piece = 0; $offset < strlen( $bytes ); $offset += self::CHUNK_BYTES, ++$piece ) {
                // Hex transport avoids database text-charset conversion of arbitrary image/PDF bytes.
                $hex = bin2hex( substr( $bytes, $offset, self::CHUNK_BYTES ) );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
                if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (kind,identity,piece,payload) VALUES (%s,%s,%d,UNHEX(%s))', self::table(), $kind, $key, $piece, $hex ) ) ) throw new \RuntimeException( __( 'Private media could not be saved.', 'atshift-deft' ) );
            }
            self::get( $kind, $identity, $meta );
            $writing = false;
        } finally {
            if ( $writing ) self::delete( $kind, $identity );
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function delete( $kind, $identity ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'kind' => $kind, 'identity' => self::key( $kind, $identity ) ) );
    }
}
