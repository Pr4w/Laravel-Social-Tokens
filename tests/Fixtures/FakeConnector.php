<?php

namespace Pr4w\SocialTokens\Tests\Fixtures;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * A connector whose behaviour is driven by static state, so tests can control
 * renewal outcomes, strategy, lead time and the connect-time exchange without
 * any HTTP. Register it under a provider key via config and reset() per test.
 */
class FakeConnector extends AbstractConnector
{
    public static ?RenewalResult $nextResult = null;

    public static int $renewCalls = 0;

    public static RenewalStrategy $strategy = RenewalStrategy::RotatingRefreshToken;

    public static ?CarbonInterval $lead = null;

    public static ?RenewalResult $exchangeResult = null;

    public static int $revokeCalls = 0;

    public function renewalStrategy(): RenewalStrategy
    {
        return static::$strategy;
    }

    public function leadTime(): CarbonInterval
    {
        return static::$lead ?? CarbonInterval::minutes(15);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        static::$renewCalls++;

        return static::$nextResult
            ?? RenewalResult::success(accessToken: 'renewed-token', expiresAt: now()->addHour());
    }

    public function exchangeForLongLived(string $accessToken): ?RenewalResult
    {
        return static::$exchangeResult;
    }

    public function revoke(SocialAccount $account): void
    {
        static::$revokeCalls++;
    }

    public static function reset(): void
    {
        static::$nextResult = null;
        static::$renewCalls = 0;
        static::$strategy = RenewalStrategy::RotatingRefreshToken;
        static::$lead = null;
        static::$exchangeResult = null;
        static::$revokeCalls = 0;
    }
}
