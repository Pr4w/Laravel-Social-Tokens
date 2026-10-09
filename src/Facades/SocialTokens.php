<?php

namespace Pr4w\SocialTokens\Facades;

use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens as SocialTokensService;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Testing\SocialTokensFake;

/**
 * @method static ProviderConnector connector(string $provider)
 * @method static string validAccessTokenFor(SocialAccount $account, int $minValiditySeconds = 30)
 * @method static RenewalResult renewCredential(SocialToken $token, int $minValiditySeconds = 30)
 * @method static bool reportRejected(SocialAccount $account, string $reason, bool $terminal = true, ?string $rejectedToken = null)
 * @method static void disconnect(SocialAccount $account)
 * @method static void revoke(SocialToken $token)
 * @method static EloquentCollection<int, SocialAccount> forgetOwner(Model $owner)
 * @method static EloquentCollection<int, SocialAccount> revokeExternalUser(string $provider, string $externalId)
 * @method static EloquentCollection<int, SocialAccount> purgeExternalUser(string $provider, string $externalId, ?Closure $deleted = null)
 * @method static SocialTokensFake renewWith(RenewalResult|Closure $result, ?string $provider = null)
 * @method static SocialTokensFake checkWith(RenewalResult|Closure $result, ?string $provider = null)
 * @method static list<SocialToken> renewals()
 * @method static list<SocialToken> revocations()
 * @method static list<SocialToken> checks()
 * @method static void assertRenewed(SocialToken|SocialAccount|null $credential = null, ?int $times = null)
 * @method static void assertNotRenewed(SocialToken|SocialAccount|null $credential = null)
 * @method static void assertRevokedAtProvider(SocialToken|SocialAccount|null $credential = null, ?int $times = null)
 * @method static void assertNotRevokedAtProvider(SocialToken|SocialAccount|null $credential = null)
 * @method static void assertChecked(SocialToken|SocialAccount|null $credential = null, ?int $times = null)
 * @method static void assertNotChecked(SocialToken|SocialAccount|null $credential = null)
 *
 * @see SocialTokensService
 * @see SocialTokensFake for the assertions, once fake() is called.
 */
class SocialTokens extends Facade
{
    /**
     * Answer the providers' renewal, revocation and check calls in place of
     * the providers, for the rest of the test (see SocialTokensFake). Every
     * class that resolves the SocialTokens service from now on gets the fake:
     * call it before resolving your own classes that take it. Calling it again
     * returns the same fake.
     */
    public static function fake(): SocialTokensFake
    {
        $current = static::getFacadeRoot();

        if ($current instanceof SocialTokensFake) {
            return $current;
        }

        static::swap($fake = new SocialTokensFake(app(ConnectorRegistry::class)));

        return $fake;
    }

    /**
     * The service's class, not its 'social-tokens' alias: swap() binds the
     * fake under this key, which is the one constructors and jobs resolve.
     */
    protected static function getFacadeAccessor(): string
    {
        return SocialTokensService::class;
    }
}
