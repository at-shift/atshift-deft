<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class ComponentUsage {
    public static function table() { global $wpdb; return $wpdb->prefix . 'sf_component_usage'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            component_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision char(64) NOT NULL,
            used_at datetime NOT NULL,
            PRIMARY KEY  (user_id,component_id,revision)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
    }

    public static function references() {
        global $wpdb; $table = self::table(); $catalog = Catalog::table();
        return $wpdb->get_results( $wpdb->prepare( 'SELECT u.component_id AS id,u.revision FROM %i u INNER JOIN %i c ON u.component_id=c.component_id WHERE u.user_id=%d AND (c.owner_id=0 OR c.owner_id=%d) ORDER BY u.component_id,u.revision', $table, $catalog, get_current_user_id(), get_current_user_id() ), ARRAY_A );
    }

    // Called inside the article transaction: a rejected save must not change the usage list.
    public static function remember( $snapshot ) {
        global $wpdb; $table = self::table();
        foreach ( $snapshot->components as $component ) {
            $row = Catalog::read( $component->id, $component->revision );
            if ( ! $row || ( $row['owner_id'] && (int) $row['owner_id'] !== get_current_user_id() ) ) continue;
            if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,component_id,revision,used_at) VALUES (%d,%s,%s,%s) ON DUPLICATE KEY UPDATE used_at=VALUES(used_at)', $table,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
                get_current_user_id(), $component->id, $component->revision, current_time( 'mysql', true ) ) ) ) throw new \RuntimeException( __( 'Could not remember component use.', 'atshift-deft' ) );
        }
    }

    public static function delete_user( $user_id ) {
        global $wpdb; $wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ) );
    }
}
