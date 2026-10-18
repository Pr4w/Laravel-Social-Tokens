<?php

namespace Pr4w\SocialTokens\Socialite;

use Laravel\Socialite\Two\User;

/**
 * A Bluesky connection: the Socialite user, plus what its credential must keep
 * to use the tokens (BlueskyConnector::credentialAttributesFrom()): the DPoP
 * key they are bound to, and the account's PDS and authorization server.
 * getId() is the account's DID, getNickname() its handle.
 */
class BlueskyUser extends User
{
    /** The connection's DPoP private key (PEM). */
    public string $dpopKey = '';

    /**
     * issuer, token_endpoint, revocation_endpoint, pds.
     *
     * @var array<string, string|null>
     */
    public array $credentialMetadata = [];

    /** The account's PDS, where its API calls go. */
    public function pds(): ?string
    {
        return $this->credentialMetadata['pds'] ?? null;
    }
}
