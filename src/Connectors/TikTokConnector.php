<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Throwable;

/**
 * TikTok Content Posting API connector.
 *
 * Token facts (verified against TikTok developer docs):
 *  - access_token lives 24h (expires_in 86400)
 *  - refresh_token lives 365 days (refresh_expires_in 31536000) and ROTATES:
 *    every refresh returns a new refresh_token, the old one is dropped
 *  - refresh uses client_key (not client_id) and needs no user consent
 *
 * The refresh flow itself lives in OAuth2Connector; this class only declares
 * TikTok's endpoint, parameter names and error codes.
 */
class TikTokConnector extends OAuth2Connector
{
    protected const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    protected const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 24h token, renew a couple of hours early.
        return CarbonInterval::hours(2);
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    /** TikTok names the client id `client_key`. */
    protected function refreshParameters(string $refreshToken): array
    {
        return [
            'client_key' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];
    }

    protected function refreshExpiresInKey(): ?string
    {
        return 'refresh_expires_in';
    }

    protected function profileFrom(array $body): array
    {
        return array_filter([
            'open_id' => $body['open_id'] ?? null,
            'scope' => $body['scope'] ?? null,
        ]);
    }

    /** access_denied: the user withdrew the app's access. */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return $error === 'access_denied' ? self::ERROR_TERMINAL : parent::classifyError($error, $description, $status);
    }

    public function revoke(SocialToken $token): void
    {
        if (empty($token->access_token)) {
            return;
        }

        try {
            $this->http()->asForm()->post(self::REVOKE_URL, [
                'client_key' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token' => $token->access_token,
            ]);
        } catch (Throwable) {
            // Best effort. The local status is what matters for our flow.
        }
    }
}
