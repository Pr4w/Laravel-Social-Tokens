<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Facebook Page publishing via the Pages API.
 *
 * Token model (Facebook Login path, shared with Instagram):
 *  - the only credential that expires is the long lived USER token (~60 days)
 *  - a Page access token is derived from it via /me/accounts and stays valid
 *    as long as the user token does
 *
 * So a facebook row stores:
 *  - access_token  = the Page access token (ready to post with)
 *  - refresh_token = the long lived USER token (encrypted), used to re-derive
 *
 * Renewal extends the user token, then re-pulls this page's token from
 * /me/accounts. Both are rotated, hence RotatingRefreshToken.
 */
class FacebookConnector extends AbstractConnector
{
    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::RotatingRefreshToken;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(7);
    }

    public function renew(SocialAccount $account): RenewalResult
    {
        // The user token (root credential) lives in refresh_token for facebook rows.
        $userToken = $account->refresh_token;

        if (empty($userToken)) {
            return RenewalResult::terminalFailure('Missing user token to re-derive the page token.');
        }

        // 1. Extend the long lived user token.
        $extended = $this->extendUserToken($userToken);

        if ($extended instanceof RenewalResult) {
            return $extended;
        }

        // 2. Re-derive this page's token with the fresh user token.
        $pages = $this->fetchPages($extended['token']);

        if ($pages instanceof RenewalResult) {
            return $pages;
        }

        $pageToken = null;

        foreach ($pages as $page) {
            if (($page['id'] ?? null) === (string) $account->provider_user_id) {
                $pageToken = $page['access_token'] ?? null;
                break;
            }
        }

        if ($pageToken === null) {
            // The user is no longer an admin of this page, or it is gone.
            return RenewalResult::terminalFailure('Page not found among managed pages (admin access lost?).');
        }

        return RenewalResult::success(
            accessToken: $pageToken,             // fresh page token, ready to post with
            expiresAt: $extended['expiresAt'],   // tied to the user token expiry
            refreshToken: $extended['token'],    // rotate the stored user token
        );
    }

    /**
     * Exchange a user token for a fresh long-lived one via the fb_exchange_token
     * grant. Idempotent: safe to call on a token that is already long lived.
     * Shared by renew() and the initial page-seeding action.
     *
     * @return array{token: string, expiresAt: ?\Illuminate\Support\Carbon}|RenewalResult
     */
    public function extendUserToken(string $userToken): array|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => Http::acceptJson()->get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $userToken,
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
            return RenewalResult::unknownFailure('Malformed token extension response.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return [
            'token' => $token,
            'expiresAt' => isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
        ];
    }

    /**
     * The Facebook user id behind a token (the credential holder). Used to key a
     * user's page rows so a reconnect can reconcile the ones they no longer manage.
     *
     * @return string|RenewalResult
     */
    public function fetchUserId(string $userToken): string|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => Http::withToken($userToken)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/me", ['fields' => 'id']));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $id = $body['id'] ?? null;

        if ($id === null) {
            return RenewalResult::unknownFailure('Malformed /me response, no id.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
        }

        return (string) $id;
    }

    /**
     * List the Pages a user token can manage, each carrying its own page access
     * token. Shared by renew() (find one page), the page-seeding action (all
     * pages), and the Instagram action (which requests the linked IG account
     * field via $fields).
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    public function fetchPages(string $userToken, string $fields = 'id,name,access_token,picture{url}'): array|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => Http::withToken($userToken)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/me/accounts", [
                'fields' => $fields,
            ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json() ?? [];

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        return $body['data'] ?? [];
    }
}
