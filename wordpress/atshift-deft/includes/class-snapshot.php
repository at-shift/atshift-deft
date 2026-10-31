<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class Snapshot {
    public static function post_title( $title ) {
        // WordPress titles are HTML sinks even when their source is plain text.
        return htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
    }
    private const TAGS = 'section article aside header footer main div span h2 h3 h4 h5 h6 p br strong em b i u s ul ol li a figure img figcaption blockquote table thead tbody tfoot tr td th caption dl dt dd hr pre code time address';
    private const ATTRS = 'class data-field data-sf-layout href title src alt width height colspan rowspan scope datetime';

    public static function json( $value ) {
        return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR );
    }

    private static function fail( $message ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text validation exception; REST JSON-encodes it and HTML callers escape it at output.
        throw new \InvalidArgumentException( $message );
    }

    // WordPress validates schema constraints; this pass prevents its intentional type coercion.
    private static function strict_types( $value, $schema ) {
        $type = $schema['type'] ?? '';
        $valid = array(
            'object' => is_object( $value ) && $value instanceof \stdClass,
            'array' => is_array( $value ), 'integer' => is_int( $value ),
            'string' => is_string( $value ), 'boolean' => is_bool( $value ),
        );
        if ( empty( $valid[ $type ] ) ) self::fail( sprintf( /* translators: %s: JSON type. */ __( 'Invalid JSON type: %s', 'atshift-deft' ), $type ) );
        if ( 'object' === $type ) {
            foreach ( get_object_vars( $value ) as $key => $child ) {
                $rule = $schema['properties'][ $key ] ?? ( $schema['additionalProperties'] ?? false );
                if ( ! is_array( $rule ) ) self::fail( sprintf( /* translators: %s: property name. */ __( 'Unknown property: %s', 'atshift-deft' ), $key ) );
                if ( isset( $schema['propertyNames']['pattern'] ) && ! preg_match( '~' . $schema['propertyNames']['pattern'] . '~D', $key ) ) self::fail( __( 'Invalid field name.', 'atshift-deft' ) );
                self::strict_types( $child, $rule );
            }
        } elseif ( 'array' === $type ) {
            foreach ( $value as $child ) self::strict_types( $child, $schema['items'] );
        }
    }

    private static function url( $value, $link ) {
        if ( '' === $value ) return true;
        if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) || str_starts_with( $value, '//' ) ) return false;
        $parts = wp_parse_url( $value );
        if ( false === $parts ) return false;
        // Session-bound admin/file URLs are not durable article values.
        if ( str_contains( rawurldecode( $parts['path'] ?? '' ), '/wp-admin/' ) ) return false;
        if ( str_starts_with( $value, '/' ) || ( $link && str_starts_with( $value, '#' ) ) ) return true;
        $scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
        if ( $link && in_array( $scheme, array( 'mailto', 'tel' ), true ) ) return strlen( $value ) > strlen( $scheme ) + 1;
        return in_array( $scheme, array( 'https', 'http' ), true ) && (bool) wp_parse_url( $value, PHP_URL_HOST );
    }

    private static function document( $html ) {
        if ( ! trim( $html ) || preg_match( '/<\s*[!?]|<\s*\/?\s*(html|head|body)\b/i', $html ) ) self::fail( __( 'Use a single component root without document declarations.', 'atshift-deft' ) );
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        $roots = array();
        foreach ( $body->childNodes as $node ) {
            if ( $node instanceof \DOMElement ) $roots[] = $node;
            elseif ( trim( $node->textContent ) ) self::fail( __( 'Text must be inside the component root.', 'atshift-deft' ) );
        }
        if ( 1 !== count( $roots ) ) self::fail( __( 'Use exactly one component root.', 'atshift-deft' ) );
        $bindings = array();
        foreach ( $body->getElementsByTagName( '*' ) as $el ) {
            if ( ! in_array( $el->tagName, explode( ' ', self::TAGS ), true ) ) self::fail( sprintf( /* translators: %s: HTML element name. */ __( 'Unsupported HTML element: %s', 'atshift-deft' ), $el->tagName ) );
            foreach ( $el->attributes as $attr ) {
                if ( ! in_array( $attr->name, explode( ' ', self::ATTRS ), true ) ) self::fail( sprintf( /* translators: %s: HTML attribute name. */ __( 'Unsupported HTML attribute: %s', 'atshift-deft' ), $attr->name ) );
                if ( in_array( $attr->name, array( 'href', 'src' ), true ) && ! self::url( $attr->value, 'href' === $attr->name ) ) self::fail( __( 'Invalid component URL.', 'atshift-deft' ) );
            }
            if ( $el->hasAttribute( 'data-sf-layout' ) && ( $el !== $roots[0] || 'qa' !== $el->getAttribute( 'data-sf-layout' ) ) ) self::fail( __( 'Unsupported layout marker.', 'atshift-deft' ) );
            if ( $el->hasAttribute( 'data-field' ) ) {
                $key = $el->getAttribute( 'data-field' );
                if ( ! preg_match( '/^[a-z][a-z0-9_]*$/D', $key ) || isset( $bindings[ $key ] ) || $el->getElementsByTagName( '*' )->length ) self::fail( __( 'Invalid, repeated or non-leaf binding.', 'atshift-deft' ) );
                $bindings[ $key ] = $el;
            }
        }
        if ( $roots[0]->hasAttribute( 'data-sf-layout' ) ) {
            $children = array();
            foreach ( $roots[0]->childNodes as $child ) if ( $child instanceof \DOMElement || trim( $child->textContent ) ) $children[] = $child;
            if ( 'section' !== $roots[0]->tagName || 2 !== count( $children ) || ! isset( $bindings['question'], $bindings['answer'] ) ||
                $children[0] !== $bindings['question'] || $children[1] !== $bindings['answer'] || 'h3' !== $children[0]->tagName || 'p' !== $children[1]->tagName ) self::fail( __( 'Invalid Q&A layout.', 'atshift-deft' ) );
        }
        return array( $dom, $roots[0], $bindings );
    }

    private static function field_value( $field, $value, $alt ) {
        if ( $field->required && ! trim( $value ) ) self::fail( sprintf( /* translators: %s: field key. */ __( 'Required field is empty: %s', 'atshift-deft' ), $field->key ) );
        if ( in_array( $field->type, array( 'image', 'link' ), true ) ) {
            if ( ! self::url( $value, 'link' === $field->type ) ) self::fail( sprintf( /* translators: %s: field key. */ __( 'Invalid field URL: %s', 'atshift-deft' ), $field->key ) );
            if ( '' !== $value && ! trim( $alt ) ) self::fail( __( 'Image alternative text or link text is required.', 'atshift-deft' ) );
        } elseif ( '' !== $alt ) self::fail( __( 'Plain text fields cannot have alt text.', 'atshift-deft' ) );
    }

    public static function schema( $version = true ) {
        $file = 3 === $version ? 'snapshot-v3.json' : ( $version ? 'snapshot-v2.json' : 'snapshot.json' );
        return json_decode( file_get_contents( dirname( __DIR__ ) . '/schema/' . $file ), true );
    }

    public static function shape( $data, $schema ) {
        self::strict_types( $data, $schema );
        $valid = rest_validate_value_from_schema( $data, $schema, 'input' );
        if ( is_wp_error( $valid ) ) self::fail( $valid->get_error_message() );
    }

    private static function component_data( $c ) {
        $canonical = array();
        foreach ( array( 'id', 'key', 'name', 'purpose', 'html', 'css', 'fields', 'variants' ) as $prop ) $canonical[ $prop ] = $c->$prop;
        $canonical['fields'] = array_map( static function( $f ) {
            $out = array();
            foreach ( array( 'id', 'key', 'label', 'type', 'required', 'value', 'alt' ) as $prop ) $out[ $prop ] = $f->$prop;
            return $out;
        }, $c->fields );
        $canonical['variants'] = array_map( static function( $v ) { return array( 'id' => $v->id, 'name' => $v->name, 'classes' => $v->classes ); }, $c->variants );
        if ( property_exists( $c, 'behaviors' ) ) $canonical['behaviors'] = array_map( static function( $ref ) {
            $settings = get_object_vars( $ref->settings ); ksort( $settings, SORT_STRING );
            return array( 'key' => $ref->key, 'id' => $ref->id, 'revision' => $ref->revision, 'target' => $ref->target, 'settings' => (object) $settings );
        }, $c->behaviors );
        return $canonical;
    }

    public static function component( $input ) {
        if ( ! $input instanceof \stdClass ) self::fail( __( 'Component must be an object.', 'atshift-deft' ) );
        $c = clone $input;
        $calculate = ! property_exists( $c, 'revision' );
        if ( $calculate ) $c->revision = str_repeat( '0', 64 );
        $version = property_exists( $c, 'behaviors' ) ? 3 : 2;
        self::shape( $c, self::schema( $version )['properties']['components']['items'] );
        if ( $calculate ) $c->revision = hash( 'sha256', self::json( self::component_data( $c ) ) );
        // Definitions may have empty slots; required values are enforced on real article blocks.
        $validated = self::validate_data( self::json( array( 'schemaVersion' => $version, 'article' => array(
            'id' => 'component-validation', 'revision' => 1, 'title' => 'Component validation',
            'blocks' => array() ), 'components' => array( $c ) ) ), true );
        return json_decode( $validated['payload'] )->components[0];
    }

    public static function validate( $input ) {
        return self::validate_data( $input, false );
    }

    private static function validate_data( $input, $definition_only ) {
        if ( ! class_exists( '\DOMDocument' ) ) self::fail( __( 'PHP DOM extension is required.', 'atshift-deft' ) );
        if ( ! is_string( $input ) || strlen( $input ) > 4 * 1024 * 1024 ) self::fail( __( 'Snapshot exceeds 4 MB.', 'atshift-deft' ) );
        $data = json_decode( $input, false, 128, JSON_THROW_ON_ERROR );
        $v2 = isset( $data->schemaVersion );
        $version = $v2 && 3 === $data->schemaVersion ? 3 : ( $v2 ? 2 : 1 );
        self::shape( $data, self::schema( $v2 ? $version : false ) );
        if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $data->article->id ) ) self::fail( __( 'Article ID must use letters, digits, underscores or hyphens.', 'atshift-deft' ) );
        $catalog = $normalized_components = array();
        foreach ( $data->components as $c ) {
            $key = $c->id . '@' . $c->revision;
            if ( isset( $catalog[ $key ] ) ) self::fail( __( 'Duplicate component.', 'atshift-deft' ) );
            // Match the browser componentData() property order for cross-runtime SHA-256.
            $canonical = self::component_data( $c );
            if ( ! hash_equals( hash( 'sha256', self::json( $canonical ) ), $c->revision ) ) self::fail( __( 'Component revision mismatch.', 'atshift-deft' ) );
            if ( ! trim( $c->name ) || ! preg_match( '/^[a-z][a-z0-9_]*$/D', $c->key ) ) self::fail( __( 'Invalid component name.', 'atshift-deft' ) );
            // Store::save restricts new designs to administrators; authors use pinned components.
            if ( preg_match( '/@import\b/i', $c->css ) ) self::fail( __( 'External CSS imports are not supported.', 'atshift-deft' ) );
            list( $dom, $root, $bindings ) = self::document( $c->html );
            $ids = $keys = array();
            foreach ( $c->fields as $f ) {
                if ( isset( $ids[ $f->id ] ) || isset( $keys[ $f->key ] ) || ! isset( $bindings[ $f->key ] ) || ! trim( $f->label ) ) self::fail( __( 'Invalid or duplicate field.', 'atshift-deft' ) );
                $ids[ $f->id ] = $keys[ $f->key ] = true;
                $el = $bindings[ $f->key ];
                $tag = $el->tagName;
                if ( ( 'image' === $f->type ) !== ( 'img' === $tag ) || ( 'link' === $f->type ) !== ( 'a' === $tag ) ) self::fail( __( 'Field type does not match its HTML element.', 'atshift-deft' ) );
                $value = 'img' === $tag ? $el->getAttribute( 'src' ) : ( 'a' === $tag ? $el->getAttribute( 'href' ) : $el->textContent );
                $alt = 'img' === $tag ? $el->getAttribute( 'alt' ) : ( 'a' === $tag ? $el->textContent : '' );
                if ( $value !== $f->value || $alt !== $f->alt ) self::fail( __( 'Component example does not match HTML.', 'atshift-deft' ) );
                if ( '' !== $f->value || '' !== $f->alt ) self::field_value( $f, $f->value, $f->alt );
            }
            if ( count( $keys ) !== count( $bindings ) ) self::fail( __( 'Missing field definitions.', 'atshift-deft' ) );
            if ( property_exists( $c, 'behaviors' ) ) Behaviors::validate( $c, $bindings );
            $variants = array();
            foreach ( $c->variants as $v ) {
                if ( isset( $variants[ $v->id ] ) || ! trim( $v->name ) || preg_match( '/["\'<>`=]/', $v->classes ) ) self::fail( __( 'Invalid variant.', 'atshift-deft' ) );
                $variants[ $v->id ] = $v;
            }
            if ( ! $variants ) self::fail( __( 'At least one variant is required.', 'atshift-deft' ) );
            if ( $root->hasAttribute( 'data-sf-layout' ) && ( array_keys( $variants ) !== array( 'list', 'accordion' ) ||
                count( $c->fields ) !== 2 || 'question' !== $c->fields[0]->key || 'answer' !== $c->fields[1]->key ||
                'text' !== $c->fields[0]->type || 'textarea' !== $c->fields[1]->type ) ) self::fail( __( 'Invalid Q&A fields or presentations.', 'atshift-deft' ) );
            $catalog[ $key ] = array( $c, $variants );
            $canonical['revision'] = $c->revision;
            $normalized_components[ $key ] = $canonical;
        }
        $ids = $used = array();
        $html = '';
        $styles = $normalized_blocks = $media = array();
        foreach ( $data->article->blocks as $b ) {
            $key = $b->componentId . '@' . $b->componentRevision;
            if ( isset( $ids[ $b->id ] ) || ! isset( $catalog[ $key ] ) ) self::fail( __( 'Duplicate block or unknown component.', 'atshift-deft' ) );
            $ids[ $b->id ] = $used[ $key ] = true;
            list( $c, $variants ) = $catalog[ $key ];
            if ( ! isset( $variants[ $b->variantId ] ) || count( get_object_vars( $b->values ) ) !== count( $c->fields ) ) self::fail( __( 'Unknown variant or incorrect field set.', 'atshift-deft' ) );
            list( $dom, $root, $bindings ) = self::document( $c->html );
            foreach ( $c->fields as $f ) {
                if ( ! isset( $b->values->{$f->key} ) ) self::fail( __( 'Missing field value.', 'atshift-deft' ) );
                $v = $b->values->{$f->key};
                if ( isset( $v->assetRef ) ) {
                    if ( ! $v2 || '' !== $v->value || ! trim( $v->alt ) ) self::fail( __( 'Media references require schema v2, an empty URL and contextual text.', 'atshift-deft' ) );
                    $media[ Media::key( $b->id, $f->key ) ] = array( 'ref' => $v->assetRef, 'type' => $f->type );
                } else {
                    self::field_value( $f, $v->value, $v->alt );
                }
                $el = $bindings[ $f->key ];
                if ( 'image' === $f->type ) {
                    if ( $v2 && '' === $v->value && ! isset( $v->assetRef ) ) { $el->removeAttribute( 'src' ); $el->setAttribute( 'hidden', '' ); }
                    else $el->setAttribute( 'src', $v->value );
                    $el->setAttribute( 'alt', $v->alt );
                } else {
                    while ( $el->firstChild ) $el->removeChild( $el->firstChild );
                    $el->appendChild( $dom->createTextNode( 'link' === $f->type ? $v->alt : $v->value ) );
                    if ( 'link' === $f->type ) $el->setAttribute( 'href', $v->value );
                }
                $el->removeAttribute( 'data-field' );
                if ( isset( $v->assetRef ) ) {
                    $el->removeAttribute( 'image' === $f->type ? 'src' : 'href' );
                    $el->setAttribute( 'data-sf-media', Media::key( $b->id, $f->key ) );
                }
            }
            $classes = trim( $root->getAttribute( 'class' ) . ' ' . $variants[ $b->variantId ]->classes );
            if ( $classes ) $root->setAttribute( 'class', $classes );
            if ( $root->hasAttribute( 'data-sf-layout' ) ) {
                $root->removeAttribute( 'data-sf-layout' );
                if ( 'accordion' === $b->variantId ) {
                    $details = $dom->createElement( 'details' );
                    foreach ( $root->attributes as $attr ) $details->setAttribute( $attr->name, $attr->value );
                    $summary = $dom->createElement( 'summary' );
                    $question = $bindings['question'];
                    while ( $question->firstChild ) $summary->appendChild( $question->firstChild );
                    $details->appendChild( $summary ); $details->appendChild( $bindings['answer'] );
                    $root->parentNode->replaceChild( $details, $root ); $root = $details;
                }
            }
            if ( 3 === $version ) Behaviors::decorate( $c, $b, $root, $bindings );
            $html .= '<div class="sf-block" data-sf-block="' . esc_attr( $b->id ) . '">' . $dom->saveHTML( $root ) . '</div>';
            $styles[] = $c->css;
            $values = get_object_vars( $b->values );
            ksort( $values, SORT_STRING );
            foreach ( $values as $name => $value ) {
                $values[ $name ] = array( 'value' => $value->value, 'alt' => $value->alt );
                if ( isset( $value->assetRef ) ) $values[ $name ]['assetRef'] = array( 'kind' => $value->assetRef->kind, 'id' => $value->assetRef->id, 'sha256' => $value->assetRef->sha256 );
            }
            $normalized_blocks[] = array( 'id' => $b->id, 'componentId' => $b->componentId, 'componentRevision' => $b->componentRevision,
                'variantId' => $b->variantId, 'values' => (object) $values );
            if ( property_exists( $b, 'behaviorSettings' ) ) {
                $settings = get_object_vars( $b->behaviorSettings ); ksort( $settings, SORT_STRING );
                foreach ( $settings as &$setting ) { $props = get_object_vars( $setting ); ksort( $props, SORT_STRING ); $setting = (object) $props; }
                unset( $setting );
                $normalized_blocks[count( $normalized_blocks ) - 1]['behaviorSettings'] = (object) $settings;
            }
        }
        Media::resolve_batch( $media, $data->article->id );
        if ( ! $definition_only && count( $used ) !== count( $catalog ) ) self::fail( __( 'Only components used by the article may be imported.', 'atshift-deft' ) );
        $css = implode( "\n", $styles );
        // Payload is the canonical editable source; rendered HTML is derived, never client-supplied.
        ksort( $normalized_components, SORT_STRING );
        $normalized = array( 'article' => array( 'id' => $data->article->id, 'revision' => $data->article->revision,
            'title' => $data->article->title, 'blocks' => $normalized_blocks ), 'components' => array_values( $normalized_components ) );
        if ( $v2 ) $normalized = array_merge( array( 'schemaVersion' => $version ), $normalized );
        $payload = self::json( $normalized );
        return array( 'snapshot' => $data, 'payload' => $payload, 'html' => '<div class="sf-article">' . $html . '</div>', 'css' => $css,
            'digest' => hash( 'sha256', 'sf-wp-renderer-v' . $version . ':' . $payload . ':' . $html . ':' . $css ) );
    }
}
