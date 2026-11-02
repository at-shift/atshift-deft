<?php
require __DIR__ . '/wordpress-media-fixture.php';
use Atshift\Deft\{Store, Snapshot, Publications, Reservations};
$checks = 0; $post = 0; $id = 'media-test-' . wp_generate_uuid4();
function title_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
try {
    $post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Ordinary post', 'post_status' => 'draft' ), true );
    if ( is_wp_error( $post ) ) throw new RuntimeException( 'Fixture failed.' );
    foreach ( array( 'Ordinary & title', 'Already &amp; encoded', '<em>Theme title markup</em>' ) as $input ) {
        title_check( Store::title( $input, $post ) === $input, 'An unrelated title remains unchanged: ' . $input );
        title_check( Store::title( $input, 0 ) === $input, 'A filter call without an object is not globally rewritten' );
    }
    $fixture = sf_media_test_create( $id ); $snapshot = $fixture['snapshot'];
    foreach ( array( '<img src=x onerror=alert(1)>', '<script>alert(1)</script>', 'Literal &lt;tag&gt; & "quotes"' ) as $input ) {
        $row = Store::read( $id ); $snapshot->article->revision++; $snapshot->article->title = $input;
        $saved = Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), (int) $row['version'] );
        if ( is_wp_error( $saved ) ) throw new RuntimeException( $saved->get_error_message() );
        $title = get_the_title( $saved['postId'] );
        title_check( $title === Snapshot::post_title( $input ), 'Owned title is escaped exactly once from canonical plain text' );
        title_check( ! str_contains( $title, '<' ) && html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $input, 'Rendered title is inert and preserves literal entities' );
    }
    echo 'Title boundary checks: ' . $checks . ' passed.' . PHP_EOL;
} finally { if ( $post && ! is_wp_error( $post ) ) wp_delete_post( $post, true ); sf_media_test_cleanup( $id ); }
