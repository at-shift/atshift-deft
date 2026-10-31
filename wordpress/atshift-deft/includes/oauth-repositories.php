<?php
declare(strict_types=1);

namespace StructuredFields\OAuth;

use StructuredFields\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\ScopeEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use StructuredFields\Vendor\League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use StructuredFields\Vendor\League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

defined( 'ABSPATH' ) || exit;

final class Clients implements ClientRepositoryInterface {
    public function __construct( private readonly ?Context $context = null ) {}

    public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
        if ( null !== $this->context && $clientIdentifier !== $this->context->grant['oauth_client'] ) return null;
        $row = \StructuredFields\OAuthStore::client( $clientIdentifier );
        if ( ! is_array( $row ) || empty( $row['active'] ) || ( $row['id'] ?? null ) !== $clientIdentifier ||
            ! is_string( $row['name'] ?? null ) || ! is_string( $row['redirect_uri'] ?? null ) || '' === $row['redirect_uri'] ) return null;
        return new Client( $row );
    }

    public function validateClient( string $clientIdentifier, ?string $clientSecret, ?string $grantType ): bool {
        $client = $this->getClientEntity( $clientIdentifier );
        return null !== $client && ( null === $clientSecret || '' === $clientSecret ) &&
            null !== $grantType && $client->supportsGrantType( $grantType );
    }
}

/** Shared grant checks; locking and transactions belong to the caller. */
abstract class Repository {
    public function __construct( protected readonly Context $context ) {}

    protected function activeGrant(): array {
        $grant = \StructuredFields\OAuthStore::active_grant( $this->context->grant['id'] );
        if ( ! is_array( $grant ) || ( $grant['id'] ?? null ) !== $this->context->grant['id'] ||
            ( $grant['oauth_client'] ?? null ) !== $this->context->grant['oauth_client'] ||
            (string) ( $grant['owner_id'] ?? '' ) !== $this->context->owner() ||
            ! empty( $grant['publishing'] ) !== ! empty( $this->context->grant['publishing'] ) ||
            min( (int) ( $grant['expires'] ?? 0 ), (int) $this->context->grant['expires'] ) <= time() ) {
            throw OAuthServerException::invalidGrant( 'Authorization is no longer active.' );
        }
        return $grant;
    }

    /** @return list<string> */
    protected function validate( ClientEntityInterface $client, array $scopes, ?string $owner, bool $requireOwner = true ): array {
        $this->context->assertIdentity( $client, $owner, $requireOwner );
        $grant = $this->activeGrant();
        $registered = ( new Clients( $this->context ) )->getClientEntity( $client->getIdentifier() );
        if ( null === $registered || $registered->getRedirectUri() !== $client->getRedirectUri() ) {
            throw OAuthServerException::invalidGrant( 'Client is no longer authorized.' );
        }
        $row = \StructuredFields\OAuthStore::client( $client->getIdentifier() );
        $ids = $this->context->scopeIds( $scopes );
        foreach ( array( 'sf:authoring' => 'authoring', 'sf:publish' => 'publishing' ) as $scope => $flag ) {
            if ( in_array( $scope, $ids, true ) && ( empty( $grant[ $flag ] ) || empty( $row[ $flag ] ) ) ) {
                throw OAuthServerException::invalidScope( 'requested_scope' );
            }
        }
        return $ids;
    }

    protected function expiry( \DateTimeImmutable $expiry ): \DateTimeImmutable {
        $grant = $this->activeGrant();
        $limit = new \DateTimeImmutable( '@' . (int) $grant['expires'] );
        $expiry = $this->context->clampExpiry( $expiry > $limit ? $limit : $expiry );
        if ( $expiry->getTimestamp() <= time() ) throw OAuthServerException::invalidGrant( 'Token has expired.' );
        return $expiry;
    }

    protected function save( string $id, string $kind, array $scopes, \DateTimeImmutable $expiry, string $accessId = '' ): void {
        if ( ! preg_match( '/^[a-f0-9]{80}$/D', $id ) ) throw OAuthServerException::invalidGrant( 'Invalid token identifier.' );
        if ( \StructuredFields\OAuthStore::token( $id, $kind ) ) throw UniqueTokenIdentifierConstraintViolationException::create();
        // Persist identifiers and authorization metadata only, never serialized credentials.
        $result = \StructuredFields\OAuthStore::save_token( $id, $kind, $this->context->grant,
            $this->context->resource, $scopes, $expiry, $accessId );
        if ( false === $result ) throw OAuthServerException::serverError( 'Token could not be stored.' );
    }

    protected function sourceMatches( string $id, string $kind ): bool {
        $source = $this->context->source;
        return null === $source || $source['kind'] !== $kind || $source['id'] === $id;
    }

    protected function revoked( string $id, string $kind ): bool {
        $row = \StructuredFields\OAuthStore::token( $id, $kind );
        if ( ! is_array( $row ) || ( $row['id'] ?? null ) !== $id || ! $this->context->matchesRecord( $row, $kind ) ||
            ! $this->sourceMatches( $id, $kind ) || ! empty( $row['revoked'] ) || (int) ( $row['expires'] ?? 0 ) <= time() ) return true;
        try {
            $client = ( new Clients( $this->context ) )->getClientEntity( $row['client_id'] );
            if ( null === $client ) return true;
            $scopes = array_map( static fn ( string $scope ): Scope => new Scope( $scope ), Context::storedScopes( $row ) );
            $this->validate( $client, $scopes, (string) $row['owner_id'] );
        } catch ( OAuthServerException $e ) {
            return true;
        }
        return false;
    }

    protected function revoke( string $id, string $kind ): void {
        $row = \StructuredFields\OAuthStore::token( $id, $kind );
        if ( ! $row ) return;
        if ( ! is_array( $row ) || ( $row['id'] ?? null ) !== $id || ! $this->context->matchesRecord( $row, $kind ) ||
            ! $this->sourceMatches( $id, $kind ) ||
            ( 'access' === $kind && 'refresh' === ( $this->context->source['kind'] ?? null ) &&
                ( $this->context->source['access_id'] ?? null ) !== $id ) ) {
            throw OAuthServerException::invalidGrant( 'Token does not match the authorization.' );
        }
        if ( false === \StructuredFields\OAuthStore::revoke_token( $id, $kind ) ) {
            throw OAuthServerException::serverError( 'Token could not be revoked.' );
        }
    }
}

final class Scopes extends Repository implements ScopeRepositoryInterface {
    public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
        return $this->context->allows( $identifier ) ? new Scope( $identifier ) : null;
    }

    public function finalizeScopes( array $scopes, string $grantType, ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null, ?string $authCodeId = null ): array {
        if ( ! in_array( $grantType, array( 'authorization_code', 'refresh_token' ), true ) ) {
            throw OAuthServerException::unsupportedGrantType();
        }
        $source = $this->context->source;
        if ( 'refresh_token' === $grantType && ( null === $source || 'refresh' !== $source['kind'] ) ) {
            throw OAuthServerException::invalidGrant( 'Refresh source is required.' );
        }
        if ( 'authorization_code' === $grantType && ( null !== $authCodeId || null !== $source ) &&
            ( null === $source || 'code' !== $source['kind'] || $authCodeId !== $source['id'] ) ) {
            throw OAuthServerException::invalidGrant( 'Authorization code does not match its source.' );
        }
        $ids = $this->validate( $clientEntity, $scopes, $userIdentifier, null !== $source );
        return array_map( static fn ( string $id ): Scope => new Scope( $id ), $ids );
    }
}

final class Codes extends Repository implements AuthCodeRepositoryInterface {
    public function getNewAuthCode(): AuthCodeEntityInterface {
        $this->activeGrant();
        return new AuthCode( $this->context );
    }

    public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
        if ( ! $authCodeEntity instanceof AuthCode || $authCodeEntity->context !== $this->context ) {
            throw OAuthServerException::invalidGrant( 'Authorization code context mismatch.' );
        }
        $ids = $this->validate( $authCodeEntity->getClient(), $authCodeEntity->getScopes(), $authCodeEntity->getUserIdentifier() );
        if ( null !== $authCodeEntity->getRedirectUri() && $authCodeEntity->getRedirectUri() !== $authCodeEntity->getClient()->getRedirectUri() ) {
            throw OAuthServerException::invalidGrant( 'Authorization code redirect mismatch.' );
        }
        $authCodeEntity->setExpiryDateTime( $this->expiry( $authCodeEntity->getExpiryDateTime() ) );
        $this->save( $authCodeEntity->getIdentifier(), 'code', $ids, $authCodeEntity->getExpiryDateTime() );
        $this->context->issuedCodeId = $authCodeEntity->getIdentifier();
    }

    public function revokeAuthCode( string $codeId ): void { $this->revoke( $codeId, 'code' ); }
    public function isAuthCodeRevoked( string $codeId ): bool { return $this->revoked( $codeId, 'code' ); }
}

final class AccessTokens extends Repository implements AccessTokenRepositoryInterface {
    public function getNewToken( ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null ): AccessTokenEntityInterface {
        $ids = $this->validate( $clientEntity, $scopes, $userIdentifier );
        $token = new AccessToken( $this->context );
        $token->setClient( $clientEntity );
        $token->setUserIdentifier( $this->context->owner() );
        foreach ( $ids as $id ) $token->addScope( new Scope( $id ) );
        return $token;
    }

    public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
        if ( ! $accessTokenEntity instanceof AccessToken || $accessTokenEntity->context !== $this->context ) {
            throw OAuthServerException::invalidGrant( 'Access token context mismatch.' );
        }
        $ids = $this->validate( $accessTokenEntity->getClient(), $accessTokenEntity->getScopes(), $accessTokenEntity->getUserIdentifier() );
        $accessTokenEntity->setExpiryDateTime( $this->expiry( $accessTokenEntity->getExpiryDateTime() ) );
        $this->save( $accessTokenEntity->getIdentifier(), 'access', $ids, $accessTokenEntity->getExpiryDateTime() );
        $this->context->issuedAccessId = $accessTokenEntity->getIdentifier();
    }

    public function revokeAccessToken( string $tokenId ): void { $this->revoke( $tokenId, 'access' ); }
    public function isAccessTokenRevoked( string $tokenId ): bool { return $this->revoked( $tokenId, 'access' ); }
}

final class RefreshTokens extends Repository implements RefreshTokenRepositoryInterface {
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
        $this->activeGrant();
        return new RefreshToken( $this->context );
    }

    public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
        if ( ! $refreshTokenEntity instanceof RefreshToken || $refreshTokenEntity->context !== $this->context ) {
            throw OAuthServerException::invalidGrant( 'Refresh token context mismatch.' );
        }
        $access = $refreshTokenEntity->getAccessToken();
        if ( ! $access instanceof AccessToken || $access->context !== $this->context ) {
            throw OAuthServerException::invalidGrant( 'Refresh access token context mismatch.' );
        }
        $ids = $this->validate( $access->getClient(), $access->getScopes(), $access->getUserIdentifier() );
        $row = \StructuredFields\OAuthStore::token( $access->getIdentifier(), 'access' );
        if ( $this->context->issuedAccessId !== $access->getIdentifier() || ! is_array( $row ) ||
            ! $this->context->matchesRecord( $row, 'access' ) || ! empty( $row['revoked'] ) ||
            (int) ( $row['expires'] ?? 0 ) !== $access->getExpiryDateTime()->getTimestamp() ||
            Context::storedScopes( $row ) !== $ids ) {
            throw OAuthServerException::invalidGrant( 'Refresh token requires its persisted access token.' );
        }
        $refreshTokenEntity->setExpiryDateTime( $this->expiry( $refreshTokenEntity->getExpiryDateTime() ) );
        $this->save( $refreshTokenEntity->getIdentifier(), 'refresh', $ids, $refreshTokenEntity->getExpiryDateTime(), $access->getIdentifier() );
        $this->context->issuedRefreshId = $refreshTokenEntity->getIdentifier();
    }

    public function revokeRefreshToken( string $tokenId ): void { $this->revoke( $tokenId, 'refresh' ); }
    public function isRefreshTokenRevoked( string $tokenId ): bool { return $this->revoked( $tokenId, 'refresh' ); }
}
