<?php
namespace Atshift\Deft {
    function is_ssl() { return $GLOBALS['sf_transport_case']['ssl']; }
    function home_url() { return $GLOBALS['sf_transport_case']['home']; }
    function wp_get_environment_type() { return $GLOBALS['sf_transport_case']['env']; }
    function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
}
namespace {
define( 'ABSPATH', __DIR__ );
foreach ( array( 'access', 'connections', 'transfer' ) as $file ) require dirname( __DIR__ ) . '/wordpress/atshift-deft/includes/class-' . $file . '.php';
$cases = array(
    array( true, 'production', 'https://example.test', '192.0.2.1', true ),
    array( false, 'production', 'http://127.0.0.1', '127.0.0.1', false ),
    array( false, 'staging', 'http://127.0.0.1', '127.0.0.1', false ),
    array( false, 'development', 'http://127.0.0.1', '127.0.0.1', false ),
    array( false, 'local', 'http://127.0.0.1', '127.0.0.1', true ),
    array( false, 'local', 'http://localhost', '127.0.0.1', true ),
    array( false, 'local', 'http://[::1]', '::1', true ),
    array( false, 'local', 'http://127.0.0.1', '192.0.2.1', false ),
    array( false, 'local', 'http://example.test', '127.0.0.1', false ),
    array( false, 'local', 'ftp://127.0.0.1', '127.0.0.1', false ),
    array( false, 'local', 'http://127.0.0.1', '', false ),
);
$checks = $failures = 0;
foreach ( $cases as list( $ssl, $env, $home, $peer, $expected ) ) {
    $GLOBALS['sf_transport_case'] = compact( 'ssl', 'env', 'home' ); $_SERVER['REMOTE_ADDR'] = $peer;
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
    foreach ( array( 'Atshift\Deft\\Connections' => 'secure', 'Atshift\Deft\\Transfer' => 'secure_transport' ) as $class => $method ) {
        $checks++; $ok = $class::$method() === $expected; if ( ! $ok ) $failures++;
        echo ( $ok ? 'PASS ' : 'FAIL ' ) . "$checks: $class $env $home $peer\n";
    }
}
echo "Transport checks: $checks checks, $failures failures.\n";
exit( $failures ? 1 : 0 );
}
