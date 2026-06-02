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
 * Instagram publishing via the Instagram Graph API (Facebook Login path).
 *
 * Token facts (verified against Meta developer docs):
 *  - publishing uses a Facebook long lived User access token (~60 days)
 *  - there is NO refresh token; the long lived token is extended in place via
 *    the fb_exchange_token grant on graph.facebook.com
 *  - a token can only be extended when it is at least 24h old and not expired
 *
 * Note: if you use the newer "Instagram API with Instagram Login" path instead,
 * the extension call is graph.instagram.com/refresh_access_token with
 * grant_type=ig_refresh_token and no client credentials. Same strategy, swap
 * the request below.
 */
class InstagramConnector extends AbstractConnector
{
    public function key(): string
    {
        return 'instagram';
    }

    public function publishingScopes(): array
    {
        return [
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_insights',
            'pages_show_list',
            'pages_read_engagement',
            'business_management',
        ];
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function leadTime(): CarbonInterval
    {
        // 60 day token, extend roughly a week early. Well past the 24h minimum age.
        return CarbonInterval::days(7);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        if (empty($account->access_token)) {
            return RenewalResult::terminalFailure('Missing access token.');
        }

        $version = $this->config['graph_version'] ?? 'v23.0';
        $url = "https://graph.facebook.com/{$version}/oauth/access_token";

        try {
            $response = Http::acceptJson()->get($url, [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'fb_exchange_token' => $account->access_token,
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
            // No refresh token on this path: the access token is the long lived credential.
            refreshToken: null,
        );
    }
}
