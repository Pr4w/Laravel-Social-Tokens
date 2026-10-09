<?php

namespace Pr4w\SocialTokens\Tests\Fixtures;

use Carbon\CarbonInterval;
use Closure;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * A connector whose behaviour is driven by static state, so tests can control
 * renewal outcomes, strategy, lead time and the connect-time exchange without
 * any HTTP. Register it under a provider key via config and reset() per test.
 */
class FakeConnector extends AbstractConnector
{
    public static ?RenewalResult $nextResult = null;

    /**
     * Per-credential outcome, when a test needs different results for
     * different credentials. Wins over $nextResult.
     *
     * @var (Closure(SocialToken): RenewalResult)|null
     */
    public static ?Closure $resultUsing = null;

    public static int $renewCalls = 0;

    public static RenewalStrategy $strategy = RenewalStrategy::RotatingRefreshToken;

    public static ?CarbonInterval $lead = null;

    public static ?RenewalResult $exchangeResult = null;

    public static int $revokeCalls = 0;

    /**
     * Runs inside revoke(), e.g. to throw or to look at the credential then.
     *
     * @var (Closure(SocialToken): void)|null
     */
    public static ?Closure $revokeUsing = null;

    public function renewalStrategy(): RenewalStrategy
    {
        return static::$strategy;
    }

    public function leadTime(): CarbonInterval
    {
        return static::$lead ?? CarbonInterval::minutes(15);
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        static::$renewCalls++;

        if (static::$resultUsing !== null) {
            return (static::$resultUsing)($token);
        }

        return static::$nextResult
            ?? RenewalResult::success(accessToken: 'renewed-token', expiresAt: now()->addHour());
    }

    public function exchangeForLongLived(#[\SensitiveParameter] string $accessToken): ?RenewalResult
    {
        return static::$exchangeResult;
    }

    public function revoke(SocialToken $token): void
    {
        static::$revokeCalls++;

        if (static::$revokeUsing !== null) {
            (static::$revokeUsing)($token);
        }
    }

    public static function reset(): void
    {
        static::$nextResult = null;
        static::$resultUsing = null;
        static::$renewCalls = 0;
        static::$strategy = RenewalStrategy::RotatingRefreshToken;
        static::$lead = null;
        static::$exchangeResult = null;
        static::$revokeCalls = 0;
        static::$revokeUsing = null;
    }
}
