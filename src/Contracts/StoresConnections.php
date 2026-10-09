<?php

namespace Pr4w\SocialTokens\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Models\SocialAccount;

/**
 * An action that stores what one OAuth callback returned: the one StoreConnection
 * calls for a provider (StoreAccountFromSocialite, or the action a
 * FansOutConnections connector names). Its options mean what they mean on
 * StoreConnection::handle(); an action ignores the ones that do not apply to
 * its provider, never one that does.
 */
interface StoresConnections
{
    /**
     * @return Collection<int, SocialAccount> Every account stored.
     */
    public function storeConnection(
        string $provider,
        #[\SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
        bool $restoreDisconnected = false,
    ): Collection;
}
