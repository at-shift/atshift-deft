<?php
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use Atshift\Deft\PrivateBlobs;
use Atshift\Deft\Assets;

if ( ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) || home_url() !== 'http://127.0.0.1:8128' || wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Disposable prefix test database required.' );
global $wpdb;
$checks = 0; $ids = array();
function blob_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
function blob_reject( $fn, $label ) { try { $fn(); } catch ( Throwable $e ) { blob_check( true, $label ); return; } blob_check( false, $label ); }
try {
    blob_check( PrivateBlobs::available(), 'Private storage is an InnoDB table, not a web file' );
    foreach ( array( 1, 65535, 65536, 65537, Assets::MAX_BYTES ) as $size ) {
        $id = wp_generate_uuid4(); $ids[] = $id; $bytes = random_bytes( $size );
        $meta = array( 'bytes' => $size, 'sha256' => hash( 'sha256', $bytes ) );
        blob_check( null === PrivateBlobs::get( 'asset', $id, $meta ), 'Unknown identity returns no bytes: ' . $size );
        PrivateBlobs::put( 'asset', $id, $bytes, $meta );
        blob_check( $bytes === PrivateBlobs::get( 'asset', $id, $meta ), 'Exact arbitrary binary chunk roundtrip: ' . $size );
        PrivateBlobs::put( 'asset', $id, $bytes, $meta );
        blob_check( (int) ceil( $size / PrivateBlobs::CHUNK_BYTES ) === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE kind=%s AND identity=%s', PrivateBlobs::table(), 'asset', hash( 'sha256', $id ) ) ), 'Retry does not duplicate chunks: ' . $size );
    }
    blob_check( null === PrivateBlobs::get( 'publication', $id, $meta ), 'Asset and publication identities are isolated' );
    $bad = $meta; $bad['sha256'] = str_repeat( '0', 64 );
    blob_reject( static fn() => PrivateBlobs::get( 'asset', $id, $bad ), 'Digest mismatch never exposes bytes' );
    blob_reject( static fn() => PrivateBlobs::put( 'asset', $id, 'changed', $meta ), 'Existing frozen bytes cannot be replaced' );
    blob_reject( static fn() => PrivateBlobs::put( 'asset', wp_generate_uuid4(), str_repeat( 'x', Assets::MAX_BYTES + 1 ), array() ), 'Oversize writes fail before storage' );
    $wpdb->delete( PrivateBlobs::table(), array( 'kind' => 'asset', 'identity' => hash( 'sha256', $id ), 'piece' => 1 ) );
    blob_reject( static fn() => PrivateBlobs::get( 'asset', $id, $meta ), 'Missing chunk fails closed' );
    $meta['bytes'] = 1;
    blob_reject( static fn() => PrivateBlobs::get( 'asset', $id, $meta ), 'Extra chunks fail closed even with forged short metadata' );
    $row = array( 'asset_id' => $id, 'bytes' => Assets::MAX_BYTES, 'sha256' => hash( 'sha256', $bytes ) );
    blob_reject( static fn() => Assets::verified_bytes( $row ), 'Corrupt database record cannot fall back to a legacy file' );
    echo 'Private blob checks: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    foreach ( $ids as $id ) PrivateBlobs::delete( 'asset', $id );
}
