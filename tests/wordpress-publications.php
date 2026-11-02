<?php
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\Publications;
use Atshift\Deft\PublicationMedia;
use Atshift\Deft\Connections;
use Atshift\Deft\Mcp;

$count = 0;
function sfp_check( $ok, $message ) { global $count; if ( ! $ok ) throw new RuntimeException( $message ); echo 'PASS ' . ++$count . ': ' . $message . PHP_EOL; }
foreach ( array( 'get-publication', 'set-publication', 'cancel-publication' ) as $key ) {
    sfp_check( is_wp_error( Mcp::execute( $key, array() ) ), 'No direct publication ability bypass: ' . $key );
    $ability = wp_get_ability( 'structured-fields/' . $key );
    sfp_check( ! $ability->get_meta()['show_in_rest'] && ! $ability->get_meta()['mcp']['public'], 'Publication ability hidden from global API: ' . $key );
}
sfp_check( is_wp_error( Publications::permission( new WP_REST_Request( 'POST' ) ) ), 'Current WP user alone cannot authorize publication' );
$future = new DateTimeImmutable( '+2 days', new DateTimeZone( 'Asia/Tokyo' ) );
$body = (object) array( 'timing' => 'scheduled', 'publishAt' => $future->format( 'Y-m-d\TH:i:sP' ), 'timeZone' => 'Asia/Tokyo' );
sfp_check( Publications::timestamp( $body ) === $future->getTimestamp(), 'IANA time zone matches exact UTC instant' );
$bad = array(
    array( 'now', '', 'Asia/Tokyo' ),
    array( 'scheduled', $future->format( 'Y-m-d\TH:i:sP' ), 'UTC' ),
    array( 'scheduled', '2027-02-30T12:00:00+09:00', 'Asia/Tokyo' ),
    array( 'scheduled', '2027-03-14T02:30:00-05:00', 'America/New_York' ),
    array( 'scheduled', gmdate( 'Y-m-d\TH:i:s+00:00', time() - 60 ), 'UTC' ),
    array( 'scheduled', gmdate( 'Y-m-d\TH:i:s+00:00', time() + 2*YEAR_IN_SECONDS ), 'UTC' ),
    array( 'scheduled', $future->format( 'Y-m-d H:i' ), 'Asia/Tokyo' ),
    array( 'scheduled', $future->format( 'Y-m-d\TH:i:sP' ), 'unknown' ),
);
foreach ( $bad as $case ) {
    $rejected = false;
    try { Publications::timestamp( (object) array_combine( array( 'timing', 'publishAt', 'timeZone' ), $case ) ); } catch ( Throwable $e ) { $rejected = true; }
    sfp_check( $rejected, 'Invalid, ambiguous or out-of-range timing rejected: ' . implode( ' ', $case ) );
}
$row = array( 'rendered' => '<div class="sf-article"><!-- wp:test/dynamic /--><p>[sf_test_execute]</p></div>' );
$html = PublicationMedia::html( array(), $row, array() );
sfp_check( ! str_contains( $html, '<!--' ) && str_contains( $html, '&#91;sf_test_execute]' ), 'Public HTML cannot contain executable WP blocks or shortcodes' );
sfp_check( ! Connections::can_publish(), 'No implicit publisher connection' );
echo "Publication unit checks: $count passed.\n";
