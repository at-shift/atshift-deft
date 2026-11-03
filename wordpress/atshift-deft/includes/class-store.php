<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class Store {
    public static function title( $title, $post_id = 0 ) {
        // Only DeFt supplies a replacement title. Preserve other plugins' filter output verbatim;
        // their HTML/plain-text sink owns escaping, and globally escaping here breaks that contract.
        if ( ! $post_id ) return $title;
        $post = get_post( $post_id );
        if ( ! $post ) return $title;
        if ( 'atshde_article' === $post->post_type ) {
            global $wpdb; $table = self::table();
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT payload FROM %i WHERE post_id=%d', $table, $post_id ), ARRAY_A );
            return Snapshot::post_title( json_decode( $row['payload'] ?? '{}' )->article->title ?? $post->post_title );
        }
        $publication = get_post_meta( $post_id, '_atshde_publication', true );
        if ( ! $publication ) return $title;
        $job = Publications::by_publication( $publication );
        $row = $job && Publications::matches_post( $job, $post ) ? self::read( $job['article_id'], (int) $job['article_version'] ) : null;
        $canonical = json_decode( $row['payload'] ?? '{}' )->article->title ?? null;
        // Old raw titles and new encoded titles share the same canonical display.
        if ( is_string( $canonical ) && in_array( $post->post_title, array( $canonical, trim( $canonical ), Snapshot::post_title( $canonical ), trim( Snapshot::post_title( $canonical ) ) ), true ) ) return Snapshot::post_title( $canonical );
        return Snapshot::post_title( $post->post_title );
    }
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'atshde_snapshots';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            article_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            version bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            digest char(64) NOT NULL,
            payload longtext NOT NULL,
            rendered longtext NOT NULL,
            css longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (article_id,version),
            UNIQUE KEY post_id (post_id)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
    }

    public static function read( $id, $version = null ) {
        global $wpdb;
        $table = self::table();
        if ( null === $version ) return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s ORDER BY version DESC LIMIT 1', $table, $id ), ARRAY_A );
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s AND version=%d', $table, $id, $version ), ARRAY_A );
    }

    public static function preview_url( $row ) {
        return add_query_arg( array( 'post_type' => 'atshde_article', 'p' => (int) $row['post_id'], 'preview' => 'true',
            'sf_version' => (int) $row['version'], '_sf_nonce' => wp_create_nonce( 'sf-preview-' . $row['post_id'] . '-' . $row['digest'] ) ), home_url( '/' ) );
    }

    public static function response( $row, $full = true ) {
        $snapshot = json_decode( $row['payload'] );
        $result = array( 'articleId' => $row['article_id'], 'version' => (int) $row['version'], 'postId' => (int) $row['post_id'],
            'revision' => $snapshot->article->revision, 'title' => $snapshot->article->title, 'digest' => $row['digest'],
            'previewUrl' => self::preview_url( $row ), 'createdAt' => $row['created_at'], 'publishAuthorized' => false );
        $result['behaviorWarnings'] = Behaviors::warnings( $snapshot );
        $result = array_merge( $result, Drafts::state( $row['article_id'] ) );
        if ( 'draft' !== $result['draftState'] ) unset( $result['previewUrl'] );
        if ( $full ) $result['snapshot'] = $snapshot;
        return $result;
    }

    public static function listing( $state = 'draft' ) {
        global $wpdb;
        $table = self::table();
        if ( ! Access::workspace() ) return array();
        if ( ! in_array( $state, array( 'draft', 'trash' ), true ) ) return new \WP_Error( 'sf_draft_state', __( 'Choose drafts or Trash.', 'atshift-deft' ), array( 'status' => 400 ) );
        // Filter before the limit; a destination reservation is not another private draft.
        // Post-type identifiers must match exactly even when core and plugin columns use different collations.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM %i s INNER JOIN (SELECT article_id,MAX(version) AS version,MIN(version) AS first_version FROM %i GROUP BY article_id) latest ON s.article_id=latest.article_id AND s.version=latest.version INNER JOIN %i owner ON owner.article_id=s.article_id AND owner.version=latest.first_version LEFT JOIN %i lifecycle ON lifecycle.article_id=s.article_id LEFT JOIN %i publication ON publication.article_id=s.article_id LEFT JOIN %i reserved ON reserved.ID=publication.post_id WHERE owner.created_by=%d AND COALESCE(lifecycle.state,%s)=%s AND (%s='trash' OR (COALESCE(publication.state,'')<>'published' AND NOT (COALESCE(publication.state,'') IN ('scheduled','processing') AND publication.article_version=s.version AND reserved.ID IS NOT NULL AND reserved.post_status=%s AND CAST(reserved.post_type AS BINARY)=CAST(publication.post_type AS BINARY) AND reserved.post_author=publication.owner_id))) ORDER BY GREATEST(s.created_at,COALESCE(lifecycle.changed_at,s.created_at)) DESC,s.article_id ASC LIMIT 100", $table, $table, $table, Drafts::table(), Publications::table(), $wpdb->posts, get_current_user_id(), 'draft', $state, $state, Reservations::STATUS ), ARRAY_A );
        return array_map( static function( $row ) { return self::response( $row, false ); }, $rows );
    }

    public static function lock_name( $id ) {
        return substr( 'sf_' . hash( 'sha256', DB_NAME . ':' . self::table() . ':' . $id ), 0, 64 );
    }

    public static function save( $validated, $expected ) {
        global $wpdb;
        $id = $validated['snapshot']->article->id;
        $table = self::table();
        // A per-site, per-article DB lock serializes first inserts as well as updates.
        $lock = self::lock_name( $id );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Another save is in progress.', 'atshift-deft' ), array( 'status' => 409 ) );
        $post_id = 0;
        try {
            $current = self::read( $id );
            if ( ! Access::workspace() || ( $current && ! Access::article( $id ) ) ) return Access::denied();
            if ( ! Access::components( $validated['snapshot'], $current ) ) return new \WP_Error( 'sf_design_forbidden', __( 'Use active catalog components or this article\'s saved component revisions. New designs require an administrator.', 'atshift-deft' ), array( 'status' => 403 ) );
            $version = $current ? (int) $current['version'] : 0;
            if ( $version !== $expected ) return new \WP_Error( 'sf_conflict', __( 'The saved version changed. Reload it before saving.', 'atshift-deft' ), array( 'status' => 409 ) );
            if ( $current && hash_equals( $current['digest'], $validated['digest'] ) ) return self::response( $current );
            if ( $current && $validated['snapshot']->article->revision <= json_decode( $current['payload'] )->article->revision ) return new \WP_Error( 'sf_revision', __( 'Changed content must have a newer article revision.', 'atshift-deft' ), array( 'status' => 409 ) );
            if ( $version >= 100 ) return new \WP_Error( 'sf_limit', __( 'This prototype allows 100 saved versions per article.', 'atshift-deft' ), array( 'status' => 400 ) );
            // Fail closed on non-transactional host configurations rather than orphaning drafts.
            foreach ( array( $table, $wpdb->posts, $wpdb->postmeta, ComponentUsage::table() ) as $name ) {
                $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $name ) );
                if ( 'InnoDB' !== $engine ) return new \WP_Error( 'sf_storage', __( 'InnoDB tables are required.', 'atshift-deft' ), array( 'status' => 503 ) );
            }
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) throw new \RuntimeException( __( 'Could not start transaction.', 'atshift-deft' ) );
            // Each immutable snapshot owns a separate draft used only for authenticated theme preview.
            $post_id = wp_insert_post( wp_slash( array( 'post_type' => 'atshde_article', 'post_status' => 'draft',
                'post_title' => Snapshot::post_title( $validated['snapshot']->article->title ), 'post_content' => '',
                'post_author' => get_current_user_id(), 'comment_status' => 'closed', 'ping_status' => 'closed' ) ), true );
            if ( is_wp_error( $post_id ) ) throw new \RuntimeException( __( 'Could not create preview draft.', 'atshift-deft' ) );
            $ok = $wpdb->insert( $table, array( 'article_id' => $id, 'version' => $version + 1, 'post_id' => $post_id,
                'digest' => $validated['digest'], 'payload' => $validated['payload'], 'rendered' => $validated['html'],
                'css' => $validated['css'], 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
            if ( false === $ok ) throw new \RuntimeException( __( 'Could not save snapshot.', 'atshift-deft' ) );
            ComponentUsage::remember( $validated['snapshot'] );
            if ( false === $wpdb->query( 'COMMIT' ) ) throw new \RuntimeException( __( 'Could not commit snapshot.', 'atshift-deft' ) );
            Reservations::draft_changed( $id, $version + 1 );
            return self::response( self::read( $id, $version + 1 ) );
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            if ( is_int( $post_id ) && $post_id ) clean_post_cache( $post_id );
            return new \WP_Error( 'sf_storage', __( 'The snapshot was not saved.', 'atshift-deft' ), array( 'status' => 500 ) );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
}
