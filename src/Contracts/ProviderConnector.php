<?php

namespace Pr4w\SocialTokens\Contracts;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The per-provider brain. All provider specific behaviour lives behind this
 * interface so the rest of the package never knows a TikTok from a LinkedIn.
 */
interface ProviderConnector
{
    /** How this provider's tokens are kept alive. */
    public function renewalStrategy(): RenewalStrategy;

    /**
     * How long before expiry the token should be renewed. Token lifetimes range
     * from one hour to sixty days, so this is per provider.
     */
    public function leadTime(): CarbonInterval;

    /**
     * Attempt to renew the account's token. Must not write to the database.
     * Returns a RenewalResult that the caller applies.
     */
    public function renew(SocialAccount $account): RenewalResult;

    /**
     * Exchange a freshly connected access token for its long-lived form, for
     * providers that require a distinct step at connect (Instagram, Threads).
     * Returns null when the connect token is already durable (most providers).
     * Must not write to the database.
     */
    public function exchangeForLongLived(string $accessToken): ?RenewalResult;

    /** Revoke the token on the provider side, if supported. */
    public function revoke(SocialAccount $account): void;
}
