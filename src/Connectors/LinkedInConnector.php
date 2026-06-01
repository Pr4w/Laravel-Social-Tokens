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
 * LinkedIn publishing via the Posts API (part of the Community Management API).
 *
 * Token facts (verified against LinkedIn / Microsoft developer docs):
 *  - access token lives 60 days
 *  - refresh tokens are issued to approved partner programs (Community
 *    Management / Marketing Developer Platform), and live 365 days
 *  - the refresh token TTL does NOT reset on use: it counts down from issuance,
 *    so the member must re-authorise within a year regardless
 *
 * Scope note:
 *  - posting to a PERSONAL profile uses w_member_social
 *  - posting to a COMPANY page uses w_organization_social, restricted to members
 *    with an admin role on that page (ADMINISTRATOR / DIRECT_SPONSORED_CONTENT_
 *    POSTER / RECRUITING_POSTER)
 *  - the default below targets company pages via the Community Management API;
 *    override 'scopes' in config to change it
 *
 * Strategy is config driven:
 *  - default: ReauthOnly, the account is flagged for reconnection ahead of the
 *    60 day expiry
 *  - with 'refresh_enabled' => true (you have refresh tokens): StableRefreshToken
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
        return $this->config['scopes'] ?? [
            'r_basicprofile',
            'rw_organization_admin',
            'w_member_social',

            'r_member_postAnalytics',
            'r_member_profileAnalytics',

            'w_organization_social',
            'r_organization_social',

            'r_organization_followers',

            'r_organization_social_feed',
            'w_organization_social_feed',

            'w_member_social_feed',

            'r_1st_connections_size'
        ];
    }

    public function renewalStrategy(): RenewalStrategy
    {
        return ($this->config['refresh_enabled'] ?? false)
            ? RenewalStrategy::StableRefreshToken
            : RenewalStrategy::ReauthOnly;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(5);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
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
            refreshToken: $body['refresh_token'] ?? null,
            refreshExpiresAt: isset($body['refresh_token_expires_in'])
                ? now()->addSeconds((int) $body['refresh_token_expires_in'])
                : null,
        );
    }
}
