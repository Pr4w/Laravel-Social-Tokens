<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Connectors\Concerns\ParsesSignedRequests;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\VerifiesSignedRequests;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Base for the Meta APIs whose long-lived token (~60 days, no refresh token)
 * extends itself: a refresh_access_token call carrying the token alone, which
 * Meta refuses for a token less than 24 hours old, and at connect an exchange
 * of the short-lived token Socialite returns, signed with the app secret.
 * Threads (graph.threads.net) and the Instagram API with Instagram Login
 * (graph.instagram.com) differ only in their host and grant names.
 *
 * Errors come in Graph's shape and go through MetaErrorMapper. These hosts
 * are not signed with appsecret_proof. The app's Uninstall (Deauthorize) and
 * Delete callbacks are verified with its secret (VerifiesSignedRequests).
 */
abstract class LongLivedTokenConnector extends AbstractConnector implements ChecksCredential, VerifiesSignedRequests
{
    use ParsesSignedRequests;

    /** The refresh grant refuses a token less than 24 hours old (Meta docs). */
    public const MINIMUM_TOKEN_AGE_HOURS = 24;

    /** The refresh endpoint (refresh_access_token). */
    abstract protected function refreshUrl(): string;

    /** The connect-time exchange endpoint (access_token). */
    abstract protected function exchangeUrl(): string;

    /** The /me endpoint a credential check calls. */
    abstract protected function meUrl(): string;

    /** th_refresh_token, ig_refresh_token... */
    abstract protected function refreshGrant(): string;

    /** th_exchange_token, ig_exchange_token... */
    abstract protected function exchangeGrant(): string;

    /** The product's name in failure reasons. */
    abstract protected function label(): string;

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(7);
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        // last_renewed_at is when the current token was issued (renewal or
        // connection). Null means unknown (backfilled or imported rows): keep
        // trying, as before, rather than guess from updated_at or created_at.
        if ($token->last_renewed_at !== null
            && $token->last_renewed_at->greaterThan(now()->subHours(static::MINIMUM_TOKEN_AGE_HOURS))) {
            return RenewalResult::transientFailure("{$this->label()} token is less than 24h old and cannot be refreshed yet.");
        }

        return $this->refreshWithToken($token->access_token);
    }

    protected function refreshWithToken(#[\SensitiveParameter] ?string $accessToken): RenewalResult
    {
        if (empty($accessToken)) {
            return RenewalResult::terminalFailure('Missing access token.');
        }

        return $this->tokenCall($this->refreshUrl(), [
            'grant_type' => $this->refreshGrant(),
            'access_token' => $accessToken,
        ], 'Malformed response, no access_token.');
    }

    /**
     * Connect-time exchange: swap the short lived token Socialite returns for
     * the ~60 day long lived one, with the exchange grant (the app secret
     * required), distinct from the refresh grant renewals use.
     */
    public function exchangeForLongLived(#[\SensitiveParameter] string $accessToken): ?RenewalResult
    {
        return $this->tokenCall($this->exchangeUrl(), $this->exchangeParameters($accessToken), "Malformed {$this->exchangeGrant()} response.");
    }

    /**
     * The exchange request's query.
     *
     * @return array<string, mixed>
     */
    protected function exchangeParameters(#[\SensitiveParameter] string $accessToken): array
    {
        return [
            'grant_type' => $this->exchangeGrant(),
            'client_secret' => $this->clientSecret(),
            'access_token' => $accessToken,
        ];
    }

    /**
     * Ask the provider whether the token still works (GET /me), without
     * renewing it.
     */
    public function checkCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing access token.', ['definitive' => true]);
        }

        $accessToken = $token->access_token;

        $response = $this->attempt(fn () => $this->http()->acceptJson()->get($this->meUrl(), [
            'fields' => 'id',
            'access_token' => $accessToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::mapCredentialCheck($body['error']);
        }

        return isset($body['id'])
            ? RenewalResult::success(accessToken: $accessToken)
            : RenewalResult::unknownFailure("Malformed {$this->label()} /me response, no id.", ['status' => $response->status()]);
    }

    /**
     * A GET that answers with a long-lived token: transport outcomes and Graph
     * errors normalised, the token and its expiry read.
     *
     * @param  array<string, mixed>  $query
     */
    protected function tokenCall(string $url, #[\SensitiveParameter] array $query, string $malformed): RenewalResult
    {
        $response = $this->attempt(fn () => $this->http()->acceptJson()->get($url, $query));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : []; // a scalar JSON body is not an error object

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return RenewalResult::unknownFailure($malformed, [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
            ]);
        }

        return RenewalResult::success(
            accessToken: $token,
            expiresAt: is_numeric($body['expires_in'] ?? null) ? now()->addSeconds((int) $body['expires_in']) : null,
            refreshToken: null, // no refresh token: the access token is the long lived credential
        );
    }
}
