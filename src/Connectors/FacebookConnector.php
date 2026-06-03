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
    public function key(): string
    {
        return 'facebook';
    }

    public function publishingScopes(): array
    {
        return [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'business_management',
        ];
    }

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

        $version = $this->config['graph_version'] ?? 'v23.0';

        // 1. Extend the long lived user token.
        try {
            $extend = Http::acceptJson()->get("https://graph.facebook.com/{$version}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'fb_exchange_token' => $userToken,
            ]);
        } catch (ConnectionException $e) {
            return RenewalResult::transientFailure('Connection error: '.$e->getMessage());
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.$e->getMessage());
        }

        if ($extend->serverError() || $extend->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$extend->status());
        }

        $extendBody = $extend->json() ?? [];

        if (! empty($extendBody['error'])) {
            return MetaErrorMapper::map($extendBody['error']);
        }

        $newUserToken = $extendBody['access_token'] ?? null;

        if ($newUserToken === null) {
            return RenewalResult::unknownFailure('Malformed token extension response.', [
                'status' => $extend->status(),
                'body' => $extendBody,
            ]);
        }

        $expiresAt = isset($extendBody['expires_in']) ? now()->addSeconds((int) $extendBody['expires_in']) : null;

        // 2. Re-derive this page's token with the fresh user token.
        try {
            $pagesResponse = Http::withToken($newUserToken)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/me/accounts", [
                    'fields' => 'id,access_token',
                ]);
        } catch (ConnectionException $e) {
            return RenewalResult::transientFailure('Connection error: '.$e->getMessage());
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.$e->getMessage());
        }

        if ($pagesResponse->serverError() || $pagesResponse->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$pagesResponse->status());
        }

        $pageToken = null;

        foreach ($pagesResponse->json('data', []) as $page) {
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
            accessToken: $pageToken,        // fresh page token, ready to post with
            expiresAt: $expiresAt,          // tied to the user token expiry
            refreshToken: $newUserToken,    // rotate the stored user token
        );
    }
}
