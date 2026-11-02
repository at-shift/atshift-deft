<?php
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\{Catalog, ComponentUsage, Snapshot};

$original_prefix = $wpdb->prefix;
$prefix = 'sf_upgrade_' . bin2hex( random_bytes( 8 ) ) . '_';
$sources = array( Catalog::table(), Catalog::versions(), ComponentUsage::table() );
$tables = array( $prefix . 'atshde_catalog', $prefix . 'atshde_components', $prefix . 'atshde_component_usage' );
$schema = 3; $checks = 0;
function sfu_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
$read_schema = static function() use ( &$schema ) { return $schema; };
$write_schema = static function( $new, $old ) use ( &$schema ) { $schema = $new; return $old; };
$deny_alter = static fn( $sql ) => str_starts_with( $sql, 'ALTER TABLE ' . $tables[0] . ' ' ) ? 'DO 0' : $sql;
try {
    foreach ( $tables as $index => $table ) {
        if ( false === $wpdb->query( "CREATE TABLE $table LIKE {$sources[$index]}" ) ) throw new RuntimeException( 'Could not create isolated migration table.' );
    }
    $table = $tables[0]; $versions = $tables[1];
    if ( false === $wpdb->query( "ALTER TABLE $table DROP INDEX creator_owner, DROP COLUMN creator_id" ) ) throw new RuntimeException( 'Could not prepare alpha.11 schema.' );
    $wpdb->prefix = $prefix;
    add_filter( 'pre_option_atshde_catalog_schema_version', $read_schema );
    add_filter( 'pre_update_option_atshde_catalog_schema_version', $write_schema, 10, 2 );
    $c = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/sample.json' ) )->components[0];
    $c->id = 'legacy-migration'; $c->key = 'legacy_migration'; unset( $c->revision ); $c = Snapshot::component( $c ); $old_revision = $c->revision;
    $old_payload = Snapshot::json( $c );
    if ( false === $wpdb->insert( $versions, array( 'component_id' => $c->id, 'revision' => $c->revision, 'payload' => $old_payload, 'created_by' => 791001, 'created_at' => '2026-01-01 00:00:00' ) ) ) throw new RuntimeException( 'Legacy revision insert failed.' );
    $c->name = 'Later manager revision'; unset( $c->revision ); $c = Snapshot::component( $c );
    if ( false === $wpdb->insert( $versions, array( 'component_id' => $c->id, 'revision' => $c->revision, 'payload' => Snapshot::json( $c ), 'created_by' => 791002, 'created_at' => '2026-01-02 00:00:00' ) ) ) throw new RuntimeException( 'Manager revision insert failed.' );
    $record = array( 'component_id' => $c->id, 'owner_id' => 0, 'definition_kind' => 'design', 'machine_key' => $c->key, 'guidance' => '', 'recipe' => '', 'reuse_note' => '',
        'revision' => $c->revision, 'version' => 2, 'enabled' => 0, 'updated_by' => 791002, 'updated_at' => '2026-01-02 00:00:00' );
    if ( false === $wpdb->insert( $table, $record ) ) throw new RuntimeException( 'Legacy catalog insert failed.' );
    $before = Catalog::read( $c->id );
    sfu_check( null === $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'creator_id'" ) && 3 === $schema, 'Migration starts with the alpha.11 catalog shape and version' );
    add_filter( 'query', $deny_alter );
    try { Catalog::install(); } finally { remove_filter( 'query', $deny_alter ); }
    sfu_check( 3 === $schema && null === $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'creator_id'" ), 'Unavailable ALTER does not mark the schema upgraded' );
    sfu_check( $before === Catalog::read( $c->id ) && $old_payload === Catalog::read( $c->id, $old_revision )['payload'], 'An incomplete upgrade leaves legacy records unchanged' );
    Catalog::install();
    sfu_check( 4 === $schema && 'creator_id' === $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'creator_id'" ), 'Retry adds the creator column and advances the schema gate' );
    $after = Catalog::read( $c->id ); $creator = $after['creator_id']; unset( $after['creator_id'] );
    sfu_check( null === $creator && $before === $after && $old_payload === Catalog::read( $c->id, $old_revision )['payload'], 'Upgrade preserves all old metadata, current payload and historical payload' );
    sfu_check( 1 === Catalog::shared_usage( 791001 ) && 0 === Catalog::shared_usage( 791002 ), 'Migrated records count the original creator rather than the later manager' );
    Catalog::install(); $again = Catalog::read( $c->id ); unset( $again['creator_id'] );
    sfu_check( $before === $again && 4 === $schema, 'Repeated installation remains idempotent' );
} finally {
    remove_filter( 'query', $deny_alter ); remove_filter( 'pre_option_atshde_catalog_schema_version', $read_schema ); remove_filter( 'pre_update_option_atshde_catalog_schema_version', $write_schema );
    $wpdb->prefix = $original_prefix;
    foreach ( array_reverse( $tables ) as $table ) {
        if ( ! str_starts_with( $table, $prefix ) ) throw new RuntimeException( 'Unexpected migration table.' );
        $wpdb->query( "DROP TABLE IF EXISTS $table" );
    }
}
sfu_check( 4 === (int) get_option( 'atshde_catalog_schema_version' ) && $original_prefix === $wpdb->prefix && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'Disposable tables are removed without changing site schema state' );
echo "Catalog upgrade checks: $checks passed.\n";
