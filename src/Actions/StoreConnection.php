<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * The single entry point for persisting a freshly connected account. Call this
 * from your OAuth callback for every provider: it stores the account, upgrades
 * short-lived tokens, and handles Facebook's page fan-out, then returns every
 * account it stored.
 *
 * The return type is always a Collection because a connection can yield more
 * than one account — most providers give exactly one, Facebook gives one per
 * managed page. It delegates to StoreAccountFromSocialite and StoreFacebookPages,
 * which remain available if you need the lower-level path directly.
 */
class StoreConnection
{
    public function __construct(
        protected ConnectorRegistry $registry,
        protected StoreAccountFromSocialite $single,
        protected StoreFacebookPages $facebookPages,
    ) {
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    public function handle(
        string $provider,
        SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
    ): Collection {
        $connector = $this->registry->has($provider) ? $this->registry->for($provider) : null;

        // Facebook fans out to one account per managed page.
        if ($connector instanceof FacebookConnector) {
            return $this->facebookPages->handle(
                userToken: $user->token,
                owner: $owner,
                connectedBy: $connectedBy,
                userId: $user->getId(),
            );
        }

        // Every other provider yields a single account.
        return collect([
            $this->single->handle($provider, $user, $owner, $connectedBy, $longLived),
        ]);
    }
}
