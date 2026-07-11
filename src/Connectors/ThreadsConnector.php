<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Threads publishing via the Threads API.
 *
 * Token facts (verified against Meta developer docs):
 *  - long lived access token lives ~60 days (expires_in ~5183944)
 *  - no refresh token; the long lived token is extended via th_refresh_token
 *  - extension only works while the token is still valid and at least 24h old
 *
 * Mechanically identical to Instagram's strategy, different host and grant.
 * The refresh call needs only the access token, no client credentials.
 */
class ThreadsConnector extends AbstractConnector
{
    protected const REFRESH_URL = 'https://graph.threads.net/refresh_access_token';

    protected const EXCHANGE_URL = 'https://graph.threads.net/access_token';

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
        return $this->refreshWithToken($token->access_token);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        return $this->refreshWithToken($account->access_token);
    }

    private function refreshWithToken(?string $accessToken): RenewalResult
    {
        if (empty($accessToken)) {
            return RenewalResult::terminalFailure('Missing access token.');
        }

        $response = $this->attempt(fn () => Http::acceptJson()->get(self::REFRESH_URL, [
            'grant_type' => 'th_refresh_token',
            'access_token' => $accessToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $accessToken = $body['access_token'] ?? null;

        if ($accessToken === null) {
            return RenewalResult::unknownFailure('Malformed response, no access_token.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            refreshToken: null,
        );
    }

    /**
     * Connect-time exchange: swap the short lived token Socialite returns for the
     * ~60 day long lived one. Uses the th_exchange_token grant (client credentials
     * required), distinct from the th_refresh_token grant used by renew().
     */
    public function exchangeForLongLived(string $accessToken): ?RenewalResult
    {
        $response = $this->attempt(fn () => Http::acceptJson()->get(self::EXCHANGE_URL, [
            'grant_type' => 'th_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'access_token' => $accessToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $token = $body['access_token'] ?? null;

        if ($token === null) {
            return RenewalResult::unknownFailure('Malformed th_exchange_token response.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return RenewalResult::success(
            accessToken: $token,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
        );
    }
}
