<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class Access {
    public static function secure_transport() {
        if ( is_ssl() ) return true;
        return 'local' === wp_get_environment_type() && 'http' === wp_parse_url( home_url(), PHP_URL_SCHEME ) &&
            in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '[::1]' ), true ) &&
            in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true );
    }

    public static function install() {
        // Never assign this role to existing accounts or change their capabilities.
        add_role( 'sf_author', 'atshift DEFT author', array( 'read' => true, 'upload_files' => true,
            'sf_edit_drafts' => true, 'sf_review_drafts' => true ) );
        add_role( 'sf_designer', 'atshift DEFT designer', array( 'read' => true, 'upload_files' => true,
            'sf_edit_drafts' => true, 'sf_review_drafts' => true, 'sf_register_components' => true, 'sf_manage_components' => true ) );
        add_role( 'sf_publisher', 'atshift DEFT publisher', array( 'read' => true, 'upload_files' => true,
            'sf_edit_drafts' => true, 'sf_review_drafts' => true, 'sf_publish_articles' => true ) );
        update_option( 'sf_access_schema_version', 3, false );
    }

    public static function workspace( $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'sf_edit_drafts' ) );
    }

    public static function update_brand_labels() {
        $roles = wp_roles();
        foreach ( array( 'author', 'designer', 'publisher' ) as $name ) {
            $key = 'sf_' . $name;
            if ( ! isset( $roles->roles[ $key ] ) ) continue;
            $roles->roles[ $key ]['name'] = 'atshift DEFT ' . $name;
            $roles->role_names[ $key ] = 'atshift DEFT ' . $name;
        }
        // Rename display labels only; preserve every existing role capability.
        update_option( $roles->role_key, $roles->roles );
        update_option( 'sf_brand_version', 1, false );
    }

    public static function reviewer( $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        return self::workspace( $user_id ) && user_can( $user_id, 'upload_files' ) &&
            ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'sf_review_drafts' ) );
    }

    public static function publisher( $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        return self::reviewer( $user_id ) && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'sf_publish_articles' ) );
    }

    public static function owner( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $id ) ) return 0;
        global $wpdb; $table = Store::table();
        // The first saved creator is immutable, including for pre-alpha.5 articles.
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE article_id=%s ORDER BY version ASC LIMIT 1', $table, $id ) );
    }

    public static function article( $id, $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        return self::workspace( $user_id ) && self::owner( $id ) === (int) $user_id;
    }

    public static function denied() {
        return new \WP_Error( 'sf_forbidden', __( 'This article is not available to this WordPress account.', 'atshift-deft' ), array( 'status' => 403 ) );
    }

    public static function components( $snapshot, $current ) {
        if ( current_user_can( 'manage_options' ) ) return true;
        $allowed = array();
        if ( $current ) {
            foreach ( json_decode( $current['payload'] )->components as $c ) $allowed[ $c->id . '@' . $c->revision ] = true;
        }
        foreach ( $snapshot->components as $c ) {
            if ( ! isset( $allowed[ $c->id . '@' . $c->revision ] ) && ! Catalog::available( $c->id, $c->revision ) ) return false;
        }
        return true;
    }

    public static function preview_caps( $caps, $cap, $user_id, $args ) {
        if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) || 'sf_article' !== get_post_type( $args[0] ) ) return $caps;
        // Compare exact unslashed tokens; never turn malformed IDs into valid ones.
        $post_id = is_string( $_GET['p'] ?? null ) ? wp_unslash( $_GET['p'] ) : '';
        $preview = is_string( $_GET['preview'] ?? null ) ? wp_unslash( $_GET['preview'] ) : '';
        $version = is_string( $_GET['sf_version'] ?? null ) ? wp_unslash( $_GET['sf_version'] ) : '';
        $nonce = is_string( $_GET['_sf_nonce'] ?? null ) ? wp_unslash( $_GET['_sf_nonce'] ) : '';
        // Core draft queries need edit_post; grant it only for this exact read-only preview.
        if ( 'delete_post' === $cap || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
            ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ||
            (string) $args[0] !== $post_id || 'true' !== $preview ) return array( 'do_not_allow' );
        global $wpdb; $table = Store::table();
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id=%d', $table, $args[0] ), ARRAY_A );
        if ( ! $row || ! self::article( $row['article_id'], $user_id ) || (int) $user_id !== get_current_user_id() ||
            (string) $row['version'] !== $version ||
            ! wp_verify_nonce( $nonce, 'sf-preview-' . $row['post_id'] . '-' . $row['digest'] ) ) return array( 'do_not_allow' );
        return array( 'read' );
    }
}
