<?php

namespace Pr4w\SocialTokens\Actions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Socialite\Two\User as SocialiteUser;
use LogicException;
use Pr4w\SocialTokens\Contracts\FansOutConnections;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Contracts\StoresConnections;
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
 * managed page. Which action stores it is the connector's call: one that fans
 * out (FansOutConnections) names its own (StoreFacebookPages,
 * StoreInstagramAccounts), every other goes to StoreAccountFromSocialite.
 * Those remain available if you need the lower-level path directly.
 */
class StoreConnection
{
    public function __construct(
        protected ConnectorRegistry $registry,
        protected Container $container,
        protected StoreAccountFromSocialite $single,
    ) {}

    /**
     * @param  bool  $longLived  Exchange the token for its long-lived form first, where the
     *                           provider needs a distinct step (Threads, Instagram Login, and
     *                           the Meta user token behind Facebook and Instagram). Pass false
     *                           only with a token that already is long-lived.
     * @param  CarbonInterface|null  $refreshExpiresAt  The refresh token's expiry, when you know it
     *                                                  (see StoreAccountFromSocialite). Not used by the
     *                                                  Facebook and Instagram fan-outs.
     * @param  bool  $restoreDisconnected  A fan-out connection leaves out the Pages and Instagram
     *                                     accounts the user disconnected (SocialTokens::disconnect());
     *                                     true brings them back. A single account is always restored.
     * @return Collection<int, SocialAccount>
     *
     * @throws LogicException when a fan-out connector names an action that cannot store connections.
     */
    public function handle(
        string $provider,
        #[\SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
        bool $restoreDisconnected = false,
    ): Collection {
        // Throws InvalidArgumentException for a provider without a connector.
        $connector = $this->registry->for($provider);

        return $this->actionFor($connector)->storeConnection(
            provider: $provider,
            user: $user,
            owner: $owner,
            connectedBy: $connectedBy,
            longLived: $longLived,
            refreshExpiresAt: $refreshExpiresAt,
            restoreDisconnected: $restoreDisconnected,
        );
    }

    protected function actionFor(ProviderConnector $connector): StoresConnections
    {
        if (! $connector instanceof FansOutConnections) {
            return $this->single;
        }

        $action = $this->container->make($connector->connectionAction());

        if (! $action instanceof StoresConnections) {
            throw new LogicException(sprintf(
                '%s::connectionAction() must name a %s, not [%s].',
                $connector::class,
                StoresConnections::class,
                get_debug_type($action),
            ));
        }

        return $action;
    }
}
