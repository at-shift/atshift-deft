<?php
require_once dirname( __DIR__ ) . '/.local/wordpress/wp-includes/pomo/mo.php';
function __( $text, $domain = 'default' ) {
    global $locale;
    if ( 'atshift-deft' !== $domain || 'ja' !== $locale ) return $text;
    static $catalog;
    if ( ! $catalog ) {
        $catalog = new MO();
        if ( ! $catalog->import_from_file( dirname( __DIR__ ) . '/wordpress/atshift-deft/languages/atshift-deft-ja.mo' ) ) throw new RuntimeException( 'Japanese catalog unavailable.' );
    }
    return $catalog->translate( $text );
}
