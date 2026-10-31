<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class Media {
    public static function key( $block, $field ) {
        return hash( 'sha256', Snapshot::json( array( $block, $field ) ) );
    }

    public static function attachment( $id ) {
        if ( true !== Assets::permission() || ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', $id ) ||
            (string) (int) $id !== $id || 'attachment' !== get_post_type( (int) $id ) ||
            ! in_array( get_post( (int) $id )->post_status ?? '', array( 'inherit', 'private' ), true ) ||
            (int) get_post( (int) $id )->post_author !== get_current_user_id() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \InvalidArgumentException( __( 'Media is missing or not accessible.', 'atshift-deft' ) );
        }
        $uploads = wp_get_upload_dir();
        $base = empty( $uploads['error'] ) ? realpath( $uploads['basedir'] ) : false;
        $path = get_attached_file( (int) $id, true );
        $real = is_string( $path ) ? realpath( $path ) : false;
        if ( ! $base || ! $real || ! is_file( $real ) || ! is_readable( $real ) ||
            wp_normalize_path( $path ) !== wp_normalize_path( $real ) ||
            ! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \InvalidArgumentException( __( 'Only local media inside the uploads directory is supported.', 'atshift-deft' ) );
        }
        $meta = Assets::validate_file( $real, wp_basename( $real ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( get_post_mime_type( (int) $id ) !== $meta['mime'] ) throw new \InvalidArgumentException( __( 'Media type does not match the file.', 'atshift-deft' ) );
        return array_merge( $meta, array( 'path' => $real, 'assetRef' => array( 'kind' => 'attachment', 'id' => $id, 'sha256' => $meta['sha256'] ) ) );
    }

    public static function attachment_response( $id ) {
        $meta = self::attachment( $id );
        unset( $meta['path'] );
        $meta['existingWordPressMedia'] = true;
        return $meta;
    }

    public static function listing( $request ) {
        $search = $request->get_param( 'search' ) ?? '';
        $page = $request->get_param( 'page' ) ?? '1';
        if ( ! is_string( $search ) || strlen( $search ) > 200 || ! is_scalar( $page ) || ! preg_match( '/^[1-9][0-9]{0,4}$/D', (string) $page ) ) {
            return new \WP_Error( 'sf_media_query', __( 'Invalid media search or page.', 'atshift-deft' ), array( 'status' => 400 ) );
        }
        $query = new \WP_Query( array( 'post_type' => 'attachment', 'post_status' => array( 'inherit', 'private' ),
            'post_mime_type' => array_values( Assets::MIMES ), 'posts_per_page' => 10, 'paged' => (int) $page,
            'author' => get_current_user_id(), 's' => $search, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids' ) );
        $items = array();
        foreach ( $query->posts as $id ) {
            try { $items[] = self::attachment_response( (string) $id ); } catch ( \Throwable $error ) {
                // Unavailable/offloaded files must not become selectable without a verified hash.
            }
        }
        return array( 'items' => $items, 'page' => (int) $page, 'totalPages' => (int) $query->max_num_pages );
    }

    public static function resolve( $ref, $article_id, $type ) {
        if ( true !== Assets::permission() || ! Access::article( $article_id ) || ! $ref instanceof \stdClass ||
            ! isset( $ref->kind, $ref->id, $ref->sha256 ) || ! is_string( $ref->id ) || ! is_string( $ref->sha256 ) ||
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            ! preg_match( '/^[a-f0-9]{64}$/D', $ref->sha256 ) ) throw new \InvalidArgumentException( __( 'Invalid media reference or permission.', 'atshift-deft' ) );
        if ( 'staged' === $ref->kind ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $ref->id ) ) throw new \InvalidArgumentException( __( 'Invalid private asset ID.', 'atshift-deft' ) );
            $row = Assets::read( $ref->id );
            if ( ! $row || $row['article_id'] !== $article_id || 'publication' !== $row['purpose'] || ! hash_equals( $row['sha256'], $ref->sha256 ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                throw new \InvalidArgumentException( __( 'Use a publication candidate belonging to this article, with its exact hash.', 'atshift-deft' ) );
            }
            $meta = array_merge( $row, array( 'path' => Assets::verified_path( $row ) ) );
        } elseif ( 'attachment' === $ref->kind ) {
            $meta = self::attachment( $ref->id );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! hash_equals( $meta['sha256'], $ref->sha256 ) ) throw new \InvalidArgumentException( __( 'The existing media file has changed.', 'atshift-deft' ) );
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \InvalidArgumentException( __( 'Unknown media source.', 'atshift-deft' ) );
        }
        if ( ( 'image' === $type && ! str_starts_with( $meta['mime'], 'image/' ) ) ||
            ( 'link' === $type && 'application/pdf' !== $meta['mime'] ) || ! in_array( $type, array( 'image', 'link' ), true ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            throw new \InvalidArgumentException( __( 'Use an image for an image field, or a PDF for a download link.', 'atshift-deft' ) );
        }
        return $meta;
    }

    public static function references( $snapshot ) {
        $components = $refs = array();
        foreach ( $snapshot->components as $c ) $components[ $c->id . '@' . $c->revision ] = $c;
        foreach ( $snapshot->article->blocks as $b ) {
            $c = $components[ $b->componentId . '@' . $b->componentRevision ];
            foreach ( $c->fields as $f ) {
                $v = $b->values->{$f->key};
                if ( isset( $v->assetRef ) ) $refs[ self::key( $b->id, $f->key ) ] = array( 'ref' => $v->assetRef, 'type' => $f->type );
            }
        }
        return $refs;
    }

    public static function resolve_batch( $refs, $article_id ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( count( $refs ) > 50 ) throw new \InvalidArgumentException( __( 'At most 50 media bindings are supported per article.', 'atshift-deft' ) );
        $verified = $resolved = $files = array(); $bytes = 0;
        foreach ( $refs as $key => $usage ) {
            $ref = $usage['ref'];
            $identity = Snapshot::json( array( get_current_user_id(), $article_id, $ref->kind ?? null, $ref->id ?? null, $ref->sha256 ?? null, $usage['type'] ) );
            if ( ! isset( $verified[$identity] ) ) {
                $meta = self::resolve( $ref, $article_id, $usage['type'] );
                if ( ! isset( $files[$meta['path']] ) ) { $bytes += (int) $meta['bytes']; $files[$meta['path']] = true; }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                if ( $bytes > Assets::ARTICLE_BYTES ) throw new \InvalidArgumentException( __( 'Article media exceeds 100 MiB.', 'atshift-deft' ) );
                $verified[$identity] = $meta;
            }
            $resolved[$key] = $verified[$identity];
        }
        return $resolved;
    }

    public static function url( $row, $key ) {
        return add_query_arg( array( 'action' => 'sf_media', 'article' => $row['article_id'], 'version' => (int) $row['version'], 'key' => $key,
            '_sf_nonce' => wp_create_nonce( 'sf-media-' . $row['post_id'] . '-' . $row['digest'] . '-' . $key ) ), admin_url( 'admin-post.php' ) );
    }

    public static function preview_html( $row ) {
        return self::preview( $row )['html'];
    }

    public static function preview( $row ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! Access::article( $row['article_id'] ) ) throw new \RuntimeException( __( 'Article access denied.', 'atshift-deft' ) );
        $snapshot = json_decode( $row['payload'] );
        $refs = self::references( $snapshot );
        $media = self::resolve_batch( $refs, $row['article_id'] );
        if ( ! $refs ) return array( 'html' => $row['rendered'], 'media' => $media, 'references' => $refs );
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $row['rendered'] . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        $found = $urls = array();
        foreach ( ( new \DOMXPath( $dom ) )->query( '//*[@data-sf-media]' ) as $element ) {
            $key = $element->getAttribute( 'data-sf-media' );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! isset( $refs[ $key ] ) || isset( $found[ $key ] ) ) throw new \RuntimeException( __( 'Invalid media binding.', 'atshift-deft' ) );
            $ref = $refs[ $key ];
            $image = 'image' === $ref['type'];
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( $element->tagName !== ( $image ? 'img' : 'a' ) ) throw new \RuntimeException( __( 'Invalid media element.', 'atshift-deft' ) );
            // Repeated bindings share one authenticated URL so browsers fetch the file once.
            $identity = Snapshot::json( array( $ref['ref']->kind, $ref['ref']->id, $ref['ref']->sha256, $ref['type'] ) );
            if ( ! isset( $urls[$identity] ) ) $urls[$identity] = self::url( $row, $key );
            $element->setAttribute( $image ? 'src' : 'href', $urls[$identity] );
            if ( ! $image ) $element->setAttribute( 'download', '' );
            $element->removeAttribute( 'data-sf-media' );
            $found[ $key ] = true;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( count( $found ) !== count( $refs ) ) throw new \RuntimeException( __( 'Missing media binding.', 'atshift-deft' ) );
        $html = '';
        foreach ( $dom->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) $html .= $dom->saveHTML( $node );
        return array( 'html' => $html, 'media' => $media, 'references' => $refs );
    }

    public static function serve() {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Content-Type-Options: nosniff' );
        header( "Content-Security-Policy: default-src 'none'; sandbox" );
        $id = is_string( $_GET['article'] ?? null ) ? wp_unslash( $_GET['article'] ) : '';
        $version = is_string( $_GET['version'] ?? null ) ? wp_unslash( $_GET['version'] ) : '';
        $key = is_string( $_GET['key'] ?? null ) ? wp_unslash( $_GET['key'] ) : '';
        $nonce = is_string( $_GET['_sf_nonce'] ?? null ) ? wp_unslash( $_GET['_sf_nonce'] ) : '';
        if ( true !== Assets::permission() || ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $id ) ||
            ! is_string( $version ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $version ) ||
            ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) || ! is_string( $nonce ) ||
            ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
            wp_die( esc_html__( 'Private media access denied.', 'atshift-deft' ), esc_html__( 'Private preview media', 'atshift-deft' ), array( 'response' => 403 ) );
        }
        $row = Store::read( $id, (int) $version );
        if ( ! $row || ! Access::article( $row['article_id'] ) || ! wp_verify_nonce( $nonce, 'sf-media-' . $row['post_id'] . '-' . $row['digest'] . '-' . $key ) ) {
            wp_die( esc_html__( 'Private media access denied.', 'atshift-deft' ), esc_html__( 'Private preview media', 'atshift-deft' ), array( 'response' => 403 ) );
        }
        try {
            $refs = self::references( json_decode( $row['payload'] ) );
            if ( ! isset( $refs[ $key ] ) ) throw new \RuntimeException( __( 'Unknown media field.', 'atshift-deft' ) );
            $meta = self::resolve( $refs[ $key ]['ref'], $id, $refs[ $key ]['type'] );
            // Read bounded bytes once, then verify that exact buffer before sending it.
            $bytes = file_get_contents( $meta['path'], false, null, 0, Assets::MAX_BYTES + 1 );
            if ( ! is_string( $bytes ) || strlen( $bytes ) !== (int) $meta['bytes'] || ! hash_equals( $meta['sha256'], hash( 'sha256', $bytes ) ) ) {
                throw new \RuntimeException( __( 'Media changed during transfer.', 'atshift-deft' ) );
            }
        } catch ( \Throwable $error ) {
            wp_die( esc_html__( 'The referenced media is unavailable or has changed.', 'atshift-deft' ), esc_html__( 'Private preview media', 'atshift-deft' ), array( 'response' => 503 ) );
        }
        header( 'Content-Type: ' . $meta['mime'] );
        header( 'Content-Length: ' . strlen( $bytes ) );
        $mode = 'application/pdf' === $meta['mime'] ? 'attachment' : 'inline';
        header( 'Content-Disposition: ' . $mode . '; filename="media.' . pathinfo( $meta['filename'], PATHINFO_EXTENSION ) . '"; filename*=UTF-8\'\'' . rawurlencode( $meta['filename'] ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integrity-verified image/PDF bytes with a validated MIME type, not an HTML response.
        if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) echo $bytes;
        exit;
    }
}
