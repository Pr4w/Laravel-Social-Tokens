<?php

namespace Pr4w\SocialTokens\Testing\Concerns;

use Carbon\CarbonInterval;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The provider calls a test double answered instead of the provider, kept
 * for the assertions: each is a copy of the credential as it was sent.
 */
trait RecordsProviderCalls
{
    /** @var array<string, list<SocialToken>> */
    protected array $calls = ['renew' => [], 'revoke' => [], 'check' => []];

    /** The access token every faked renewal returns, unless told otherwise. */
    public const RENEWED_TOKEN = 'fake-renewed-access-token';

    /**
     * The credentials renewed at the provider, as they were sent.
     *
     * @return list<SocialToken>
     */
    public function renewals(): array
    {
        return $this->calls['renew'];
    }

    /**
     * The credentials revoked at the provider, with the tokens they held.
     *
     * @return list<SocialToken>
     */
    public function revocations(): array
    {
        return $this->calls['revoke'];
    }

    /**
     * A renewal reached the provider: for that credential (an account's own),
     * $times times when given, or for any credential.
     */
    public function assertRenewed(SocialToken|SocialAccount|null $credential = null, ?int $times = null): void
    {
        $this->assertCalled('renew', 'renewed', $credential, $times);
    }

    /** No renewal reached the provider: for that credential, or at all. */
    public function assertNotRenewed(SocialToken|SocialAccount|null $credential = null): void
    {
        $this->assertCalled('renew', 'renewed', $credential, 0);
    }

    /**
     * A provider-side revocation was made: for that credential, $times times
     * when given, or for any. A local revocation alone (the provider has no
     * endpoint, or another owner still holds a grant) makes none.
     */
    public function assertRevokedAtProvider(SocialToken|SocialAccount|null $credential = null, ?int $times = null): void
    {
        $this->assertCalled('revoke', 'revoked at the provider', $credential, $times);
    }

    public function assertNotRevokedAtProvider(SocialToken|SocialAccount|null $credential = null): void
    {
        $this->assertCalled('revoke', 'revoked at the provider', $credential, 0);
    }

    protected function record(string $kind, SocialToken $token): void
    {
        $this->calls[$kind][] = clone $token;
    }

    protected function assertCalled(string $kind, string $verb, SocialToken|SocialAccount|null $credential, ?int $times): void
    {
        $id = $credential instanceof SocialAccount ? $credential->social_token_id : $credential?->getKey();
        $subject = $credential === null ? 'Any credential' : 'Credential ['.($id ?? 'none').']';

        $count = count(array_filter(
            $this->calls[$kind],
            fn (SocialToken $call) => $credential === null || ($id !== null && (string) $call->getKey() === (string) $id),
        ));

        if ($times === null) {
            PHPUnit::assertGreaterThan(0, $count, "{$subject} was not {$verb}.");

            return;
        }

        PHPUnit::assertSame($times, $count, "{$subject} was {$verb} {$count} times instead of {$times}.");
    }

    /**
     * The result scripted for this credential: its provider's, or the one for
     * every provider (keyed '*').
     *
     * @param  array<string, RenewalResult|(Closure(SocialToken): RenewalResult)>  $scripted
     */
    protected function scriptedFor(array $scripted, SocialToken $token): ?RenewalResult
    {
        $result = $scripted[$token->provider] ?? $scripted['*'] ?? null;

        return $result instanceof Closure ? $result($token) : $result;
    }

    /**
     * A successful renewal, as a provider gives one: a new access token, valid
     * one hour past the lead time, so that it is not taken for a renewal that
     * did not extend the credential.
     */
    protected static function renewedResult(CarbonInterval $lead): RenewalResult
    {
        return RenewalResult::success(
            accessToken: self::RENEWED_TOKEN,
            expiresAt: now()->add($lead)->addHour(),
        );
    }
}
