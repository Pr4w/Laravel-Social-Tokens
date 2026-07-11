<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;
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
class GoogleConnector extends AbstractConnector
{
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::StableRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        // 1h token, renew a few minutes early.
        return CarbonInterval::minutes(10);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        if (empty($account->refresh_token)) {
            return RenewalResult::terminalFailure('Missing refresh token (was access_type=offline used?).');
        }

        $response = $this->attempt(fn () => Http::asForm()
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return $this->mapError($body);
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
            // Stable strategy: Google does not return a refresh token here, so we
            // pass null and keep the one we already stored.
            refreshToken: null,
            profile: array_filter([
                'scope' => $body['scope'] ?? null,
            ]),
        );
    }

    public function revoke(SocialAccount $account): void
    {
        $token = $account->refresh_token ?: $account->access_token;

        if (empty($token)) {
            return;
        }

        try {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $token]);
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function mapError(array $body): RenewalResult
    {
        $error = (string) ($body['error'] ?? 'unknown');
        $description = (string) ($body['error_description'] ?? '');

        $terminal = [
            'invalid_grant',     // refresh token revoked, expired, or password changed
            'invalid_client',
            'unauthorized_client',
        ];

        return in_array($error, $terminal, true)
            ? RenewalResult::terminalFailure(trim("{$error}: {$description}"))
            : RenewalResult::unknownFailure(trim("{$error}: {$description}"), [
                'error' => $error,
                'error_description' => $description,
            ]);
    }
}
