<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

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
    /**
     * Instagram shares the Meta user credential, refreshed via the Facebook
     * connector (fb_exchange_token). So its credentials live under "facebook".
     */
    public function credentialProvider(): string
    {
        return 'facebook';
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function refreshCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing user token.');
        }

        return $this->extend($token->access_token);
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

        // Renewal and the connect-time long-lived exchange are the same
        // fb_exchange_token call on this path, so both go through extend().
        return $this->extend($account->access_token);
    }

    public function exchangeForLongLived(string $accessToken): ?RenewalResult
    {
        return $this->extend($accessToken);
    }

    private function extend(string $accessToken): RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';
        $url = "https://graph.facebook.com/{$version}/oauth/access_token";

        $response = $this->attempt(fn () => Http::acceptJson()->get($url, [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $accessToken,
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
            return RenewalResult::unknownFailure('Malformed response, no access_token.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return RenewalResult::success(
            accessToken: $token,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            // No refresh token on this path: the access token is the long lived credential.
            refreshToken: null,
        );
    }
}
