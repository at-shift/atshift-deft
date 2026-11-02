<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

function register_translations( $file ) {
    $load = static function() use ( $file ) {
        load_plugin_textdomain( 'atshift-deft', false, dirname( plugin_basename( $file ) ) . '/languages' );
    };
    // Guards also need translations, including activation after init has run.
    if ( did_action( 'init' ) ) $load();
    else add_action( 'init', $load, 0 );
}
