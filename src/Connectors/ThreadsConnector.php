<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;
use Throwable;

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

    public function key(): string
    {
        return 'threads';
    }

    public function publishingScopes(): array
    {
        return [
            'threads_basic',
            'threads_content_publish',
            'threads_manage_insights'
        ];
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(7);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        if (empty($account->access_token)) {
            return RenewalResult::terminalFailure('Missing access token.');
        }

        try {
            $response = Http::acceptJson()->get(self::REFRESH_URL, [
                'grant_type' => 'th_refresh_token',
                'access_token' => $account->access_token,
            ]);
        } catch (ConnectionException $e) {
            return RenewalResult::transientFailure('Connection error: '.$e->getMessage());
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.$e->getMessage());
        }

        if ($response->serverError() || $response->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$response->status());
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $accessToken = $body['access_token'] ?? null;

        if ($accessToken === null) {
            return RenewalResult::transientFailure('Malformed response, no access_token.');
        }

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            refreshToken: null,
        );
    }
}
