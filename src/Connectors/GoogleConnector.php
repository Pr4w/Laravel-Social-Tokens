<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Throwable;

/**
 * YouTube Shorts publishing via the YouTube Data API (Google OAuth2).
 *
 * Token facts (Google OAuth2, stable):
 *  - access_token lives ~1h (expires_in 3600)
 *  - the refresh_token is long lived and does NOT rotate; Google does not
 *    return a new refresh_token on refresh, so the existing one is kept
 *  - to receive a refresh_token at connect, the authorization request must use
 *    access_type=offline and prompt=consent (see the controller note)
 *  - a revoked or stale refresh_token returns invalid_grant: reconnect needed
 */
class GoogleConnector extends OAuth2Connector
{
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::StableRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 1h token. The lead must exceed the dispatcher cadence (15 min) plus
        // queue latency, or the token routinely expires before its renewal.
        return CarbonInterval::minutes(25);
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function missingRefreshTokenReason(): string
    {
        return 'Missing refresh token (was access_type=offline used?).';
    }

    /**
     * Google answers invalid_request without saying why (e.g. "Could not
     * determine client ID"): keep it unknown and retried. admin_policy_enforced:
     * a Workspace admin blocked the app for this user, retrying cannot help.
     */
    protected function classifyError(string $error, string $description, int $status): ?string
    {
        return match ($error) {
            'invalid_request' => null,
            'admin_policy_enforced' => self::ERROR_TERMINAL,
            default => parent::classifyError($error, $description, $status),
        };
    }

    public function revoke(SocialToken $credential): void
    {
        $token = $credential->refresh_token ?: $credential->access_token;

        if (empty($token)) {
            return;
        }

        try {
            $this->http()->asForm()->post(self::REVOKE_URL, ['token' => $token]);
        } catch (Throwable) {
            // Best effort.
        }
    }
}
