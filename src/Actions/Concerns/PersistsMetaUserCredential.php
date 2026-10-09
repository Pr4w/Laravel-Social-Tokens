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
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>|null  $debug  debugUserToken()'s result.
     */
    protected function persistUserCredential(
        MetaGraphConnector $connector,
        array $keys,
        #[\SensitiveParameter] string $userToken,
        ?CarbonInterface $expiresAt,
        ?array $debug,
    ): SocialToken {
        $lifetime = $debug === null ? null : MetaGraphConnector::tokenLifetime($debug);
        $expiresAt ??= $lifetime['expires_at'] ?? null;
        $static = $expiresAt === null && ($lifetime['never_expires'] ?? false);

        return SocialToken::persistCredential($keys, [
            'access_token' => $userToken,
            'refresh_token' => null,
            'expires_at' => $expiresAt,
            'renew_at' => $static ? null : SocialToken::renewAtFor($expiresAt, $connector), // an unknown expiry is checked back
        ] + array_filter(['data_access_expires_at' => $lifetime['data_access_expires_at'] ?? null]));
    }
}
