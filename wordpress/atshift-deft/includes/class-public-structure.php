<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class PublicStructure {
    private const TAGS = 'article section div header footer aside h1 h2 h3 h4 h5 h6 p span strong em b i u s small mark sub sup blockquote pre code ul ol li dl dt dd figure figcaption img a br hr table caption thead tbody tfoot tr th td details summary time';

    public static function routes() {
        register_rest_route( 'structured-fields/v1', '/public-structure/(?P<id>[1-9][0-9]{0,9})', array(
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => array( self::class, 'response' ),
        ) );
    }

    public static function response( $request ) {
        $data = self::read( (int) $request['id'] );
        $response = new \WP_REST_Response( $data ?: array( 'code' => 'sf_public_structure_missing', 'message' => __( 'Public structure not found.', 'atshift-deft' ) ), $data ? 200 : 404 );
        $response->header( 'Cache-Control', 'no-store, private, max-age=0' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function read( $id ) {
        $post = get_post( $id );
        if ( ! $post || 'publish' !== $post->post_status || $post->post_password || ! is_post_publicly_viewable( $post ) ) return null;
        $publication = get_post_meta( $post->ID, '_atshde_publication', true );
        if ( ! is_string( $publication ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $publication ) ) return null;
        $job = Publications::by_publication( $publication );
        if ( ! $job || 'published' !== $job['state'] || ! Publications::matches_post( $job, $post, true ) ) return null;
        // Membership/paywall integrations can veto this public projection, never bypass the checks above.
        if ( ! apply_filters( 'atshift_deft_public_structure_enabled', true, $post->ID ) ) return null;
        $nodes = self::project( $post->post_content );
        if ( null === $nodes ) return null;
        return array( 'format' => 'atshift-deft-public', 'schemaVersion' => 1,
            'url' => get_permalink( $post ), 'postType' => $post->post_type,
            'title' => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            'publishedAt' => get_post_time( DATE_W3C, true, $post ), 'modifiedAt' => get_post_modified_time( DATE_W3C, true, $post ),
            'content' => $nodes,
        );
    }

    public static function project( $html ) {
        if ( strlen( $html ) > 4 * 1024 * 1024 ) return null;
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors( true );
        try {
            $loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        } finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
        if ( ! $loaded ) return null;
        $xpath = new \DOMXPath( $dom );
        $roots = $xpath->query( '//body/*[contains(concat(" ",normalize-space(@class)," ")," sf-article ")]' );
        if ( 1 !== $roots->length ) return null;
        $budget = 10000;
        try {
            // Only public HTML is traversed. No snapshot, component defaults, manifests or account data are loaded.
            $root = self::node( $roots->item( 0 ), 0, $budget );
            return $root ? $root['children'] : array();
        } catch ( \OverflowException $error ) { return null; }
    }

    private static function node( $el, $depth, &$budget ) {
        if ( $depth > 64 || --$budget < 0 ) throw new \OverflowException();
        if ( $el instanceof \DOMText ) return '' === $el->nodeValue ? null : array( 'type' => 'text', 'text' => $el->nodeValue );
        if ( ! $el instanceof \DOMElement ) return null;
        $tag = strtolower( $el->tagName );
        if ( ! in_array( $tag, explode( ' ', self::TAGS ), true ) || $el->hasAttribute( 'hidden' ) ||
            'true' === strtolower( trim( $el->getAttribute( 'aria-hidden' ) ) ) ||
            preg_match( '/(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse))/i', $el->getAttribute( 'style' ) ) ) return null;
        $types = array( 'div' => 'group', 'section' => 'section', 'article' => 'article', 'p' => 'paragraph',
            'a' => 'link', 'img' => 'image', 'ul' => 'list', 'ol' => 'list', 'li' => 'listItem',
            'details' => 'disclosure', 'summary' => 'summary', 'br' => 'break', 'hr' => 'separator' );
        $node = array( 'type' => $types[ $tag ] ?? 'element', 'tag' => $tag );
        if ( preg_match( '/^h([1-6])$/D', $tag, $m ) ) { $node['type'] = 'heading'; $node['level'] = (int) $m[1]; }
        if ( 'ul' === $tag || 'ol' === $tag ) $node['ordered'] = 'ol' === $tag;
        $classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
        if ( in_array( 'sf-block', $classes, true ) ) $node['type'] = 'block';
        foreach ( array( 'stack', 'columns', 'qa' ) as $layout ) {
            if ( in_array( 'sf-built-' . $layout, $classes, true ) ) $node['layout'] = $layout;
        }
        if ( 'a' === $tag || 'img' === $tag ) {
            $url = self::url( $el->getAttribute( 'img' === $tag ? 'src' : 'href' ) );
            if ( $url ) $node['url'] = $url;
            if ( 'img' === $tag ) $node['alt'] = $el->getAttribute( 'alt' );
        }
        foreach ( array( 'colspan', 'rowspan' ) as $attr ) {
            $value = $el->getAttribute( $attr );
            if ( in_array( $tag, array( 'td', 'th' ), true ) && ctype_digit( $value ) && (int) $value > 0 && (int) $value <= 1000 ) $node[ $attr ] = (int) $value;
        }
        $node['children'] = array();
        foreach ( $el->childNodes as $child ) {
            $item = self::node( $child, $depth + 1, $budget );
            if ( null !== $item ) $node['children'][] = $item;
        }
        return $node;
    }

    private static function url( $url ) {
        if ( ! $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) return '';
        $parts = wp_parse_url( $url );
        if ( false === $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) ) return '';
        if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) return '';
        return $url;
    }

    public static function linked_data( $data ) {
        $blog = 'post' === $data['postType'];
        return array( '@context' => 'https://schema.org', '@type' => $blog ? 'BlogPosting' : 'WebPage',
            '@id' => $data['url'] . '#atshift-deft', 'url' => $data['url'],
            $blog ? 'headline' : 'name' => $data['title'],
            'datePublished' => $data['publishedAt'], 'dateModified' => $data['modifiedAt'] );
    }

    public static function head() {
        if ( ! is_singular() || is_preview() || is_feed() ) return;
        $id = get_queried_object_id(); $data = self::read( $id );
        if ( ! $data ) return;
        echo '<link rel="alternate" type="application/json" title="DeFt public structure" href="' . esc_url( rest_url( 'structured-fields/v1/public-structure/' . $id ) ) . '">' . "\n";
        // Sites with an SEO graph can disable only this small JSON-LD document, keeping structure discovery.
        if ( ! apply_filters( 'atshift_deft_json_ld_enabled', true, $id ) ) return;
        $json = wp_json_encode( self::linked_data( $data ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) return;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* escapes HTML delimiters inside this non-executable JSON script.
        echo '<script type="application/ld+json" id="atshift-deft-json-ld">' . $json . '</script>' . "\n";
    }
}
