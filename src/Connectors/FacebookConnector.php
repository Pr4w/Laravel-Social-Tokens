<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Facebook Page publishing via the Pages API.
 *
 * Token model (Facebook Login path, shared with Instagram):
 *  - the only credential that expires is the long lived USER token (~60 days)
 *  - a Page access token is derived from it via /me/accounts and, once minted
 *    from a long lived user token, does not expire
 *
 * So the renewable credential is the USER token. refreshCredential extends it in
 * place (fb_exchange_token) — a single token, no refresh-token rotation, hence
 * ExtendLongLived. Page tokens are stored as static credentials and not
 * re-derived here (see StoreFacebookPages / StoreInstagramAccounts).
 */
class FacebookConnector extends AbstractConnector implements ChecksCredential
{
    /** Safety cap on /me/accounts result pages (100 Pages each). */
    protected const MAX_RESULT_PAGES = 20;

    public function renewalStrategy(): RenewalStrategy
    {
        return RenewalStrategy::ExtendLongLived;
    }

    public function leadTime(): CarbonInterval
    {
        return CarbonInterval::days(7);
    }

    /**
     * Refresh the Meta user credential: extend the stored user token in place.
     * Single-phase — Facebook page tokens are static and are not re-derived here.
     */
    public function refreshCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing user token.');
        }

        $extended = $this->extendUserToken($token->access_token);

        if ($extended instanceof RenewalResult) {
            return $extended;
        }

        return RenewalResult::success(
            accessToken: $extended['token'],
            expiresAt: $extended['expiresAt'],
        );
    }

    /**
     * Page tokens never expire, but die when the user changes their password,
     * removes the app or loses the Page's admin role. A cheap /me call tells.
     */
    public function checkCredential(SocialToken $token): RenewalResult
    {
        if (empty($token->access_token)) {
            return RenewalResult::terminalFailure('Missing page token.', ['definitive' => true]);
        }

        $response = $this->fetchMe($token->access_token);

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // The health check's own mapping: only a session subcode is definitive.
        if (! empty($body['error'])) {
            return MetaErrorMapper::mapCredentialCheck($body['error']);
        }

        return RenewalResult::success(accessToken: $token->access_token);
    }

    /**
     * Exchange a user token for a fresh long-lived one via the fb_exchange_token
     * grant. Safe to call on a token that is already long lived, but whether
     * Meta then pushes the expiry further is not documented: renewals log the
     * expires_in they get, and a non-extending one is handled by applyRenewal().
     * Shared by renewal and the connect-time actions.
     *
     * @return array{token: string, expiresAt: ?Carbon}|RenewalResult
     */
    public function extendUserToken(string $userToken): array|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => $this->http()->acceptJson()->get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $userToken,
        ]));

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : []; // a scalar JSON body is not an error object

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $token = $body['access_token'] ?? null;

        if ($token === null) {
            return RenewalResult::unknownFailure('Malformed token extension response.', [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
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
     * @return array<string, array<int, string>>|RenewalResult [account_id => scopes]
     */
    public function grantedScopesByAccount(string $userToken, array $accountIds): array|RenewalResult
    {
        $appToken = $this->appAccessToken();

        if ($appToken instanceof RenewalResult) {
            return $appToken;
        }

        $version = $this->config['graph_version'] ?? 'v23.0';

        $response = $this->attempt(fn () => $this->http()->acceptJson()->get("https://graph.facebook.com/{$version}/debug_token", [
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
     */
    public function fetchUserId(string $userToken): string|RenewalResult
    {
        $response = $this->fetchMe($userToken);

        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : []; // a scalar JSON body is not an error object

        if (! empty($body['error'])) {
            return MetaErrorMapper::map($body['error']);
        }

        $id = $body['id'] ?? null;

        if ($id === null) {
            return RenewalResult::unknownFailure('Malformed /me response, no id.', [
                'status' => $response->status(),
                'keys' => array_keys($body), // never the body: it may carry tokens
            ]);
        }

        return (string) $id;
    }

    /**
     * GET /me?fields=id with the given token, transport outcomes normalised.
     */
    protected function fetchMe(string $token): Response|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';

        return $this->attempt(fn () => $this->http()->withToken($token)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/me", ['fields' => 'id']));
    }

    /**
     * List the Pages a user token can manage, each carrying its own page access
     * token. Shared by the page-seeding action (all pages) and the Instagram
     * action (which requests the linked IG account field via $fields).
     *
     * Follows paging.next to the end: the actions reconcile against this list
     * and flag every page missing from it, so a truncated list would flag pages
     * the user still manages. Any failure returns a RenewalResult rather than a
     * partial list: a Graph error, a non-2xx, a 2xx without a `data` list (an
     * HTML page from a proxy, `{}`, a captive portal), or the safety cap.
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    public function fetchPages(string $userToken, string $fields = 'id,name,access_token,picture{url}'): array|RenewalResult
    {
        $version = $this->config['graph_version'] ?? 'v23.0';
        $url = "https://graph.facebook.com/{$version}/me/accounts";
        $query = ['fields' => $fields, 'limit' => 100];
        $pages = [];

        for ($resultPage = 0; $resultPage < self::MAX_RESULT_PAGES; $resultPage++) {
            // Without a query argument: even an empty one replaces the URL's own
            // query string, which would drop paging.next's cursor.
            $response = $this->attempt(fn () => $query === null
                ? $this->http()->withToken($userToken)->acceptJson()->get($url)
                : $this->http()->withToken($userToken)->acceptJson()->get($url, $query));

            if ($response instanceof RenewalResult) {
                return $response;
            }

            $body = $response->json();
            $body = is_array($body) ? $body : [];

            if (! empty($body['error'])) {
                return MetaErrorMapper::map($body['error']);
            }

            // Anything but a 2xx carrying a list is not "the end of the list".
            // No body in the context: a partial one may hold page tokens.
            $data = $body['data'] ?? null;

            if (! $response->successful() || ! is_array($data) || ! array_is_list($data)) {
                return RenewalResult::unknownFailure('Could not list Facebook pages (HTTP '.$response->status().', no data list).', [
                    'status' => $response->status(),
                    'result_page' => $resultPage + 1,
                ]);
            }

            $pages = array_merge($pages, $data);
            $next = $body['paging']['next'] ?? null;

            if ($next === null) {
                return $pages;
            }

            // paging.next is a complete URL that already carries every parameter.
            $url = $next;
            $query = null;
        }

        return RenewalResult::unknownFailure('Too many Facebook Pages to list; refusing to return a partial list.', [
            'result_pages' => self::MAX_RESULT_PAGES,
            'pages_so_far' => count($pages),
        ]);
    }
}
