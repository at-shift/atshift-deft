<?php
define( 'ABSPATH', __DIR__ . '/' );
$plugin = getenv( 'SF_OAUTH_PLUGIN_DIR' ) ?: dirname( __DIR__ ) . '/wordpress/atshift-deft';
require $plugin . '/vendor/autoload.php';
require $plugin . '/includes/class-oauth.php';

use Atshift\Deft\OAuth;

$checks = 0;
function sfoi_check( $ok, $message ) {
    global $checks;
    if ( ! $ok ) throw new RuntimeException( $message );
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}
function sfoi_rejected( $raw, $allowed ) {
    try { OAuth::decode_form( $raw, $allowed ); }
    catch ( InvalidArgumentException $e ) { return true; }
    return false;
}
$allowed = array( 'client_id', 'state', 'scope', 'redirect_uri', 'resource' );
foreach ( array( 'client.id', 'client+id', 'client%20id', '%20client_id', 'client_id%00junk', 'client_id[]', 'client_id[0]',
    'client_id[x', 'client_id%5B%5D', 'CLIENT_ID', 'unknown', '0', '' ) as $name ) {
    sfoi_check( sfoi_rejected( $name . '=discard&client_id=valid', $allowed ), 'Ambiguous/unknown name cannot be hidden by a later scalar: ' . $name );
    sfoi_check( sfoi_rejected( 'client_id=valid&' . $name . '=discard', $allowed ), 'Ambiguous/unknown name cannot override an earlier scalar: ' . $name );
    sfoi_check( sfoi_rejected( $name . '=valid', $allowed ), 'A lone alias is not normalized into a valid name: ' . $name );
}
foreach ( array( 'client_id', 'cl%69ent_id', 'client%5fid' ) as $name ) {
    sfoi_check( array( 'client_id' => 'exact' ) === OAuth::decode_form( $name . '=exact', $allowed ), 'Canonical encoded name is decoded once: ' . $name );
    sfoi_check( sfoi_rejected( 'client_id=a&' . $name . '=b', $allowed ), 'Decoded duplicate is rejected: ' . $name );
}
$values = array( 'literal + & / ? = % ; \\ " \' <tag>', '%2520', 'https://example.invalid/cb?x=a%2Bb&y=%2520',
    'https://example.invalid/?rest_route=/structured-fields-mcp/v1/mcp', ' leading and trailing ' );
foreach ( $values as $value ) {
    $encoded = http_build_query( array( 'state' => $value ), '', '&', PHP_QUERY_RFC1738 );
    sfoi_check( array( 'state' => $value ) === OAuth::decode_form( $encoded, $allowed ), 'Opaque form value remains byte-exact' );
}
sfoi_check( array( 'state' => 'a b+c%20;d=e' ) === OAuth::decode_form( 'state=a+b%2Bc%2520;d=e', $allowed ), 'Plus, escaped plus, percent, semicolon and equals retain form semantics' );
sfoi_check( array( 'scope' => '' ) === OAuth::decode_form( 'scope', $allowed ), 'Bare scope is empty, not an omitted/default scope' );
sfoi_check( array( 'scope' => '' ) === OAuth::decode_form( 'scope=', $allowed ), 'Explicit empty scope is preserved' );
sfoi_check( array() === OAuth::decode_form( '', $allowed ), 'Empty form remains empty for idempotent revocation handling' );
sfoi_check( array( 'state' => 'x' ) === OAuth::decode_form( '&state=x&&', $allowed ), 'Empty separator segments are harmless' );
foreach ( array( 'state=x%', 'state=x%0', 'state=x%QQ', "state=x\n", "state=x\0", str_repeat( '&', 32 ), 'state=' . str_repeat( 'x', 32763 ), null, array(), false ) as $raw ) {
    sfoi_check( sfoi_rejected( $raw, $allowed ), 'Malformed encoding, controls, oversized work or nonstring input is rejected' );
}
sfoi_check( 32762 === strlen( OAuth::decode_form( 'state=' . str_repeat( 'x', 32762 ), $allowed )['state'] ), 'The 32768-byte boundary is accepted' );
sfoi_check( array() === OAuth::decode_form( str_repeat( '&', 31 ), $allowed ), 'At most 32 pairs are admitted to the library parser' );
echo 'OAuth input checks: ' . $checks . ' passed.', PHP_EOL;
