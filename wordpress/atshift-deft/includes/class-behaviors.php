<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

/** Only shipped, immutable assets are executable. Catalog requests cannot add code. */
final class Behaviors {
    public static function registry() {
        $enabled = array( 'type' => 'boolean' );
        $items = array(
            'fade-in' => array( 'name' => 'Gentle appearance', 'implementation' => 'css', 'targetType' => 'root',
                'purpose' => 'Optional, short appearance animation. Content remains visible without motion.',
                'settingsSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'enabled', 'durationMs' ),
                    'properties' => array( 'enabled' => $enabled, 'durationMs' => array( 'type' => 'integer', 'enum' => array( 100, 200, 250, 400, 600 ) ) ) ),
                'defaults' => array( 'enabled' => true, 'durationMs' => 250 ), 'files' => array( 'fade-in-v1.css' ) ),
            'image-zoom' => array( 'name' => 'Enlarge image', 'implementation' => 'javascript', 'targetType' => 'image',
                'purpose' => 'Open a larger image in a keyboard-accessible dialog. The original image remains available without JS.',
                'settingsSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'enabled', 'showCaption' ),
                    'properties' => array( 'enabled' => $enabled, 'showCaption' => array( 'type' => 'boolean' ) ) ),
                'defaults' => array( 'enabled' => true, 'showCaption' => true ), 'files' => array( 'image-zoom-v1.css', 'image-zoom-v1.js' ) ),
        );
        $manifest_file = dirname( __DIR__ ) . '/behaviors/manifest.json';
        $manifest = is_file( $manifest_file ) ? json_decode( file_get_contents( $manifest_file ), true ) : array();
        foreach ( $items as $id => &$item ) {
            $item['id'] = $id; $item['version'] = '1.0.0'; $item['contractVersion'] = 1;
            $bytes = array(); $available = true;
            foreach ( $item['files'] as $file ) {
                $bytes[$file] = $manifest[$file] ?? '';
                $path = dirname( __DIR__ ) . '/behaviors/' . $file;
                if ( ! preg_match( '/^[a-f0-9]{64}$/D', $bytes[$file] ) || ! is_file( $path ) || ! hash_equals( $bytes[$file], hash_file( 'sha256', $path ) ) ) $available = false;
            }
            $item['revision'] = hash( 'sha256', Snapshot::json( array( $item, $bytes ) ) );
            $item['assetsAvailable'] = $available;
            $item['dependencies'] = array(); $item['externalRequests'] = false;
        }
        return $items;
    }

    public static function state() {
        return get_option( 'atshde_behavior_state', array( 'version' => 0, 'disabled' => array() ) );
    }

    public static function active( $id, $revision ) {
        $entry = self::registry()[$id] ?? null;
        return $entry && $entry['assetsAvailable'] && hash_equals( $entry['revision'], $revision ) && ! in_array( $id . '@' . $revision, self::state()['disabled'], true );
    }

    public static function listing() {
        $items = self::registry();
        foreach ( $items as &$item ) { $item['enabled'] = self::active( $item['id'], $item['revision'] ); unset( $item['files'] ); }
        return array( 'items' => array_values( $items ), 'version' => self::state()['version'],
            'selectionOrder' => array( 'native-html', 'css', 'javascript' ),
            'native' => array( 'qa' => array( 'presentations' => array( 'list', 'accordion' ), 'javascriptRequired' => false ) ),
            'canRegisterCode' => false, 'canManageAvailability' => current_user_can( 'manage_options' ) );
    }

    public static function availability( $request ) {
        if ( ! Access::workspace() || ! current_user_can( 'manage_options' ) ) return Access::denied();
        global $wpdb;
        $lock = 'sf_behavior_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 48 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Behavior settings are busy.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 1024 ) throw new \InvalidArgumentException( __( 'Use JSON within 1 KiB.', 'atshift-deft' ) );
            $body = json_decode( $request->get_body(), false, 32, JSON_THROW_ON_ERROR );
            Snapshot::shape( $body, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'revision', 'enabled', 'expectedVersion' ), 'properties' => array(
                'revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'enabled' => array( 'type' => 'boolean' ),
                'expectedVersion' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991 ) ) ) );
            $entry = self::registry()[$request['id']] ?? null;
            if ( ! $entry || $entry['revision'] !== $body->revision ) throw new \InvalidArgumentException( __( 'Unknown behavior revision.', 'atshift-deft' ) );
            // Refresh the option after acquiring the cross-request lock.
            wp_cache_delete( 'atshde_behavior_state', 'options' );
            $state = self::state();
            if ( $state['version'] !== $body->expectedVersion ) return new \WP_Error( 'sf_conflict', __( 'Behavior availability changed. Reload before editing.', 'atshift-deft' ), array( 'status' => 409 ) );
            $key = $request['id'] . '@' . $body->revision;
            $disabled = array_values( array_diff( $state['disabled'], array( $key ) ) );
            if ( ! $body->enabled ) $disabled[] = $key;
            sort( $disabled, SORT_STRING );
            if ( $disabled !== $state['disabled'] ) {
                $state = array( 'version' => $state['version'] + 1, 'disabled' => $disabled, 'updatedBy' => get_current_user_id(), 'updatedAt' => current_time( 'mysql', true ) );
                if ( ! update_option( 'atshde_behavior_state', $state, false ) ) throw new \RuntimeException( __( 'Behavior availability was not saved.', 'atshift-deft' ) );
            }
            return self::listing();
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_behavior', $error->getMessage(), array( 'status' => 400 ) ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function bindings_schema() {
        return Snapshot::schema( 3 )['properties']['components']['items']['properties']['behaviors'];
    }

    public static function validate( $component, $bindings ) {
        $keys = $targets = array(); $registry = self::registry();
        foreach ( $component->behaviors ?? array() as $ref ) {
            $entry = $registry[$ref->id] ?? null;
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! $entry || ! hash_equals( $entry['revision'], $ref->revision ) ) throw new \InvalidArgumentException( __( 'Unknown behavior revision. Use the exact registry reference.', 'atshift-deft' ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( isset( $keys[$ref->key] ) || isset( $targets[$ref->id . ':' . $ref->target] ) ) throw new \InvalidArgumentException( __( 'Duplicate behavior key or target.', 'atshift-deft' ) );
            $keys[$ref->key] = $targets[$ref->id . ':' . $ref->target] = true;
            Snapshot::shape( $ref->settings, $entry['settingsSchema'] );
            if ( 'root' === $entry['targetType'] ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                if ( 'root' !== $ref->target ) throw new \InvalidArgumentException( __( 'This behavior targets the component root.', 'atshift-deft' ) );
            } else {
                $el = $bindings[$ref->target] ?? null;
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                if ( ! $el || 'img' !== $el->tagName ) throw new \InvalidArgumentException( __( 'Image enlargement requires an image field target.', 'atshift-deft' ) );
                for ( $parent = $el->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                    if ( 'a' === $parent->tagName ) throw new \InvalidArgumentException( __( 'A linked image cannot also be an enlargement button.', 'atshift-deft' ) );
                }
            }
        }
    }

    public static function assert_available( $component ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        foreach ( $component->behaviors ?? array() as $ref ) if ( ! self::active( $ref->id, $ref->revision ) ) throw new \InvalidArgumentException( __( 'This behavior revision is stopped. Do not introduce it in a new component revision.', 'atshift-deft' ) );
    }

    public static function settings( $component, $block ) {
        $overrides = get_object_vars( $block->behaviorSettings ?? new \stdClass() ); $out = array();
        foreach ( $component->behaviors ?? array() as $ref ) {
            $settings = $overrides[$ref->key] ?? $ref->settings;
            Snapshot::shape( $settings, self::registry()[$ref->id]['settingsSchema'] );
            $out[$ref->key] = $settings; unset( $overrides[$ref->key] );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( $overrides ) throw new \InvalidArgumentException( __( 'Settings reference a behavior not bound to this component.', 'atshift-deft' ) );
        return $out;
    }

    public static function decorate( $component, $block, $root, $bindings ) {
        $settings = self::settings( $component, $block );
        foreach ( $component->behaviors ?? array() as $ref ) {
            $s = $settings[$ref->key]; if ( ! $s->enabled ) continue;
            if ( 'fade-in' === $ref->id ) { $root->setAttribute( 'data-sf-fade', (string) $s->durationMs ); $root->setAttribute( 'data-sf-fade-revision', $ref->revision ); }
            if ( 'image-zoom' === $ref->id ) { $bindings[$ref->target]->setAttribute( 'data-sf-zoom', $s->showCaption ? 'caption' : 'image' ); $bindings[$ref->target]->setAttribute( 'data-sf-zoom-revision', $ref->revision ); }
        }
    }

    public static function warnings( $snapshot ) {
        $out = array();
        foreach ( $snapshot->components as $c ) foreach ( $c->behaviors ?? array() as $ref ) {
            if ( ! self::active( $ref->id, $ref->revision ) ) $out[$ref->id . '@' . $ref->revision] = 'Behavior stopped or unavailable: ' . $ref->id . '. Static content is retained.';
        }
        return array_values( $out );
    }

    public static function preview( $html, $snapshot ) {
        if ( ! array_filter( $snapshot->components, static function( $c ) { return ! empty( $c->behaviors ); } ) ) return $html;
        $dom = new \DOMDocument(); $old = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $old ); $needed = array();
        foreach ( $dom->getElementsByTagName( '*' ) as $el ) foreach ( array( 'fade-in' => 'fade', 'image-zoom' => 'zoom' ) as $id => $marker ) {
            $attr = 'data-sf-' . $marker;
            if ( ! $el->hasAttribute( $attr ) ) continue;
            $revision = $el->getAttribute( $attr . '-revision' );
            if ( self::active( $id, $revision ) ) $needed[$id] = true;
            else { $el->removeAttribute( $attr ); $el->removeAttribute( $attr . '-revision' ); }
        }
        foreach ( array_keys( $needed ) as $id ) {
            $entry = self::registry()[$id];
            foreach ( $entry['files'] as $file ) {
                $handle = 'atshift-deft-behavior-' . $id . '-' . substr( $entry['revision'], 0, 12 );
                $url = plugins_url( 'behaviors/' . $file, dirname( __DIR__ ) . '/atshift-deft.php' );
                if ( str_ends_with( $file, '.css' ) ) wp_enqueue_style( $handle, $url, array(), $entry['revision'] );
                else {
                    wp_enqueue_script( $handle, $url, array(), $entry['revision'], array( 'strategy' => 'defer', 'in_footer' => true ) );
                    wp_add_inline_script( $handle, 'window.sfBehaviorLabels=' . wp_json_encode( array( 'enlarge' => __( 'Enlarge image', 'atshift-deft' ), 'close' => __( 'Close image', 'atshift-deft' ) ) ) . ';', 'before' );
                }
            }
        }
        $html = ''; foreach ( $dom->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) $html .= $dom->saveHTML( $node );
        return $html;
    }
}
