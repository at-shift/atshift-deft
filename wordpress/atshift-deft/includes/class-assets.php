<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class Assets {
    const MAX_BYTES = 10 * 1024 * 1024;
    const ARTICLE_BYTES = 100 * 1024 * 1024;
    const SITE_BYTES = 500 * 1024 * 1024;
    const MIMES = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' );

    public static function user_limit( $actor ) {
        // Retain existing administrator policies through the legacy filter alias.
        $limit = apply_filters( 'atshde_private_media_limit_per_user', apply_filters( 'sf_private_media_limit_per_user', self::ARTICLE_BYTES, $actor ), $actor );
        return is_int( $limit ) && $limit >= 0 ? min( self::SITE_BYTES, $limit ) : self::ARTICLE_BYTES;
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'atshde_assets';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            asset_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            article_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            purpose varchar(16) NOT NULL,
            sha256 char(64) NOT NULL,
            filename varchar(255) NOT NULL,
            mime varchar(32) NOT NULL,
            bytes bigint(20) unsigned NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (asset_id),
            UNIQUE KEY same_input (article_id,sha256,purpose)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            update_option( 'atshde_assets_schema_version', 1, false );
        }
    }

    public static function permission() {
        return Access::workspace() && current_user_can( 'upload_files' ) ? true : Access::denied();
    }

    // A private attachment status or .htaccess alone does not protect file bytes.
    public static function validate_storage_path( $path, $roots ) {
        $real = is_string( $path ) ? realpath( $path ) : false;
        if ( ! $real || ! is_dir( $real ) || ! is_readable( $real ) || wp_normalize_path( $path ) !== wp_normalize_path( $real ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \RuntimeException( __( 'Configure an existing canonical private directory.', 'atshift-deft' ) );
        }
        $base = trailingslashit( wp_normalize_path( $real ) );
        foreach ( $roots as $root ) {
            $public = realpath( $root );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! $public ) throw new \RuntimeException( __( 'Cannot verify the public document root.', 'atshift-deft' ) );
            $public = trailingslashit( wp_normalize_path( $public ) );
            if ( str_starts_with( $base, $public ) || str_starts_with( $public, $base ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                throw new \RuntimeException( __( 'Private storage must be separate from public directories.', 'atshift-deft' ) );
            }
        }
        return $real;
    }

    // Read-only compatibility with files written before database-backed private storage.
    public static function directory() {
        if ( ! defined( 'SF_PRIVATE_MEDIA_DIR' ) || ! defined( 'SF_PUBLIC_DOCUMENT_ROOT' ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \RuntimeException( __( 'Private asset storage is not configured.', 'atshift-deft' ) );
        }
        $roots = array( ABSPATH, WP_CONTENT_DIR, SF_PUBLIC_DOCUMENT_ROOT );
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) throw new \RuntimeException( __( 'Invalid public document root.', 'atshift-deft' ) );
            // A real filesystem path is validated by realpath below, not text sanitization.
            $roots[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] );
        }
        $base = self::validate_storage_path( SF_PRIVATE_MEDIA_DIR, $roots );
        global $wpdb;
        $dir = $base . '/sf-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . 'sf_assets' ), 0, 20 );
        if ( ! is_dir( $dir ) || is_link( $dir ) || realpath( $dir ) !== $dir || ( fileperms( $dir ) & 0077 ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \RuntimeException( __( 'Private storage requires an unshared directory (0700).', 'atshift-deft' ) );
        }
        return $dir;
    }

    public static function available() {
        return PrivateBlobs::available();
    }

    public static function validate_file( $path, $name ) {
        $size = filesize( $path );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $size || $size > self::MAX_BYTES ) throw new \InvalidArgumentException( __( 'File must be between 1 byte and 10 MiB.', 'atshift-deft' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $name ) || strlen( $name ) > 255 ) throw new \InvalidArgumentException( __( 'Invalid filename.', 'atshift-deft' ) );
        $name = sanitize_file_name( wp_basename( $name ) );
        $declared = wp_check_filetype( $name, self::MIMES );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $declared['type'] || ! class_exists( '\finfo' ) ) throw new \InvalidArgumentException( __( 'Only JPEG, PNG, WebP and PDF are accepted; fileinfo is required.', 'atshift-deft' ) );
        $actual = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );
        $checked = wp_check_filetype_and_ext( $path, $name, self::MIMES );
        if ( $actual !== $declared['type'] || $checked['type'] !== $declared['type'] || $checked['proper_filename'] ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \InvalidArgumentException( __( 'The file contents do not match its extension.', 'atshift-deft' ) );
        }
        if ( 'application/pdf' === $actual ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( '%PDF-' !== file_get_contents( $path, false, null, 0, 5 ) ) throw new \InvalidArgumentException( __( 'Invalid PDF header.', 'atshift-deft' ) );
        } else {
            $image = @getimagesize( $path );
            if ( ! $image || $image[0] < 1 || $image[1] < 1 || $image[0] * $image[1] > 40000000 ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                throw new \InvalidArgumentException( __( 'Invalid image or image exceeds 40 megapixels.', 'atshift-deft' ) );
            }
        }
        return array( 'filename' => $name, 'mime' => $actual, 'bytes' => $size, 'sha256' => hash_file( 'sha256', $path ) );
    }

    public static function read( $id ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE asset_id=%s', $table, $id ), ARRAY_A );
    }

    public static function response( $row ) {
        return array( 'assetId' => $row['asset_id'], 'articleId' => $row['article_id'], 'purpose' => $row['purpose'],
            'sha256' => $row['sha256'], 'filename' => $row['filename'], 'mime' => $row['mime'], 'bytes' => (int) $row['bytes'],
            'createdAt' => $row['created_at'], 'state' => 'private-staged', 'publishAuthorized' => false,
            'contentUrl' => add_query_arg( array( 'action' => 'atshde_asset', 'asset' => $row['asset_id'],
                '_sf_nonce' => wp_create_nonce( 'sf-asset-' . $row['asset_id'] . '-' . $row['sha256'] ) ), admin_url( 'admin-post.php' ) ) );
    }

    public static function listing( $article_id ) {
        global $wpdb;
        $article = Store::read( $article_id );
        if ( ! $article ) return new \WP_Error( 'sf_missing', __( 'Save the article before adding files.', 'atshift-deft' ), array( 'status' => 404 ) );
        if ( ! Access::article( $article_id ) ) return Access::denied();
        $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s ORDER BY asset_id', $table, $article_id ), ARRAY_A );
        $manifest = array_map( static function( $row ) {
            return array( 'assetId' => $row['asset_id'], 'sha256' => $row['sha256'], 'purpose' => $row['purpose'] );
        }, $rows );
        return array( 'articleId' => $article_id, 'articleVersion' => (int) $article['version'], 'articleDigest' => $article['digest'],
            'manifestDigest' => hash( 'sha256', Snapshot::json( $manifest ) ), 'storageReady' => self::available(),
            'maxFileBytes' => min( self::MAX_BYTES, wp_max_upload_size() ), 'publishAuthorized' => false,
            'assets' => array_map( array( self::class, 'response' ), $rows ) );
    }

    public static function receive( $request, $grant = null ) {
        global $wpdb;
        $actor = $grant ? (int) $grant['created_by'] : get_current_user_id();
        $params = $request->get_body_params();
        $files = $request->get_file_params();
        $purpose = $params['purpose'] ?? 'reference';
        if ( ! str_starts_with( strtolower( (string) $request->get_header( 'content-type' ) ), 'multipart/form-data;' ) ||
            array_diff( array_keys( $params ), array( 'purpose' ) ) || array_keys( $files ) !== array( 'file' ) ||
            ! in_array( $purpose, array( 'reference', 'publication' ), true ) ) {
            return new \WP_Error( 'sf_asset_input', __( 'Send one multipart file and optional purpose (reference or publication).', 'atshift-deft' ), array( 'status' => 400 ) );
        }
        if ( ! Store::read( $request['id'] ) ) return new \WP_Error( 'sf_missing', __( 'Save the article before adding files.', 'atshift-deft' ), array( 'status' => 404 ) );
        if ( ! Access::article( $request['id'], $actor ) || ! user_can( $actor, 'upload_files' ) ) return Access::denied();
        $file = $files['file'];
        if ( ! is_array( $file ) || ( $file['error'] ?? -1 ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'sf_asset_upload', __( 'The file was not received. Check the server upload limit.', 'atshift-deft' ), array( 'status' => 400 ) );
        }
        if ( ! self::available() ) {
            return new \WP_Error( 'sf_asset_storage', __( 'Private storage is unavailable. No file was retained.', 'atshift-deft' ), array( 'status' => 503 ) );
        }
        try { $meta = self::validate_file( $file['tmp_name'], $file['name'] ?? null ); } catch ( \Throwable $error ) {
            return new \WP_Error( 'sf_asset_type', $error->getMessage(), array( 'status' => 400 ) );
        }
        if ( $meta['bytes'] > wp_max_upload_size() ) {
            return new \WP_Error( 'sf_asset_size', __( 'The file exceeds the WordPress upload limit.', 'atshift-deft' ), array( 'status' => 413 ) );
        }
        if ( $grant && ( $request['id'] !== $grant['article_id'] || $purpose !== $grant['purpose'] ||
            $meta['filename'] !== $grant['filename'] || $meta['mime'] !== $grant['mime'] ||
            $meta['bytes'] !== (int) $grant['bytes'] || ! hash_equals( $grant['sha256'], $meta['sha256'] ) ) ) {
            return new \WP_Error( 'sf_transfer_mismatch', __( 'File does not match the authorized transfer.', 'atshift-deft' ), array( 'status' => 400 ) );
        }
        $table = self::table();
        $lock = 'sf_asset_' . substr( hash( 'sha256', DB_NAME . ':' . $table ), 0, 50 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
            return new \WP_Error( 'sf_busy', __( 'Another upload is in progress. Retry the same file.', 'atshift-deft' ), array( 'status' => 409 ) );
        }
        $pending_id = null;
        try {
            $same = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s AND sha256=%s AND purpose=%s', $table, $request['id'], $meta['sha256'], $purpose ), ARRAY_A );
            if ( $same ) { self::verified_bytes( $same ); return self::response( $same ); }
            $usage = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS count,COALESCE(SUM(bytes),0) AS bytes FROM %i WHERE article_id=%s', $table, $request['id'] ), ARRAY_A );
            $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes),0) FROM %i', $table ) );
            $user_bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes),0) FROM %i WHERE created_by=%d', $table, $actor ) );
            if ( ! $usage || null === $total || null === $user_bytes ) throw new \RuntimeException( __( 'Storage metadata unavailable.', 'atshift-deft' ) );
            $user_limit = self::user_limit( $actor );
            if ( (int) $user_bytes + $meta['bytes'] > $user_limit ) {
                return new \WP_Error( 'sf_asset_user_quota', __( 'Your private media capacity is reached. Reuse existing files or ask the site administrator to adjust the limit.', 'atshift-deft' ), array( 'status' => 413, 'limitBytes' => $user_limit, 'usedBytes' => (int) $user_bytes ) );
            }
            if ( $usage['count'] >= 50 || $usage['bytes'] + $meta['bytes'] > self::ARTICLE_BYTES || $total + $meta['bytes'] > self::SITE_BYTES ) {
                return new \WP_Error( 'sf_asset_quota', __( 'Private asset quota reached (50 files / 100 MiB per article; 500 MiB per site).', 'atshift-deft' ), array( 'status' => 413 ) );
            }
            $id = wp_generate_uuid4();
            $bytes = file_get_contents( $file['tmp_name'], false, null, 0, self::MAX_BYTES + 1 );
            PrivateBlobs::put( 'asset', $id, $bytes, $meta );
            $pending_id = $id;
            $row = array_merge( $meta, array( 'asset_id' => $id, 'article_id' => $request['id'], 'purpose' => $purpose,
                'created_by' => $grant ? (int) $grant['created_by'] : get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
            self::verified_bytes( $row );
            if ( false === $wpdb->insert( $table, $row ) ) throw new \RuntimeException( __( 'Could not save metadata.', 'atshift-deft' ) );
            $pending_id = null;
            return self::response( $row );
        } catch ( \Throwable $error ) {
            return new \WP_Error( 'sf_asset_storage', __( 'The private file could not be saved or verified.', 'atshift-deft' ), array( 'status' => 503 ) );
        } finally {
            if ( $pending_id ) PrivateBlobs::delete( 'asset', $pending_id );
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    public static function verified_path( $row ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $row['asset_id'] ) ) throw new \RuntimeException( __( 'Invalid asset identity.', 'atshift-deft' ) );
        $path = self::directory() . '/' . $row['asset_id'] . '.bin';
        if ( is_link( $path ) || ! is_file( $path ) || ( fileperms( $path ) & 0077 ) ||
            (int) filesize( $path ) !== (int) $row['bytes'] || ! hash_equals( $row['sha256'], hash_file( 'sha256', $path ) ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \RuntimeException( __( 'Private file integrity check failed.', 'atshift-deft' ) );
        }
        return $path;
    }

    public static function verified_bytes( $row ) {
        $bytes = PrivateBlobs::get( 'asset', $row['asset_id'], $row );
        if ( null !== $bytes ) return $bytes;
        $bytes = file_get_contents( self::verified_path( $row ), false, null, 0, self::MAX_BYTES + 1 );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; JSON responses encode it and HTML callers escape at output.
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== (int) $row['bytes'] || ! hash_equals( $row['sha256'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Private file integrity check failed.', 'atshift-deft' ) );
        return $bytes;
    }

    public static function serve() {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Content-Type-Options: nosniff' );
        header( "Content-Security-Policy: default-src 'none'; sandbox" );
        $id = is_string( $_GET['asset'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['asset'] ) ) : '';
        $nonce = is_string( $_GET['_sf_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['_sf_nonce'] ) ) : '';
        // Reconstruct WordPress's slashed input to reject any sanitization change.
        if ( '' === $nonce || ! isset( $_GET['asset'], $_GET['_sf_nonce'] ) ||
            wp_slash( $id ) !== $_GET['asset'] || wp_slash( $nonce ) !== $_GET['_sf_nonce'] ||
            true !== self::permission() || ! is_string( $id ) || ! is_string( $nonce ) ||
            ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
            wp_die( esc_html__( 'Private file access denied.', 'atshift-deft' ), esc_html__( 'Private asset', 'atshift-deft' ), array( 'response' => 403 ) );
        }
        $row = self::read( $id );
        if ( ! $row || ! Access::article( $row['article_id'] ) || ! wp_verify_nonce( $nonce, 'sf-asset-' . $id . '-' . $row['sha256'] ) ) {
            wp_die( esc_html__( 'Private file access denied.', 'atshift-deft' ), esc_html__( 'Private asset', 'atshift-deft' ), array( 'response' => 403 ) );
        }
        try {
            $bytes = self::verified_bytes( $row );
            if ( ! is_string( $bytes ) || strlen( $bytes ) !== (int) $row['bytes'] || ! hash_equals( $row['sha256'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Private file changed during transfer.', 'atshift-deft' ) );
        } catch ( \Throwable $error ) {
            wp_die( esc_html__( 'Private file is unavailable.', 'atshift-deft' ), esc_html__( 'Private asset', 'atshift-deft' ), array( 'response' => 503 ) );
        }
        header( 'Content-Type: ' . $row['mime'] );
        header( 'Content-Length: ' . $row['bytes'] );
        $mode = str_starts_with( $row['mime'], 'image/' ) ? 'inline' : 'attachment';
        header( 'Content-Disposition: ' . $mode . '; filename="asset.' . pathinfo( $row['filename'], PATHINFO_EXTENSION ) . '"; filename*=UTF-8\'\'' . rawurlencode( $row['filename'] ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Send the exact integrity-verified binary buffer, never HTML-escape image/PDF bytes.
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) echo $bytes;
        exit;
    }
}
