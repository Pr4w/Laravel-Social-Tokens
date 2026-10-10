<?php

namespace Pr4w\SocialTokens\Actions\Concerns;

use Carbon\CarbonInterface;
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The Meta user credential, as StoreFacebookPages and StoreInstagramAccounts
 * store it: one debug_token call per connection gives both the per-account
 * scopes and the token's lifetime.
 */
trait PersistsMetaUserCredential
{
    /**
     * debug_token's data for the user token, or null when the call failed
     * (unknown: the caller keeps whatever is stored).
     *
     * @return array<string, mixed>|null
     */
    protected function debugUserToken(MetaGraphConnector $connector, #[\SensitiveParameter] string $userToken): ?array
    {
        $data = $connector->debugToken($userToken);

        return $data instanceof RenewalResult ? null : $data;
    }

    /**
     * Store the Meta user credential a connection just obtained. With
     * debug_token's data, a token that never expires is stored static (no
     * renew_at: check-static keeps an eye on it rather than a weekly
     * fb_exchange_token), an expiry the exchange left out is taken from
     * there, and so is the data access deadline. Without it, an unknown
     * expiry is checked back after one lead time.
     *
     * A new login ($newLogin: the action exchanged a token the user just
     * granted) is what moves the data access deadline, so the stored one is
     * stale even when debug_token does not give the new one: it is cleared
     * (unknown, which also re-arms its warning) and the next renewal reads
     * it. Kept, the old deadline would show a user who just reconnected as
     * lapsed until then. A re-sync of a token the app already held keeps
     * what is stored.
     *
     * A re-sync with the very token stored (to pick up a new Page) is not a
     * new token either: its issue date stays, and so do its expiry and its
     * schedule unless debug_token reports another expiry. A renewal Meta
     * did not extend parked renew_at on the expiry and warned the user:
     * moved back a lead time before it, the next dispatcher run renewed
     * again for nothing and warned them a second time. Not a null renew_at:
     * the dispatcher unscheduled the credential while nothing used it, and
     * the accounts this re-sync stores need it renewed again.
     *
     * @param  array<model-property<SocialToken>, mixed>  $keys
     * @param  array<string, mixed>|null  $debug  debugUserToken()'s result.
     */
    protected function persistUserCredential(
        MetaGraphConnector $connector,
        array $keys,
        #[\SensitiveParameter] string $userToken,
        ?CarbonInterface $expiresAt,
        ?array $debug,
        bool $newLogin,
    ): SocialToken {
        $lifetime = $debug === null ? null : $connector::tokenLifetime($debug);
        $expiresAt ??= $lifetime['expires_at'] ?? null;
        $static = $expiresAt === null && ($lifetime['never_expires'] ?? false);
        $dataAccess = $lifetime['data_access_expires_at'] ?? null;
        $kept = [];

        $stored = $newLogin || $static ? null : SocialToken::query()
            ->where($keys + ['purpose' => SocialToken::DEFAULT_PURPOSE])
            ->first();

        if ($stored !== null && $stored->holdsAccessToken($userToken)) {
            $kept['last_renewed_at'] = $stored->last_renewed_at;

            if ($expiresAt === null || self::sameExpiry($expiresAt, $stored->expires_at)) {
                $expiresAt = $stored->expires_at;

                if ($stored->renew_at !== null) {
                    $kept['renew_at'] = $stored->renew_at;
                }
            }
        }

        return SocialToken::persistCredential($keys, $kept + [
            'access_token' => $userToken,
            'refresh_token' => null,
            'expires_at' => $expiresAt,
            'renew_at' => $static ? null : SocialToken::renewAtFor($expiresAt, $connector), // an unknown expiry is checked back
        ] + ($newLogin || $dataAccess !== null ? ['data_access_expires_at' => $dataAccess] : []));
    }

    /**
     * One expiry read twice: the stored one counts from the exchange that
     * returned it (now + expires_in), debug_token's is Meta's own timestamp.
     * An extension moves a 60-day token's expiry by days, not minutes.
     */
    private static function sameExpiry(CarbonInterface $reported, ?CarbonInterface $stored): bool
    {
        return $stored !== null && abs($reported->getTimestamp() - $stored->getTimestamp()) <= 3600;
    }
}
