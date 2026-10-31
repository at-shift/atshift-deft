<?php
declare(strict_types=1);

namespace StructuredFields\OAuth;

use StructuredFields\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\ScopeEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\UserEntityInterface;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\ClientTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\ScopeTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use StructuredFields\Vendor\League\OAuth2\Server\Exception\OAuthServerException;

defined( 'ABSPATH' ) || exit;

final class Context {
    public string $issuedCodeId = '';
    public string $issuedAccessId = '';
    public string $issuedRefreshId = '';

    public function __construct(
        public readonly array $grant,
        public readonly string $resource,
        public readonly string $issuer,
        public readonly ?array $source = null
    ) {
        if ( ! is_string( $grant['id'] ?? null ) || '' === $grant['id'] ||
            ! is_string( $grant['oauth_client'] ?? null ) || '' === $grant['oauth_client'] ||
            ! preg_match( '/^[1-9][0-9]*$/D', (string) ( $grant['owner_id'] ?? '' ) ) ||
            (int) ( $grant['expires'] ?? 0 ) <= time() || '' === $resource || '' === $issuer ) {
            throw OAuthServerException::invalidGrant( 'Invalid authorization context.' );
        }
        if ( null !== $source ) {
            if ( ! $this->matchesRecord( $source, (string) ( $source['kind'] ?? '' ) ) ||
                ! empty( $source['revoked'] ) || (int) ( $source['expires'] ?? 0 ) <= time() ) {
                throw OAuthServerException::invalidGrant( 'Invalid source authorization.' );
            }
            self::storedScopes( $source );
        }
    }

    public function owner(): string { return (string) $this->grant['owner_id']; }

    public function assertIdentity( ClientEntityInterface $client, ?string $owner, bool $requireOwner = true ): void {
        if ( $client->getIdentifier() !== $this->grant['oauth_client'] || $client->isConfidential() ||
            ( $requireOwner && null === $owner ) || ( null !== $owner && $owner !== $this->owner() ) ) {
            throw OAuthServerException::invalidGrant( 'Client or owner does not match the authorization.' );
        }
    }

    public function allows( string $scope ): bool {
        $allowed = 'sf:drafts' === $scope ||
            ( 'sf:authoring' === $scope && ! empty( $this->grant['authoring'] ) ) ||
            ( 'sf:publish' === $scope && ! empty( $this->grant['publishing'] ) );
        return $allowed && ( null === $this->source || in_array( $scope, self::storedScopes( $this->source ), true ) );
    }

    /** @param ScopeEntityInterface[] $scopes @return list<string> */
    public function scopeIds( array $scopes ): array {
        $ids = array();
        foreach ( $scopes as $scope ) {
            if ( ! $scope instanceof ScopeEntityInterface ) throw OAuthServerException::invalidScope( 'invalid_scope' );
            $id = $scope->getIdentifier();
            if ( ! $this->allows( $id ) ) throw OAuthServerException::invalidScope( 'requested_scope' );
            if ( ! in_array( $id, $ids, true ) ) $ids[] = $id;
        }
        if ( ! in_array( 'sf:drafts', $ids, true ) ) throw OAuthServerException::invalidScope( 'sf:drafts' );
        return $ids;
    }

    /** @return list<string> */
    public static function storedScopes( array $record ): array {
        if ( ! is_string( $record['scopes'] ?? null ) ) throw OAuthServerException::invalidGrant( 'Invalid stored scopes.' );
        $ids = explode( ' ', $record['scopes'] );
        if ( ! in_array( 'sf:drafts', $ids, true ) || count( $ids ) !== count( array_unique( $ids ) ) ||
            array_diff( $ids, array( 'sf:drafts', 'sf:authoring', 'sf:publish' ) ) ) {
            throw OAuthServerException::invalidGrant( 'Invalid stored scopes.' );
        }
        return $ids;
    }

    public function matchesRecord( array $record, string $kind ): bool {
        return in_array( $kind, array( 'code', 'access', 'refresh' ), true ) &&
            is_string( $record['id'] ?? null ) && '' !== $record['id'] &&
            ( $record['kind'] ?? null ) === $kind &&
            ( $record['connection_id'] ?? null ) === $this->grant['id'] &&
            ( $record['client_id'] ?? null ) === $this->grant['oauth_client'] &&
            (string) ( $record['owner_id'] ?? '' ) === $this->owner() &&
            ( $record['resource'] ?? null ) === $this->resource;
    }

    public function clampExpiry( \DateTimeImmutable $expiry ): \DateTimeImmutable {
        $limit = new \DateTimeImmutable( '@' . (int) $this->grant['expires'] );
        return $expiry > $limit ? $limit : $expiry;
    }
}

final class Client implements ClientEntityInterface {
    use ClientTrait;
    use EntityTrait;

    public function __construct( array $row ) {
        $this->setIdentifier( (string) $row['id'] );
        $this->name = (string) $row['name'];
        $this->redirectUri = (string) $row['redirect_uri'];
        $this->isConfidential = false;
    }

    public function supportsGrantType( string $grantType ): bool {
        return in_array( $grantType, array( 'authorization_code', 'refresh_token' ), true );
    }
}

final class Scope implements ScopeEntityInterface {
    use ScopeTrait;
    use EntityTrait;

    public function __construct( string $identifier ) { $this->setIdentifier( $identifier ); }
}

final class User implements UserEntityInterface {
    use EntityTrait;

    public function __construct( string $identifier ) { $this->setIdentifier( $identifier ); }
}

final class AuthCode implements AuthCodeEntityInterface {
    use AuthCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;

    public function __construct( public readonly Context $context ) {}

    public function setExpiryDateTime( \DateTimeImmutable $dateTime ): void {
        $this->expiryDateTime = $this->context->clampExpiry( $dateTime );
    }
}

final class RefreshToken implements RefreshTokenEntityInterface {
    use RefreshTokenTrait;
    use EntityTrait;

    public function __construct( public readonly Context $context ) {}

    public function setExpiryDateTime( \DateTimeImmutable $dateTime ): void {
        $this->expiryDateTime = $this->context->clampExpiry( $dateTime );
    }
}

final class AccessToken implements AccessTokenEntityInterface {
    use TokenEntityTrait;
    use EntityTrait;
    use AccessTokenTrait;

    public function __construct( public readonly Context $context ) {}

    public function setExpiryDateTime( \DateTimeImmutable $dateTime ): void {
        $this->expiryDateTime = $this->context->clampExpiry( $dateTime );
    }

    public function toString(): string {
        $this->context->assertIdentity( $this->getClient(), $this->getUserIdentifier() );
        $scopes = $this->context->scopeIds( $this->getScopes() );
        $now = new \DateTimeImmutable();
        if ( '' === $this->getIdentifier() || $this->getExpiryDateTime() <= $now ) {
            throw OAuthServerException::invalidGrant( 'Access token is not valid.' );
        }
        // Use the trait's RSA signer and configuration, replacing only the claim set.
        $this->initJwtConfiguration();
        return 'sfo_' . $this->jwtConfiguration->builder()
            ->issuedBy( $this->context->issuer )
            ->permittedFor( $this->context->resource )
            ->relatedTo( $this->context->owner() )
            ->identifiedBy( $this->getIdentifier() )
            ->issuedAt( $now )
            ->canOnlyBeUsedAfter( $now )
            ->expiresAt( $this->getExpiryDateTime() )
            ->withClaim( 'scopes', $scopes )
            ->withClaim( 'client_id', $this->getClient()->getIdentifier() )
            ->getToken( $this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey() )
            ->toString();
    }
}
