<?php

namespace Pr4w\SocialTokens\Testing;

use Carbon\CarbonInterval;
use Closure;
use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Testing\Concerns\RecordsProviderCalls;

/**
 * A connector that makes no request, for a provider key of an app's own
 * tests (a custom provider, one whose connector is not written yet). Set it
 * before anything resolves the package's services, since the connector
 * registry reads the config once:
 *
 *     config(['social-tokens.connectors.acme' => ['driver' => FakeConnector::class]]);
 *     $acme = app(SocialTokens::class)->connector('acme'); // the instance the package uses
 *
 * A renewal succeeds with a new token (RENEWED_TOKEN) valid one hour past
 * the lead time, the connect-time exchange keeps the token as given, and
 * revoke() does nothing, unless told otherwise. Renewals and revocations
 * are recorded for the assertions. Its strategy (stable refresh token by
 * default) and lead time (15 minutes) can be set in the config block
 * ('strategy', a RenewalStrategy or its value; 'lead_minutes') or on the
 * instance.
 *
 * To keep the real connectors and only answer their provider calls, use
 * Facades\SocialTokens::fake() instead. The state lives on the instance,
 * which the connector registry keeps for the life of the application.
 */
class FakeConnector extends AbstractConnector
{
    use RecordsProviderCalls;

    /** @var array<string, RenewalResult|(Closure(SocialToken): RenewalResult)> */
    protected array $renewalResults = [];

    protected ?RenewalResult $exchangeResult = null;

    /** @var (Closure(SocialToken): void)|null */
    protected ?Closure $revoking = null;

    protected ?RenewalStrategy $strategy = null;

    protected ?CarbonInterval $lead = null;

    /**
     * What the provider answers every renewal. A closure receives the
     * credential.
     *
     * @param  RenewalResult|(Closure(SocialToken): RenewalResult)  $result
     */
    public function renewWith(RenewalResult|Closure $result): static
    {
        $this->renewalResults['*'] = $result;

        return $this;
    }

    /** The long-lived token a connection exchanges the token for (exchangeForLongLived()); null keeps it. */
    public function exchangeWith(?RenewalResult $result): static
    {
        $this->exchangeResult = $result;

        return $this;
    }

    /**
     * Runs inside revoke(), after it is recorded: to throw, as a connector
     * that breaks its contract would, or to look at the copy it receives.
     *
     * @param  (Closure(SocialToken): void)|null  $callback
     */
    public function revokeUsing(?Closure $callback): static
    {
        $this->revoking = $callback;

        return $this;
    }

    public function withStrategy(RenewalStrategy $strategy): static
    {
        $this->strategy = $strategy;

        return $this;
    }

    public function withLeadTime(CarbonInterval $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function renewalStrategy(): RenewalStrategy
    {
        if ($this->strategy !== null) {
            return $this->strategy;
        }

        $configured = $this->config['strategy'] ?? null;

        return $configured instanceof RenewalStrategy
            ? $configured
            : RenewalStrategy::tryFrom(is_string($configured) ? $configured : '') ?? RenewalStrategy::StableRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        return $this->lead ?? CarbonInterval::minutes(is_numeric($this->config['lead_minutes'] ?? null) ? (int) $this->config['lead_minutes'] : 15);
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        $this->record('renew', $token);

        return $this->scriptedFor($this->renewalResults, $token) ?? static::renewedResult($this->leadTime());
    }

    public function exchangeForLongLived(#[\SensitiveParameter] string $accessToken): ?RenewalResult
    {
        return $this->exchangeResult;
    }

    public function revoke(SocialToken $token): void
    {
        $this->record('revoke', $token);

        if ($this->revoking !== null) {
            ($this->revoking)($token);
        }
    }
}
