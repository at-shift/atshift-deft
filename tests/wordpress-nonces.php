<?php
namespace Atshift\Deft {
    // Observe the pluggable verifier while retaining WordPress's real verification.
    function wp_verify_nonce( $nonce, $action = -1 ) {
        if ( ! is_string( $nonce ) || sanitize_text_field( $nonce ) !== $nonce ) throw new \RuntimeException( 'Unsanitized nonce reached the verifier.' );
        $GLOBALS['sf_nonce_verifications'][] = array( $nonce, $action );
        return \wp_verify_nonce( $nonce, $action );
    }
}
namespace {
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\Access;
$GLOBALS['sf_nonce_verifications'] = array();
$checks = 0;
function sfn_check( $ok, $message ) {
    global $checks;
    if ( ! $ok ) throw new RuntimeException( $message );
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}
sfn_check( method_exists( Access::class, 'verify_nonce' ), 'Programmatic callers retain the canonical nonce verifier' );
foreach ( array( 'wp_rest', 'sf-review-fixture-digest', 'sf-oauth-consent-fixture', 'sf-media-fixture', 'sf-asset-fixture', 'sf-preview-fixture' ) as $action ) {
    $nonce = wp_create_nonce( $action );
    sfn_check( 1 === Access::verify_nonce( $nonce, $action ), 'Valid nonce accepted for ' . $action );
    sfn_check( end( $GLOBALS['sf_nonce_verifications'] ) === array( $nonce, $action ), 'Exact nonce and action reach the pluggable verifier' );
    sfn_check( false === Access::verify_nonce( $nonce, $action . '-different' ), 'Wrong action rejected' );
    sfn_check( false === Access::verify_nonce( 'wrong', $action ), 'Wrong nonce rejected' );
    foreach ( array( null, array( $nonce ), (object) array( 'nonce' => $nonce ), true, 1, 1.0, '', '<b>' . $nonce . '</b>', ' ' . $nonce, $nonce . "\n", $nonce . '%00', $nonce . "\xff" ) as $invalid ) {
        $before = count( $GLOBALS['sf_nonce_verifications'] );
        sfn_check( false === Access::verify_nonce( $invalid, $action ), 'Malformed nonce rejected without normalization' );
        sfn_check( $before === count( $GLOBALS['sf_nonce_verifications'] ), 'Malformed nonce never reaches the pluggable verifier' );
    }
}
$owner = get_current_user_id();
$nonce = wp_create_nonce( 'sf-owner-test' );
try {
    wp_set_current_user( 0 );
    sfn_check( false === Access::verify_nonce( $nonce, 'sf-owner-test' ), 'Nonce cannot cross authenticated owners' );
} finally { wp_set_current_user( $owner ); }
$nonce = wp_create_nonce( 'sf-old-tick' );
$short_lifetime = static fn() => 1;
add_filter( 'nonce_life', $short_lifetime );
try { sfn_check( false === Access::verify_nonce( $nonce, 'sf-old-tick' ), 'Expired nonce rejected' ); }
finally { remove_filter( 'nonce_life', $short_lifetime ); }
$article_id = 'media-test-' . wp_generate_uuid4();
$snapshot = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/sample.json' ) );
$snapshot->article->id = $article_id;
$saved = \Atshift\Deft\Store::save( \Atshift\Deft\Snapshot::validate( \Atshift\Deft\Snapshot::json( $snapshot ) ), 0 );
if ( is_wp_error( $saved ) ) throw new RuntimeException( 'Cannot create the disposable preview fixture.' );
$row = \Atshift\Deft\Store::read( $article_id );
$original_get = $_GET; $original_server = $_SERVER;
$valid = array( 'p' => (string) $row['post_id'], 'preview' => 'true', 'sf_version' => (string) $row['version'],
    '_sf_nonce' => wp_create_nonce( 'sf-preview-' . $row['post_id'] . '-' . $row['digest'] ) );
try {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = wp_slash( $valid );
    sfn_check( array( 'read' ) === Access::preview_caps( array(), 'read_post', $owner, array( $row['post_id'] ) ), 'Canonical preview values retain read access' );
    foreach ( $valid as $key => $value ) {
        foreach ( array( null, array( $value ), '', '<b>' . $value . '</b>', ' ' . $value, $value . "\n", $value . '%00', $value . "\0", $value . "\xff" ) as $invalid ) {
            $_GET = wp_slash( array_replace( $valid, array( $key => $invalid ) ) );
            $before = count( $GLOBALS['sf_nonce_verifications'] );
            sfn_check( array( 'do_not_allow' ) === Access::preview_caps( array(), 'read_post', $owner, array( $row['post_id'] ) ), 'Preview rejects altered ' . $key . ' without repairing it' );
            sfn_check( $before === count( $GLOBALS['sf_nonce_verifications'] ), 'Rejected preview input never reaches the pluggable verifier' );
        }
    }
    $_GET = wp_slash( $valid ); $_SERVER['REQUEST_METHOD'] = 'HEAD';
    sfn_check( array( 'read' ) === Access::preview_caps( array(), 'edit_post', $owner, array( $row['post_id'] ) ), 'HEAD retains the read-only core preview capability' );
    sfn_check( array( 'do_not_allow' ) === Access::preview_caps( array(), 'delete_post', $owner, array( $row['post_id'] ) ), 'Valid preview never grants deletion' );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    sfn_check( array( 'do_not_allow' ) === Access::preview_caps( array(), 'read_post', $owner, array( $row['post_id'] ) ), 'Valid preview never grants a POST mutation' );
} finally {
    $_GET = $original_get; $_SERVER = $original_server;
    sf_media_test_cleanup( $article_id );
}
$calls = array();
foreach ( glob( dirname( __DIR__ ) . '/wordpress/atshift-deft/includes/*.php' ) as $path ) {
    $source = file_get_contents( $path );
    if ( preg_match( '/(?<![A-Za-z0-9_])wp_verify_nonce\s*\(/', $source ) ) $calls[] = basename( $path );
}
sfn_check( array( 'class-access.php', 'class-assets.php', 'class-media.php', 'plugin.php' ) === $calls, 'Only the shared verifier and canonical HTTP boundaries call the WordPress verifier' );
echo 'Nonce boundary checks: ' . $checks . ' passed.', PHP_EOL;
}
