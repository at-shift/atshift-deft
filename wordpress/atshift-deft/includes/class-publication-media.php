<?php
namespace StructuredFields;
defined( 'ABSPATH' ) || exit;

final class PublicationMedia {
    private static function directory( $id, $create = false ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) throw new \RuntimeException( __( 'Invalid publication.', 'atshift-deft' ) );
        $path = Assets::directory() . '/publication-' . $id;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Frozen media requires a local 0700 directory; plain-text exceptions are escaped by HTML callers or JSON-encoded by REST.
        if ( $create && ! is_dir( $path ) && ! mkdir( $path, 0700 ) ) throw new \RuntimeException( __( 'Publication storage unavailable.', 'atshift-deft' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_dir( $path ) || is_link( $path ) || realpath( $path ) !== $path || ( fileperms( $path ) & 0077 ) ) throw new \RuntimeException( __( 'Private publication storage required.', 'atshift-deft' ) );
        return $path;
    }

    public static function freeze( $job, $row ) {
        global $wpdb;
        $lock = 'sf_pub_media_' . substr( hash( 'sha256', DB_NAME . ':' . Publications::table() ), 0, 45 );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) throw new \RuntimeException( __( 'Publication storage is busy.', 'atshift-deft' ) );
        try { return self::freeze_files( $job, $row ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    private static function freeze_files( $job, $row ) {
        $refs = Media::references( json_decode( $row['payload'] ) );
        if ( ! $refs ) return array();
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( count( $refs ) > 50 ) throw new \RuntimeException( __( 'At most 50 media bindings can be published.', 'atshift-deft' ) );
        $directory = self::directory( $job['publication_id'], true );
        $files = $unique = array(); $size = 0;
        $resolved = Media::resolve_batch( $refs, $row['article_id'] );
        foreach ( $refs as $key => $usage ) {
            $meta = $resolved[$key]; $hash = $meta['sha256'];
            if ( isset( $unique[$hash] ) ) { $files[$key] = array_merge( $unique[$hash], array( 'filename' => $meta['filename'] ) ); continue; }
            $bytes = file_get_contents( $meta['path'], false, null, 0, Assets::MAX_BYTES + 1 );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! is_string( $bytes ) || strlen( $bytes ) !== (int) $meta['bytes'] || ! hash_equals( $meta['sha256'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Publication media changed.', 'atshift-deft' ) );
            $size += strlen( $bytes );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( $size > Assets::ARTICLE_BYTES ) throw new \RuntimeException( __( 'Publication media exceeds 100 MiB.', 'atshift-deft' ) );
            $path = $directory . '/' . $hash . '.bin';
            if ( ! is_file( $path ) ) {
                $total = 0;
                foreach ( glob( Assets::directory() . '/publication-*/*.bin' ) ?: array() as $file ) $total += filesize( $file );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                if ( $total + strlen( $bytes ) > Assets::SITE_BYTES ) throw new \RuntimeException( __( 'Publication media quota reached.', 'atshift-deft' ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive local creation prevents overwriting a frozen publication file.
                $handle = fopen( $path, 'x+b' );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                if ( ! $handle ) throw new \RuntimeException( __( 'Publication file could not be reserved.', 'atshift-deft' ) );
                try {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Set 0600 before writing the integrity-verified bytes to the exclusive handle.
                    if ( ! chmod( $path, 0600 ) || fwrite( $handle, $bytes ) !== strlen( $bytes ) ) throw new \RuntimeException( __( 'Publication file could not be frozen.', 'atshift-deft' ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created local file handle.
                } finally { fclose( $handle ); }
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( is_link( $path ) || ( fileperms( $path ) & 0077 ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) throw new \RuntimeException( __( 'Publication copy mismatch.', 'atshift-deft' ) );
            $files[$key] = array( 'sha256' => $hash, 'mime' => $meta['mime'], 'filename' => $meta['filename'], 'bytes' => strlen( $bytes ) );
            $unique[$hash] = $files[$key];
        }
        return $files;
    }

    public static function html( $job, $row, $files ) {
        $dom = new \DOMDocument(); $old = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $row['rendered'] . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $old ); $found = array();
        // Component markup cannot introduce executable WordPress block comments.
        foreach ( ( new \DOMXPath( $dom ) )->query( '//comment()' ) as $comment ) $comment->parentNode->removeChild( $comment );
        foreach ( ( new \DOMXPath( $dom ) )->query( '//*[@data-sf-media]' ) as $element ) {
            $key = $element->getAttribute( 'data-sf-media' );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! isset( $files[$key] ) || isset( $found[$key] ) ) throw new \RuntimeException( __( 'Invalid publication media binding.', 'atshift-deft' ) );
            $image = str_starts_with( $files[$key]['mime'], 'image/' );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( $element->tagName !== ( $image ? 'img' : 'a' ) ) throw new \RuntimeException( __( 'Invalid publication element.', 'atshift-deft' ) );
            $element->setAttribute( $image ? 'src' : 'href', add_query_arg( array( 'sf_public_media' => $job['publication_id'], 'sf_media_key' => $key ), home_url( '/' ) ) );
            if ( ! $image ) $element->setAttribute( 'download', '' );
            $element->removeAttribute( 'data-sf-media' ); $found[$key] = true;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( count( $found ) !== count( $files ) ) throw new \RuntimeException( __( 'Missing publication media binding.', 'atshift-deft' ) );
        $html = ''; foreach ( $dom->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) $html .= $dom->saveHTML( $node );
        // User text must never turn into a WordPress shortcode after publication.
        return str_replace( '[', '&#91;', $html );
    }

    public static function bytes( $id, $key ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) throw new \RuntimeException( __( 'Unavailable media.', 'atshift-deft' ) );
        $job = Publications::by_publication( $id );
        $post = $job && $job['post_id'] ? get_post( (int) $job['post_id'] ) : null;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $job || 'published' !== $job['state'] || ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type || $post->post_password ) throw new \RuntimeException( __( 'Unavailable media.', 'atshift-deft' ) );
        $files = json_decode( $job['files'], true ); $meta = $files[$key] ?? null;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $meta || ! preg_match( '/^[a-f0-9]{64}$/D', $meta['sha256'] ) ) throw new \RuntimeException( __( 'Unavailable media.', 'atshift-deft' ) );
        $path = self::directory( $id ) . '/' . $meta['sha256'] . '.bin';
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( is_link( $path ) || ! is_file( $path ) || ( fileperms( $path ) & 0077 ) ) throw new \RuntimeException( __( 'Unavailable media.', 'atshift-deft' ) );
        $bytes = file_get_contents( $path, false, null, 0, Assets::MAX_BYTES + 1 );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== $meta['bytes'] || ! hash_equals( $meta['sha256'], hash( 'sha256', $bytes ) ) ) throw new \RuntimeException( __( 'Unavailable media.', 'atshift-deft' ) );
        return array( $meta, $bytes );
    }

    public static function serve() {
        // Published media is intentionally public; bytes() rechecks publication state on every read.
        if ( ! isset( $_GET['sf_public_media'] ) ) return;
        nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: no-referrer' );
        header( "Content-Security-Policy: default-src 'none'; sandbox" );
        try {
            if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) throw new \RuntimeException();
            $id = is_string( $_GET['sf_public_media'] ?? null ) ? wp_unslash( $_GET['sf_public_media'] ) : '';
            $key = is_string( $_GET['sf_media_key'] ?? null ) ? wp_unslash( $_GET['sf_media_key'] ) : '';
            list( $meta, $bytes ) = self::bytes( $id, $key );
        } catch ( \Throwable $e ) { status_header( 404 ); exit; }
        header( 'Content-Type: ' . $meta['mime'] ); header( 'Content-Length: ' . strlen( $bytes ) );
        header( 'Content-Disposition: ' . ( 'application/pdf' === $meta['mime'] ? 'attachment' : 'inline' ) . '; filename="media.' . pathinfo( $meta['filename'], PATHINFO_EXTENSION ) . '"; filename*=UTF-8\'\'' . rawurlencode( $meta['filename'] ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact verified image/PDF response with validated MIME and nosniff, not HTML.
        if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) echo $bytes;
        exit;
    }
}
