<?php
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\Catalog;
use Atshift\Deft\CatalogContext;
use Atshift\Deft\ComponentBuilder;
use Atshift\Deft\Snapshot;
$uid = (int) ( $argv[1] ?? 0 ); $run = $argv[2] ?? ''; $side = $argv[3] ?? ''; $start = (float) ( $argv[4] ?? 0 );
if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) || get_user_meta( $uid, '_sf_structure_run', true ) !== $run || ! in_array( $side, array( 'a', 'b' ), true ) || $start > microtime( true ) + 10 ) throw new RuntimeException( 'Disposable race only.' );
wp_set_current_user( $uid ); $context = CatalogContext::get( new WP_REST_Request( 'GET' ) );
$recipe = ComponentBuilder::structure_example(); $recipe->id = 'structure-test-' . $run . '-race-' . $side; $recipe->key = 'race_' . $side;
$recipe->layout = 'stack'; $recipe->fields[0]->key = 'race_question';
$body = array( 'recipe' => $recipe, 'expectedVersion' => 0, 'reuse' => array( 'catalogToken' => $context['catalogToken'],
    'considered' => array( array( 'id' => $context['components'][0]['id'], 'revision' => $context['components'][0]['revision'] ) ), 'reason' => 'A new typed race fixture is needed.' ) );
$request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' ); $request->set_body( Snapshot::json( $body ) );
while ( microtime( true ) < $start ) usleep( 1000 );
$result = Catalog::structure( $request ); echo Snapshot::json( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'id' => $result['id'], 'reused' => $result['reused'] ?? false ) );
