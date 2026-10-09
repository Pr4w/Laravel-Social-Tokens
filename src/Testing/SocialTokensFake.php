<?php

namespace Pr4w\SocialTokens\Testing;

use Closure;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Testing\Concerns\RecordsProviderCalls;

/**
 * SocialTokens for an app's tests, installed by Facades\SocialTokens::fake().
 * Everything runs as in production, against the test database, except the
 * calls to the providers: a renewal succeeds with a new token
 * (RENEWED_TOKEN) valid one hour past the connector's lead time, a
 * provider-side revocation does nothing, and the check reportRejected()
 * makes finds the token working, unless renewWith() and checkWith() say
 * otherwise. Each call is recorded for the assertions.
 *
 * The connectors stay the real ones, so their strategies, lead times and
 * the key a credential renews under (an Instagram account's is facebook)
 * are production's: validAccessTokenFor() never renews a LinkedIn credential
 * without a refresh token, and only connectors that check credentials are
 * asked by reportRejected(). Connecting (StoreConnection and the Store*
 * actions) and social-tokens:check-static still call the providers: fake
 * those with Http::fake().
 */
class SocialTokensFake extends SocialTokens
{
    use RecordsProviderCalls;

    /** @var array<string, RenewalResult|(Closure(SocialToken): RenewalResult)> */
    protected array $renewalResults = [];

    /** @var array<string, RenewalResult|(Closure(SocialToken): RenewalResult)> */
    protected array $checkResults = [];

    /**
     * What the provider answers a renewal: for the credentials of $provider
     * (the key they renew under), or of every provider. A closure receives
     * the credential.
     *
     * @param  RenewalResult|(Closure(SocialToken): RenewalResult)  $result
     */
    public function renewWith(RenewalResult|Closure $result, ?string $provider = null): static
    {
        $this->renewalResults[$provider ?? '*'] = $result;

        return $this;
    }

    /**
     * What the provider answers reportRejected()'s check: success (the token
     * works), or a terminal failure, ['definitive' => true] in its context to
     * flag the credential at once.
     *
     * @param  RenewalResult|(Closure(SocialToken): RenewalResult)  $result
     */
    public function checkWith(RenewalResult|Closure $result, ?string $provider = null): static
    {
        $this->checkResults[$provider ?? '*'] = $result;

        return $this;
    }

    /**
     * The credentials whose token reportRejected() asked the provider about.
     *
     * @return list<SocialToken>
     */
    public function checks(): array
    {
        return $this->calls['check'];
    }

    public function assertChecked(SocialToken|SocialAccount|null $credential = null, ?int $times = null): void
    {
        $this->assertCalled('check', 'checked at the provider', $credential, $times);
    }

    public function assertNotChecked(SocialToken|SocialAccount|null $credential = null): void
    {
        $this->assertCalled('check', 'checked at the provider', $credential, 0);
    }

    protected function refreshAtProvider(ProviderConnector $connector, SocialToken $token): RenewalResult
    {
        $this->record('renew', $token);

        return $this->scriptedFor($this->renewalResults, $token) ?? static::renewedResult($connector->leadTime());
    }

    protected function checkAtProvider(ChecksCredential $connector, SocialToken $token): RenewalResult
    {
        $this->record('check', $token);

        return $this->scriptedFor($this->checkResults, $token) ?? RenewalResult::success(accessToken: (string) $token->access_token);
    }

    protected function revokeAtProvider(SocialToken $token): void
    {
        $this->record('revoke', $token);
    }
}
