<?php
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\{Catalog, ComponentBuilder, Snapshot};
$uid = (int) ( $argv[1] ?? 0 ); $run = $argv[2] ?? ''; $side = $argv[3] ?? ''; $start = (float) ( $argv[4] ?? 0 );
if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) || get_user_meta( $uid, '_sf_quota_run', true ) !== $run || ! in_array( $side, array( 'a', 'b' ), true ) || $start > microtime( true ) + 10 ) throw new RuntimeException( 'Disposable race only.' );
wp_set_current_user( $uid ); add_filter( 'sf_shared_component_limit_per_user', static fn() => 2 );
$recipe = ComponentBuilder::structure_example(); $recipe->layout = 'stack'; $recipe->id = 'quota-test-' . $run . '-race-' . $side; $recipe->key = 'quota_race_' . $side; $recipe->fields[0]->key = 'quota_race_' . $side;
$component = ComponentBuilder::structure( $recipe );
$r = new WP_REST_Request( 'POST' ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( array( 'component' => $component, 'expectedVersion' => 0 ) ) );
while ( microtime( true ) < $start ) usleep( 1000 );
$result = Catalog::register( $r ); echo Snapshot::json( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'id' => $result['id'] ) );
