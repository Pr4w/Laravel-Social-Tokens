<?php

namespace Pr4w\SocialTokens\Connectors;

/**
 * Threads publishing via the Threads API.
 *
 * Token facts (per Meta developer docs; renewal behaviour is not guaranteed,
 * see SocialToken::applyRenewal() for a renewal that does not extend):
 *  - long lived access token lives ~60 days (expires_in ~5183944)
 *  - no refresh token; the long lived token is extended via th_refresh_token
 *  - extension only works while the token is still valid and at least 24h old
 *
 * Mechanically identical to the Instagram API with Instagram Login
 * (LongLivedTokenConnector), on graph.threads.net. The refresh call needs
 * only the access token, no client credentials.
 */
class ThreadsConnector extends LongLivedTokenConnector
{
    protected const REFRESH_URL = 'https://graph.threads.net/refresh_access_token';

    protected const EXCHANGE_URL = 'https://graph.threads.net/access_token';

    protected const ME_URL = 'https://graph.threads.net/v1.0/me';

    protected function refreshUrl(): string
    {
        return static::REFRESH_URL;
    }

    protected function exchangeUrl(): string
    {
        return static::EXCHANGE_URL;
    }

    protected function meUrl(): string
    {
        return static::ME_URL;
    }

    protected function refreshGrant(): string
    {
        return 'th_refresh_token';
    }

    protected function exchangeGrant(): string
    {
        return 'th_exchange_token';
    }

    protected function label(): string
    {
        return 'Threads';
    }

    /**
     * Threads' exchange has always sent the client id with the secret.
     *
     * @return array<string, mixed>
     */
    protected function exchangeParameters(#[\SensitiveParameter] string $accessToken): array
    {
        return [
            'grant_type' => $this->exchangeGrant(),
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'access_token' => $accessToken,
        ];
    }
}
