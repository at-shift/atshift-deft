<?php
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use Atshift\Deft\PrefixMigration;
use Atshift\Deft\Store;
if ( ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Disposable test site required.' );
global $wpdb;
$checks = 0; $old = $wpdb->prefix . 'sf_snapshots'; $new = Store::table(); $created = false;
function recovery_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
function recovery_flush() { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); foreach ( array( 'sf_oauth_server_keys', 'atshde_oauth_server_keys', PrefixMigration::MARKER ) as $name ) wp_cache_delete( $name, 'options' ); }
try {
    recovery_check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ), 'No legacy table remains after successful migration' );
    $before = hash( 'sha256', serialize( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY article_id,version', $new ), ARRAY_A ) ) );
    recovery_check( PrefixMigration::run() && PrefixMigration::run(), 'Completed migration is repeatable' );
    $caps = array( 'sf_author' => true, 'sf_review_drafts' => false, 'unrelated' => false );
    recovery_check( array( 'unrelated' => false, 'atshde_author' => true, 'atshde_review_drafts' => false ) === PrefixMigration::rename_caps( $caps ), 'Capability migration preserves explicit denies and unrelated entries' );
    $rejected = false;
    try { PrefixMigration::rename_caps( array( 'sf_review_drafts' => false, 'atshde_review_drafts' => true ) ); } catch ( Throwable $e ) { $rejected = true; }
    recovery_check( $rejected, 'Conflicting old/new capabilities are never merged into a grant' );
    $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $old, $new ) ); $created = true;
    update_option( PrefixMigration::MARKER, 'pending', false );
    recovery_check( ! PrefixMigration::run(), 'Conflicting old/new tables fail closed' );
    recovery_check( 'pending' === get_option( PrefixMigration::MARKER ), 'Failed migration is not marked complete' );
    $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old ) ); $created = false;
    recovery_check( PrefixMigration::run(), 'Migration resumes after an operator resolves the table conflict' );
    $key = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value,autoload FROM %i WHERE option_name=%s', $wpdb->options, 'atshde_oauth_server_keys' ), ARRAY_A );
    recovery_check( is_array( $key ), 'Encrypted legacy OAuth signing material exists' );
    $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name=%s WHERE option_name=%s', $wpdb->options, 'sf_oauth_server_keys', 'atshde_oauth_server_keys' ) );
    update_option( PrefixMigration::MARKER, 'pending', false ); recovery_flush();
    recovery_check( PrefixMigration::run(), 'Interruption after table rename resumes the option migration' );
    recovery_check( $key === $wpdb->get_row( $wpdb->prepare( 'SELECT option_value,autoload FROM %i WHERE option_name=%s', $wpdb->options, 'atshde_oauth_server_keys' ), ARRAY_A ), 'Resumed migration preserves encrypted bytes and autoload exactly' );
    add_option( 'sf_oauth_server_keys', 'conflicting fixture', '', false ); update_option( PrefixMigration::MARKER, 'pending', false );
    recovery_check( ! PrefixMigration::run() && 'pending' === get_option( PrefixMigration::MARKER ), 'Conflicting signing keys roll back and remain blocked' );
    delete_option( 'sf_oauth_server_keys' ); recovery_check( PrefixMigration::run(), 'Retry after conflict resolution succeeds without regenerating keys' );
    recovery_check( $before === hash( 'sha256', serialize( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY article_id,version', $new ), ARRAY_A ) ) ), 'All interrupted and repeated attempts leave saved snapshots byte-identical' );
    echo 'Prefix recovery checks: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    if ( $created ) $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old ) );
    delete_option( 'sf_oauth_server_keys' );
    PrefixMigration::run();
}
