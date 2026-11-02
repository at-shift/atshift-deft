<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

/** Exact legacy identifiers only. Payloads, signing material and protocol URLs never change. */
final class PrefixMigration {
    const TABLES = array( 'snapshots', 'draft_states', 'assets', 'transfers', 'reviews', 'catalog', 'components', 'component_usage', 'connections', 'oauth_clients', 'oauth_tokens', 'oauth_flows', 'publications' );
    const OPTIONS = array( 'access_schema_version', 'assets_schema_version', 'brand_version', 'catalog_schema_version', 'connections_schema_version', 'drafts_schema_version', 'oauth_schema_version', 'publications_schema_version', 'reviews_schema_version', 'transfers_schema_version', 'behavior_state', 'oauth_server_keys' );
    const CAPS = array( 'author', 'designer', 'publisher', 'edit_drafts', 'review_drafts', 'register_components', 'manage_components', 'publish_articles' );
    const MARKER = 'atshde_prefix_migration';

    private static function query( $sql ) {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Private callers provide fixed transaction SQL or complete queries prepared with %i/%s/%d; never request input.
        $result = $wpdb->query( $sql );
        if ( false === $result ) throw new \RuntimeException( 'DeFt data migration failed.' );
        return $result;
    }

    private static function exists( $table ) {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function rename_caps( $caps ) {
        if ( ! is_array( $caps ) ) throw new \RuntimeException( 'Invalid legacy capabilities.' );
        foreach ( self::CAPS as $suffix ) {
            $old = 'sf_' . $suffix; $new = 'atshde_' . $suffix;
            if ( ! array_key_exists( $old, $caps ) ) continue;
            if ( array_key_exists( $new, $caps ) && $caps[$new] !== $caps[$old] ) throw new \RuntimeException( 'Conflicting DeFt capabilities.' );
            $caps[$new] = $caps[$old]; unset( $caps[$old] );
        }
        return $caps;
    }

    public static function run() {
        global $wpdb;
        if ( 'complete' === get_option( self::MARKER ) ) return true;
        $lock = 'atshde_migrate_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 45 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) return false;
        $transaction = false; $users = $posts = array();
        try {
            if ( 'complete' === get_option( self::MARKER ) ) return true;
            $pending = 'pending' === get_option( self::MARKER );
            $legacy = false;
            foreach ( self::TABLES as $suffix ) if ( self::exists( $wpdb->prefix . 'sf_' . $suffix ) ) $legacy = true;
            if ( ! $legacy && ! $pending ) {
                update_option( self::MARKER, 'complete', false );
                return 'complete' === get_option( self::MARKER );
            }
            // Do not claim or merge a foreign lookalike table based on its short prefix alone.
            if ( ! $pending && ( ! get_option( 'sf_access_schema_version' ) || ! self::exists( $wpdb->prefix . 'sf_snapshots' ) || ! self::exists( $wpdb->prefix . 'sf_catalog' ) ) ) throw new \RuntimeException( 'Unrecognized legacy data.' );
            if ( ! $pending ) {
                foreach ( array( 'snapshots' => array( 'article_id', 'version', 'post_id', 'payload', 'digest', 'created_by' ), 'catalog' => array( 'component_id', 'owner_id', 'definition_kind', 'machine_key', 'recipe', 'revision' ) ) as $suffix => $required ) {
                    $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wpdb->prefix . 'sf_' . $suffix ) );
                    if ( array_diff( $required, $columns ) ) throw new \RuntimeException( 'Unrecognized legacy table schema.' );
                }
            }
            $rename = array();
            foreach ( self::TABLES as $suffix ) {
                $old = $wpdb->prefix . 'sf_' . $suffix; $new = $wpdb->prefix . 'atshde_' . $suffix;
                if ( ! self::exists( $old ) ) continue;
                if ( self::exists( $new ) ) throw new \RuntimeException( 'Conflicting DeFt tables.' );
                $rename[] = $wpdb->prepare( '%i TO %i', $old, $new );
            }
            foreach ( array( $wpdb->options, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta ) as $table ) {
                if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ) ) throw new \RuntimeException( 'InnoDB is required for migration.' );
            }
            update_option( self::MARKER, 'pending', false );
            if ( 'pending' !== get_option( self::MARKER ) ) throw new \RuntimeException( 'Migration marker unavailable.' );
            // MySQL renames all listed tables atomically. The marker permits a retry after this DDL commit.
            if ( $rename ) self::query( 'RENAME TABLE ' . implode( ', ', $rename ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each identifier pair is prepared with %i above.
            self::query( 'START TRANSACTION' ); $transaction = true;
            foreach ( self::OPTIONS as $suffix ) {
                $old = 'sf_' . $suffix; $new = 'atshde_' . $suffix;
                $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value,autoload FROM %i WHERE option_name IN (%s,%s) FOR UPDATE', $wpdb->options, $old, $new ), ARRAY_A );
                if ( count( $rows ) > 1 ) throw new \RuntimeException( 'Conflicting DeFt options.' );
                self::query( $wpdb->prepare( 'UPDATE %i SET option_name=%s WHERE option_name=%s', $wpdb->options, $new, $old ) );
            }
            $role_key = $wpdb->prefix . 'user_roles';
            $roles = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, $role_key ) ) );
            if ( ! is_array( $roles ) ) throw new \RuntimeException( 'Roles unavailable.' );
            $roles = self::rename_caps( $roles );
            foreach ( $roles as &$role ) $role['capabilities'] = self::rename_caps( $role['capabilities'] );
            unset( $role );
            self::query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $wpdb->options, maybe_serialize( $roles ), $role_key ) );
            $caps = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id,user_id,meta_value FROM %i WHERE meta_key=%s FOR UPDATE', $wpdb->usermeta, $wpdb->prefix . 'capabilities' ), ARRAY_A );
            foreach ( $caps as $row ) {
                $before = maybe_unserialize( $row['meta_value'] ); $after = self::rename_caps( $before );
                if ( $before === $after ) continue;
                self::query( $wpdb->prepare( 'UPDATE %i SET meta_value=%s WHERE umeta_id=%d', $wpdb->usermeta, maybe_serialize( $after ), $row['umeta_id'] ) );
                $users[] = (int) $row['user_id'];
            }
            $old_welcome = '_sf_ai_welcome_seen_' . get_current_blog_id(); $new_welcome = '_atshde_ai_welcome_seen_' . get_current_blog_id();
            if ( $wpdb->get_var( $wpdb->prepare( 'SELECT a.umeta_id FROM %i a JOIN %i b ON a.user_id=b.user_id WHERE a.meta_key=%s AND b.meta_key=%s LIMIT 1', $wpdb->usermeta, $wpdb->usermeta, $old_welcome, $new_welcome ) ) ) throw new \RuntimeException( 'Conflicting onboarding data.' );
            $users = array_merge( $users, $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE meta_key=%s', $wpdb->usermeta, $old_welcome ) ) );
            self::query( $wpdb->prepare( 'UPDATE %i SET meta_key=%s WHERE meta_key=%s', $wpdb->usermeta, $new_welcome, $old_welcome ) );
            if ( $wpdb->get_var( $wpdb->prepare( 'SELECT a.meta_id FROM %i a JOIN %i b ON a.post_id=b.post_id WHERE a.meta_key=%s AND b.meta_key=%s LIMIT 1', $wpdb->postmeta, $wpdb->postmeta, '_sf_publication', '_atshde_publication' ) ) ) throw new \RuntimeException( 'Conflicting publication data.' );
            $posts = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key=%s', $wpdb->postmeta, '_sf_publication' ) );
            self::query( $wpdb->prepare( 'UPDATE %i SET meta_key=%s WHERE meta_key=%s', $wpdb->postmeta, '_atshde_publication', '_sf_publication' ) );
            $drafts = $wpdb->prefix . 'atshde_snapshots';
            $posts = array_merge( $posts, $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', $drafts ) ) );
            self::query( $wpdb->prepare( 'UPDATE %i p JOIN %i s ON p.ID=s.post_id SET p.post_type=%s WHERE p.post_type=%s', $wpdb->posts, $drafts, 'atshde_article', 'sf_article' ) );
            foreach ( array( 'sf_reserved' => 'atshde_reserved', 'sf_reserve_hold' => 'atshde_reserve_hold' ) as $old => $new ) {
                self::query( $wpdb->prepare( 'UPDATE %i p JOIN %i m ON p.ID=m.post_id SET p.post_status=%s WHERE p.post_status=%s AND m.meta_key=%s', $wpdb->posts, $wpdb->postmeta, $new, $old, '_atshde_publication' ) );
            }
            self::query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $wpdb->options, 'complete', self::MARKER ) );
            self::query( 'COMMIT' ); $transaction = false;
            return true;
        } catch ( \Throwable $error ) {
            if ( $transaction ) $wpdb->query( 'ROLLBACK' );
            return false;
        } finally {
            // Invalidate only touched option/user/post caches; never flush unrelated site caches.
            foreach ( self::OPTIONS as $suffix ) { wp_cache_delete( 'sf_' . $suffix, 'options' ); wp_cache_delete( 'atshde_' . $suffix, 'options' ); }
            foreach ( array( 'alloptions', 'notoptions', self::MARKER, $wpdb->prefix . 'user_roles' ) as $key ) wp_cache_delete( $key, 'options' );
            foreach ( array_unique( $users ) as $id ) clean_user_cache( $id );
            foreach ( array_unique( $posts ) as $id ) clean_post_cache( $id );
            wp_roles()->for_site();
            if ( get_current_user_id() ) wp_get_current_user()->for_site();
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
}
