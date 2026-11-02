<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class PostTypes {
    public static function public_type( $name ) {
        if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/D', $name ) ) return null;
        $type = get_post_type_object( $name );
        if ( ! $type || ( $type->_builtin && ! in_array( $name, array( 'post', 'page' ), true ) ) ||
            'atshde_article' === $name || ! $type->public || ! is_post_type_viewable( $type ) ||
            ! post_type_supports( $name, 'title' ) || ! post_type_supports( $name, 'editor' ) ) return null;
        return $type;
    }

    private static function legacy_post( $type ) {
        // Preserve DeFt's existing, scoped post permission; never extend it to pages or custom types.
        return 'post' === $type->name && 'edit_posts' === $type->cap->create_posts &&
            'edit_posts' === $type->cap->edit_posts && 'publish_posts' === $type->cap->publish_posts;
    }

    public static function can_select( $name, $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id(); $type = self::public_type( $name );
        return $type && Access::workspace( $user_id ) && ( self::legacy_post( $type ) ||
            ( user_can( $user_id, $type->cap->create_posts ) && user_can( $user_id, $type->cap->edit_posts ) ) );
    }

    public static function can_publish( $name, $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id(); $type = self::public_type( $name );
        return $type && Access::publisher( $user_id ) && self::can_select( $name, $user_id ) &&
            ( self::legacy_post( $type ) || user_can( $user_id, $type->cap->publish_posts ) );
    }

    public static function can_publish_post( $post ) {
        if ( ! $post instanceof \WP_Post || ! self::can_publish( $post->post_type ) ) return false;
        $type = self::public_type( $post->post_type );
        return self::legacy_post( $type ) || current_user_can( $type->cap->edit_post, $post->ID );
    }

    public static function listing() {
        if ( ! Access::workspace() ) return Access::denied();
        $items = array();
        foreach ( get_post_types( array(), 'objects' ) as $type ) {
            if ( ! self::can_select( $type->name ) ) continue;
            $items[] = array( 'postType' => $type->name, 'label' => $type->labels->name,
                'singularLabel' => $type->labels->singular_name, 'description' => $type->description,
                'hierarchical' => (bool) $type->hierarchical, 'accountCanPublish' => self::can_publish( $type->name ) );
        }
        usort( $items, static function( $a, $b ) { return strcmp( $a['postType'], $b['postType'] ); } );
        return array( 'items' => $items, 'defaultPostType' => 'post', 'selection' => 'set-publication.postType',
            'createsPostTypes' => false, 'supportsParentSelection' => false, 'supportsCustomFields' => false );
    }
}
