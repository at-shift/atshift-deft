<?php
// wp eval-file tests/wordpress-draft-collations.php --path=<disposable WordPress>
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_post_types_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable local database.' );

use Atshift\Deft\Publications;
global $wpdb;
$original = array();
foreach ( array( $wpdb->posts, Publications::table() ) as $table ) {
    $column = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i WHERE Field=%s', $table, 'post_type' ), ARRAY_A );
    if ( ! $column || 'varchar(20)' !== $column['Type'] || 'NO' !== $column['Null'] || 'post' !== $column['Default'] ||
        ! preg_match( '/^utf8mb4_[a-z0-9_]+$/D', $column['Collation'] ) ) throw new RuntimeException( 'Unexpected disposable post_type schema.' );
    $original[ $table ] = $column['Collation'];
}
$set_collation = static function( $table, $collation ) use ( $wpdb ) {
    $sql = $wpdb->prepare( 'ALTER TABLE %i MODIFY post_type varchar(20) CHARACTER SET utf8mb4 COLLATE %i NOT NULL DEFAULT %s', $table, $collation, 'post' );
    if ( false === $wpdb->query( $sql ) ) throw new RuntimeException( 'Could not set disposable collation: ' . $wpdb->last_error );
};
try {
    $set_collation( $wpdb->posts, 'utf8mb4_general_ci' );
    $set_collation( Publications::table(), 'utf8mb4_unicode_520_ci' );
    // Exercise new drafts, destination reservations, cancellation, publication and failure recovery.
    require __DIR__ . '/wordpress-reservations.php';
    echo wp_json_encode( array( 'mixed_collations' => true, 'passed' => true ) ) . "\n";
} finally {
    foreach ( $original as $table => $collation ) $set_collation( $table, $collation );
}
