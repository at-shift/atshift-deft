<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
set_current_screen( 'toplevel_page_structured-fields' );

$checks = 0;
function sfi_check( $condition, $label ) {
    global $checks;
    if ( ! $condition ) throw new RuntimeException( $label );
    echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL;
}
$root = dirname( __DIR__ ) . '/wordpress/atshift-deft';
$directory = get_temp_dir() . 'deft-i18n-' . wp_generate_uuid4();
mkdir( $directory, 0700 );
$locale = 'en_US';
$filter = static function() use ( &$locale ) { return $locale; };
add_filter( 'pre_determine_locale', $filter );
$baseline = StructuredFields\Snapshot::validate( file_get_contents( $root . '/sample.json' ) );
$behaviors = StructuredFields\Behaviors::registry();
try {
    $locale = 'ja'; unload_textdomain( 'atshift-deft', true );
    sfi_check( 'AI用手順書をダウンロード' === StructuredFields\Onboarding::copy()['download'], 'Bundled Japanese MO loads through WordPress gettext' );
    sfi_check( 'Request failed.' === __( 'Request failed.', 'atshift-deft' ), 'Missing Japanese entries fall back to English' );
    $locale = 'fr_FR'; unload_textdomain( 'atshift-deft', true );
    $mo = new MO(); $mo->set_header( 'Language', 'fr_FR' );
    $mo->set_header( 'Plural-Forms', 'nplurals=2; plural=(n > 1);' );
    foreach ( array( 'Components' => 'Composants <script>test</script>', 'AI connections' => 'Connexions IA',
        'Download AI guide' => 'Guide IA', 'This article is not available to this WordPress account.' => 'Acces refuse.',
        'Allow this access' => 'Autoriser', 'Close image' => 'Fermer' ) as $english => $translation ) {
        $mo->add_entry( new Translation_Entry( array( 'singular' => $english, 'translations' => array( $translation ) ) ) );
    }
    $mo->export_to_file( $directory . '/atshift-deft-fr_FR.mo' );
    sfi_check( load_textdomain( 'atshift-deft', $directory . '/atshift-deft-fr_FR.mo', $locale ), 'An additional locale MO can be loaded' );
    sfi_check( 'Guide IA' === StructuredFields\Onboarding::copy()['download'], 'New locale overrides onboarding without code changes' );
    sfi_check( 'Acces refuse.' === StructuredFields\Access::denied()->get_error_message(), 'Human-readable API errors use the same domain' );
    ob_start(); StructuredFields\catalog_page(); $html = ob_get_clean();
    sfi_check( str_contains( $html, 'Composants &lt;script&gt;test&lt;/script&gt;' ) && ! str_contains( $html, '<script>test</script>' ), 'Translated HTML labels remain escaped' );
    ob_start(); StructuredFields\connections_page(); $html = ob_get_clean();
    sfi_check( str_contains( $html, 'Connexions IA' ) && str_contains( $html, 'Guide IA' ), 'Connection page labels use the added locale' );
    sfi_check( 'Fermer' === __( 'Close image', 'atshift-deft' ), 'Public behavior labels share the translation domain' );
    sfi_check( StructuredFields\Snapshot::json( $baseline ) === StructuredFields\Snapshot::json( StructuredFields\Snapshot::validate( file_get_contents( $root . '/sample.json' ) ) ), 'Locale changes do not change article payload, rendering or hashes' );
    sfi_check( $behaviors === StructuredFields\Behaviors::registry(), 'Behavior contracts and revision hashes are language-independent' );
    foreach ( array( 'admin.js' => 'toplevel_page_structured-fields',
        'catalog.js' => get_plugin_page_hookname( 'structured-fields-catalog', 'structured-fields' ),
        'connections.js' => get_plugin_page_hookname( 'structured-fields-connections', 'structured-fields' ) ) as $file => $hook ) {
        wp_deregister_script( 'sf-admin' ); do_action( 'admin_enqueue_scripts', $hook );
        $script = wp_scripts()->registered['sf-admin'];
        sfi_check( str_contains( $script->src, '/' . $file ) && in_array( 'wp-i18n', $script->deps, true ) && 'atshift-deft' === $script->textdomain, $file . ' enqueues WordPress i18n and its domain' );
        $json = wp_json_encode( array( 'domain' => 'messages', 'locale_data' => array( 'messages' => array(
            '' => array( 'domain' => 'messages', 'lang' => 'fr_FR', 'plural-forms' => 'nplurals=2; plural=(n > 1);' ),
            'Request failed.' => array( 'Echec.' ) ) ) ) );
        $filename = $directory . '/atshift-deft-fr_FR-' . md5( $file ) . '.json';
        file_put_contents( $filename, $json );
        sfi_check( $json === load_script_textdomain( 'sf-admin', 'atshift-deft', $directory ), $file . ' resolves the standard path-hashed JSON catalog' );
    }
    $profile = wp_scripts()->registered['sf-connection-profiles'];
    sfi_check( in_array( 'wp-i18n', $profile->deps, true ) && 'atshift-deft' === $profile->textdomain, 'Connection profile script has its own catalog' );
    $locale = 'en_US'; unload_textdomain( 'atshift-deft', true );
    sfi_check( 'Download AI guide' === StructuredFields\Onboarding::copy()['download'], 'Switching back restores the English fallback' );
    echo 'WordPress i18n: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    remove_filter( 'pre_determine_locale', $filter ); unload_textdomain( 'atshift-deft', true );
    foreach ( glob( $directory . '/*' ) as $file ) unlink( $file );
    rmdir( $directory );
}
