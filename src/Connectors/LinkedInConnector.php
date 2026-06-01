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
 * LinkedIn publishing via the Posts API.
 *
 * Token facts (verified against LinkedIn / Microsoft developer docs):
 *  - access token lives 60 days
 *  - refresh tokens are ONLY issued to approved Marketing Developer Platform
 *    (MDP) partners, and live 365 days
 *  - the refresh token TTL does NOT reset on use: it counts down from issuance,
 *    so even MDP partners must have the member re-authorise within a year
 *
 * Therefore the strategy is config driven:
 *  - default: ReauthOnly. The token is flagged for reconnection ahead of the
 *    60 day expiry so the user re-authorises in time.
 *  - with 'refresh_enabled' => true (MDP partners): StableRefreshToken, using
 *    the standard refresh_token grant. The eventual 365 day cap still surfaces
 *    as a needs_reconnect once the refresh token's own window closes.
 */
class LinkedInConnector extends AbstractConnector
{
    protected const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    public function key(): string
    {
        return 'linkedin';
    }

    public function publishingScopes(): array
    {
        return ['openid', 'profile', 'email', 'w_member_social'];
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return ($this->config['refresh_enabled'] ?? false)
            ? RenewalStrategy::StableRefreshToken
            : RenewalStrategy::ReauthOnly;
    }

    public function leadTime(): CarbonInterval
    {
        // Generous, so the reconnection nudge reaches the user days before the
        // 60 day token actually dies.
        return CarbonInterval::days(5);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        // Only reachable when refresh is enabled (MDP). Callers check
        // canRenewUnattended() first, but guard anyway.
        if (empty($account->refresh_token)) {
            return RenewalResult::terminalFailure('No refresh token (re-authorisation required).');
        }

        try {
            $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
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
            $error = (string) $body['error'];
            $description = (string) ($body['error_description'] ?? '');

            // invalid_grant: refresh token expired or revoked, the one year cap
            // has likely been reached. The member must re-authorise.
            return RenewalResult::terminalFailure(trim("{$error}: {$description}"));
        }

        $accessToken = $body['access_token'] ?? null;

        if ($accessToken === null) {
            return RenewalResult::transientFailure('Malformed response, no access_token.');
        }

        return RenewalResult::success(
            accessToken: $accessToken,
            expiresAt: isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            // LinkedIn returns a refresh token, but with the SAME decreasing TTL.
            // Persist it and track its expiry so the one year cap surfaces.
            refreshToken: $body['refresh_token'] ?? null,
            refreshExpiresAt: isset($body['refresh_token_expires_in'])
                ? now()->addSeconds((int) $body['refresh_token_expires_in'])
                : null,
        );
    }
}
