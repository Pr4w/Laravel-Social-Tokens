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
     * Per-account effective scopes, resolved via debug_token. Meta grants scopes
     * granularly: a user may approve a scope for some Pages / IG accounts and not
     * others, so the token-level scope list is not accurate per account. For each
     * requested account id this returns the scopes actually in effect for it —
     * every token scope minus the granular scopes granted only for other accounts.
     *
     * @param  array<int, string>  $accountIds
     * @return array<string, array<int, string>>|RenewalResult  [account_id => scopes]
     */
    public function grantedScopesByAccount(string $userToken, array $accountIds): array|RenewalResult
    {
        $appToken = $this->appAccessToken();

        if ($appToken instanceof RenewalResult) {
            return $appToken;
        }

        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => Http::acceptJson()->get("https://graph.facebook.com/{$version}/debug_token", [
            'input_token' => $userToken,
            'access_token' => $appToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $data = $response->json('data', []);

        if (! empty($data['error'])) {
            return MetaErrorMapper::map($data['error']);
        }

        $allScopes = $data['scopes'] ?? [];
        $granular = $data['granular_scopes'] ?? [];

        $result = [];

        foreach ($accountIds as $accountId) {
            $accountId = (string) $accountId;
            $scopes = $allScopes;

            foreach ($granular as $entry) {
                $targets = $entry['target_ids'] ?? null;

                // A granular scope with target_ids is in effect only for those
                // accounts; drop it for the others. Untargeted scopes apply to all.
                if (is_array($targets) && ! in_array($accountId, array_map('strval', $targets), true)) {
                    $scopes = array_diff($scopes, [$entry['scope'] ?? null]);
                }
            }

            $result[$accountId] = array_values($scopes);
        }

        return $result;
    }

    /**
     * App access token for calling debug_token. Meta accepts the literal string
     * "{app-id}|{app-secret}" as the app token, so there is no endpoint to call
     * and nothing to cache.
     *
     * @return string|RenewalResult
     */
    protected function appAccessToken(): string|RenewalResult
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === null || $clientSecret === null) {
            return RenewalResult::terminalFailure('Missing Facebook client credentials for the app access token.');
        }

        return "{$clientId}|{$clientSecret}";
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
