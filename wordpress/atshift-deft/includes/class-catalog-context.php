<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class CatalogContext {
    public static function reuse_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'catalogToken', 'considered', 'reason' ), 'properties' => array(
            'catalogToken' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
            'reason' => array( 'type' => 'string', 'minLength' => 10, 'maxLength' => 1000 ),
            'considered' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'id', 'revision' ), 'properties' => array( 'id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ), 'revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ) ) ),
        ) );
    }

    private static function token( $rows ) {
        $state = array_map( static function( $r ) { return array( $r['component_id'], $r['revision'], (int) $r['version'] ); }, $rows );
        return hash_hmac( 'sha256', Snapshot::json( array( get_current_blog_id(), get_current_user_id(), $state ) ), wp_salt( 'auth' ) );
    }

    public static function check_reuse( $reuse ) {
        $rows = Catalog::visible_rows();
        if ( ! is_object( $reuse ) || ! isset( $reuse->catalogToken, $reuse->considered, $reuse->reason ) || ! hash_equals( self::token( $rows ), $reuse->catalogToken ) ) return new \WP_Error( 'sf_catalog_stale', __( 'Read the current catalog context before creating or revising a component.', 'atshift-deft' ), array( 'status' => 409 ) );
        if ( strlen( trim( $reuse->reason ) ) < 10 || ( $rows && ! $reuse->considered ) ) return new \WP_Error( 'sf_reuse_required', __( 'Consider existing components and explain the missing capability.', 'atshift-deft' ), array( 'status' => 400 ) );
        $allowed = array(); foreach ( $rows as $r ) $allowed[$r['component_id'] . '@' . $r['revision']] = true;
        $seen = array();
        foreach ( $reuse->considered as $ref ) {
            $key = $ref->id . '@' . $ref->revision;
            if ( ! isset( $allowed[$key] ) || isset( $seen[$key] ) ) return new \WP_Error( 'sf_reuse_reference', __( 'Use distinct, current, visible catalog references.', 'atshift-deft' ), array( 'status' => 400 ) );
            $seen[$key] = true;
        }
        return true;
    }

    private static function language( $request, $key ) {
        $value = $request->get_param( $key ) ?? '';
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! is_string( $value ) || ( '' !== $value && ! preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8}){0,3}$/D', $value ) ) ) throw new \InvalidArgumentException( __( 'Use a language tag such as ja, en-US or pt-BR.', 'atshift-deft' ) );
        return str_replace( '_', '-', $value );
    }

    public static function get( $request ) {
        if ( ! Access::workspace() ) return Access::denied();
        try {
            $reply = self::language( $request, 'replyLanguage' ); $conversation = self::language( $request, 'conversationLanguage' );
            $content = self::language( $request, 'contentLanguage' ); $locale = str_replace( '_', '-', get_user_locale() );
            $query = $request->get_param( 'query' ) ?? '';
            if ( ! is_string( $query ) || mb_strlen( $query ) > 200 ) throw new \InvalidArgumentException( __( 'Query must be at most 200 characters.', 'atshift-deft' ) );
            $rows = Catalog::visible_rows(); $items = array_map( static function( $r ) { return Catalog::response( $r, false ); }, $rows );
            $terms = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ), 8, PREG_SPLIT_NO_EMPTY );
            $matches = array_values( array_filter( $items, static function( $item ) use ( $terms ) {
                if ( ! $terms ) return true;
                $haystack = mb_strtolower( Snapshot::json( array( $item['name'], $item['purpose'], $item['guidance'], $item['fields'] ) ) );
                foreach ( $terms as $term ) if ( str_contains( $haystack, $term ) ) return true;
                return false;
            } ) );
            $article = null; $id = $request->get_param( 'articleId' );
            if ( null !== $id ) {
                if ( ! is_string( $id ) || ! Access::article( $id ) ) return Access::denied();
                $row = Store::read( $id );
                $article = array( 'snapshot' => json_decode( $row['payload'] ), 'expectedVersion' => (int) $row['version'] );
            }
            return array( 'schemaVersion' => 1, 'pluginVersion' => VERSION, 'instructions' => file_get_contents( dirname( __DIR__ ) . '/AI-GUIDE.md' ),
                'language' => array( 'replyLanguage' => $reply ?: ( $conversation ?: $locale ), 'source' => $reply ? 'explicit' : ( $conversation ? 'conversation' : 'wordpress-user-locale' ),
                    'userLocale' => $locale, 'contentLanguage' => $content ?: null, 'countryInference' => false ),
                'capabilities' => array( 'createStructure' => true, 'createPersonal' => true, 'registerShared' => Catalog::can_register(), 'manageShared' => Catalog::can_manage(), 'publish' => false ),
                'limits' => array( 'personalComponents' => 20, 'sharedComponentsPerCreator' => Catalog::shared_limit( get_current_user_id() ),
                    'privateMediaBytesPerUser' => Assets::user_limit( get_current_user_id() ), 'siteComponents' => 100, 'versionsPerComponent' => 30, 'fieldsPerPersonalComponent' => 12 ),
                'catalogToken' => self::token( $rows ), 'components' => $items, 'matches' => $matches, 'matching' => 'lexical-hint-not-semantic-judgment',
                'usedComponents' => array_values( array_filter( $items, static function( $item ) { return $item['usedByMe']; } ) ),
                'sharedComponents' => array_values( array_filter( $items, static function( $item ) { return 'shared' === $item['scope']; } ) ),
                'selectionOrder' => array( 'usedComponents', 'sharedComponents', 'createStructure' ),
                'behaviors' => Behaviors::listing(),
                'schemas' => Catalog::schemas(), 'structureExample' => ComponentBuilder::structure_example(), 'personalExample' => ComponentBuilder::example(), 'currentArticle' => $article,
                'composeTemplate' => $article ? array( 'expectedVersion' => $article['expectedVersion'], 'article' => (object) array_merge( get_object_vars( $article['snapshot']->article ), array( 'revision' => $article['snapshot']->article->revision + 1 ) ) ) :
                    array( 'expectedVersion' => 0, 'article' => array( 'id' => 'article-' . wp_generate_uuid4(), 'revision' => 1, 'title' => 'Untitled article', 'blocks' => array() ) ),
                'transport' => array( 'apiRoot' => rest_url( 'structured-fields/v1/' ), 'connectedToAI' => false, 'credentialsIncluded' => false ),
            );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_context', $error->getMessage(), array( 'status' => 400 ) ); }
    }
}
