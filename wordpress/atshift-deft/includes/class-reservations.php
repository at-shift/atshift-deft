<?php
namespace Atshift\Deft;
defined( 'ABSPATH' ) || exit;

final class Reservations {
    const STATUS = 'atshde_reserved';
    const INACTIVE = 'atshde_reserve_hold';
    private static $writing = false;

    public static function register() {
        // Do not use core's future status: it can publish without DeFt's authorization checks.
        register_post_status( self::STATUS, array( 'label' => __( 'Scheduled (DeFt)', 'atshift-deft' ),
            'public' => false, 'protected' => true, 'internal' => false, 'exclude_from_search' => true,
            'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
            /* translators: %s: number of scheduled destination entries. */
            'label_count' => _n_noop( 'Scheduled (DeFt) <span class="count">(%s)</span>', 'Scheduled (DeFt) <span class="count">(%s)</span>', 'atshift-deft' ) ) );
        register_post_status( self::INACTIVE, array( 'label' => __( 'Inactive DeFt reservation', 'atshift-deft' ),
            'public' => false, 'internal' => true, 'exclude_from_search' => true,
            'show_in_admin_all_list' => false, 'show_in_admin_status_list' => false ) );
    }

    public static function managed( $post ) {
        return $post instanceof \WP_Post && in_array( $post->post_status, array( self::STATUS, self::INACTIVE ), true ) &&
            (bool) get_post_meta( $post->ID, '_atshde_publication', true );
    }

    public static function write( $callback ) {
        $previous = self::$writing; self::$writing = true;
        try { return $callback(); } finally { self::$writing = $previous; }
    }

    public static function capabilities( $caps, $cap, $user_id, $args ) {
        if ( ! self::$writing && in_array( $cap, array( 'edit_post', 'edit_page', 'delete_post', 'delete_page' ), true ) &&
            isset( $args[0] ) && self::managed( get_post( $args[0] ) ) ) return array( 'do_not_allow' );
        return $caps;
    }

    public static function block_edit( $empty, $postarr ) {
        return $empty || ( ! self::$writing && ! empty( $postarr['ID'] ) && self::managed( get_post( $postarr['ID'] ) ) );
    }

    public static function post_states( $states, $post ) {
        if ( self::STATUS === $post->post_status ) $states['atshde_reserved'] = __( 'Scheduled (DeFt)', 'atshift-deft' );
        return $states;
    }

    public static function date_status( $status, $post ) {
        return self::STATUS === $post->post_status ? esc_html__( 'Scheduled (DeFt)', 'atshift-deft' ) : $status;
    }

    public static function actions( $actions, $post ) {
        if ( ! self::managed( $post ) ) return $actions;
        unset( $actions['edit'], $actions['inline hide-if-no-js'], $actions['trash'], $actions['view'] );
        $job = Publications::by_publication( get_post_meta( $post->ID, '_atshde_publication', true ) );
        if ( $job && Publications::matches_post( $job, $post ) && Access::article( $job['article_id'] ) ) {
            $url = add_query_arg( array( 'page' => 'structured-fields', 'sf_article' => $job['article_id'] ), admin_url( 'admin.php' ) );
            $actions['sf_manage'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage reservation in DeFt', 'atshift-deft' ) . '</a>';
        }
        return $actions;
    }

    public static function reserve( $job, $row, $old = null ) {
        global $wpdb;
        $post_id = 0;
        $terms = json_decode( $job['manifest'], true )['taxonomies'] ?? null;
        $tables = array( Publications::table(), $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships );
        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Publications::set catches this as a plain-text REST error.
            if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) throw new \RuntimeException( __( 'InnoDB is required.', 'atshift-deft' ) );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Publications::set catches this as a plain-text REST error.
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) throw new \RuntimeException( __( 'Could not start publication transaction.', 'atshift-deft' ) );
        try {
            $previous = $old && $old['post_id'] ? get_post( (int) $old['post_id'] ) : null;
            if ( self::managed( $previous ) && Publications::matches_post( $old, $previous ) ) $post_id = $previous->ID;
            $data = array( 'post_type' => $job['post_type'], 'post_status' => self::STATUS, 'post_author' => $job['owner_id'],
                'post_title' => Snapshot::post_title( json_decode( $row['payload'] )->article->title ), 'post_content' => '', 'post_excerpt' => '',
                'post_date_gmt' => gmdate( 'Y-m-d H:i:s', (int) $job['publish_at'] ),
                'post_date' => wp_date( 'Y-m-d H:i:s', (int) $job['publish_at'], wp_timezone() ),
                'comment_status' => 'closed', 'ping_status' => 'closed', 'meta_input' => array( '_atshde_publication' => $job['publication_id'] ) );
            if ( $post_id ) $data['ID'] = $post_id;
            $post = self::write( static fn() => wp_insert_post( wp_slash( $data ), true ) );
            if ( is_wp_error( $post ) || ! $post ) throw new \RuntimeException( __( 'Could not create reservation post.', 'atshift-deft' ) );
            $post_id = (int) $post; $job['post_id'] = $post_id;
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; callers JSON-encode or escape the message.
            if ( ! Publications::matches_post( $job, get_post( $post_id ) ) || self::STATUS !== get_post_status( $post_id ) ) throw new \RuntimeException( __( 'The reservation post could not be verified.', 'atshift-deft' ) );
            if ( $terms ) Taxonomies::apply( $post_id, $job['post_type'], $terms );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; callers JSON-encode or escape the message.
            if ( 1 !== $wpdb->update( Publications::table(), array( 'post_id' => $post_id ), array( 'article_id' => $job['article_id'], 'version' => $job['version'] ) ) ||
                false === $wpdb->query( 'COMMIT' ) ) throw new \RuntimeException( __( 'Could not attach reservation post.', 'atshift-deft' ) );
            return $job;
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            if ( $post_id ) clean_post_cache( $post_id );
            throw $error;
        }
    }

    public static function hold( $job ) {
        if ( ! $job || ! $job['post_id'] || 'published' === $job['state'] ) return;
        $post = get_post( (int) $job['post_id'] );
        if ( ! $post || self::STATUS !== $post->post_status || ! Publications::matches_post( $job, $post ) ) return;
        self::write( static fn() => wp_update_post( array( 'ID' => $post->ID, 'post_status' => self::INACTIVE ), true ) );
    }

    public static function draft_changed( $id, $version ) {
        $job = Publications::read( $id );
        if ( $job && 'scheduled' === $job['state'] && (int) $job['article_version'] !== (int) $version ) self::hold( $job );
    }
}
