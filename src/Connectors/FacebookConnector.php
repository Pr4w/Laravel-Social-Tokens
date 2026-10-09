<?php

namespace Pr4w\SocialTokens\Connectors;

use Illuminate\Support\Carbon;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\FansOutConnections;
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
 *
 * revoke() is deliberately left a no-op: DELETE /me/permissions would
 * deauthorise the app for the whole Facebook user, killing the static page
 * credentials of every other Page they connected.
 *
 * The exchange call, the Graph version and appsecret_proof signing are
 * shared with Instagram (MetaGraphConnector).
 */
class FacebookConnector extends MetaGraphConnector implements ChecksCredential, FansOutConnections
{
    /** Safety cap on /me/accounts result pages (100 Pages each). */
    protected const MAX_RESULT_PAGES = 20;

    /** One account per Page the user manages, each with its own Page token. */
    public function connectionAction(): string
    {
        return StoreFacebookPages::class;
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
     * exchangeUserToken() in the array shape 2.x returned. A failure is the
     * RenewalResult itself.
     *
     * @return array{token: string, expiresAt: ?Carbon}|RenewalResult
     */
    public function extendUserToken(#[\SensitiveParameter] string $userToken): array|RenewalResult
    {
        $result = $this->exchangeUserToken($userToken);

        if (! $result->succeeded()) {
            return $result;
        }

        return [
            'token' => (string) $result->accessToken,
            'expiresAt' => $result->expiresAt === null ? null : Carbon::instance($result->expiresAt),
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
    public function grantedScopesByAccount(#[\SensitiveParameter] string $userToken, array $accountIds): array|RenewalResult
    {
        $data = $this->debugToken($userToken);

        return $data instanceof RenewalResult ? $data : static::grantedScopesFrom($data, $accountIds);
    }

    /**
     * grantedScopesByAccount() from a debug_token reply already fetched, so a
     * connection reads the scopes and the token's lifetime from one call.
     *
     * @param  array<string, mixed>  $data  debugToken()'s result.
     * @param  array<int, string>  $accountIds
     * @return array<string, array<int, string>> [account_id => scopes]
     */
    public static function grantedScopesFrom(array $data, array $accountIds): array
    {
        $allScopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];
        $granular = is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [];

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
     * The Facebook user id behind a token (the credential holder). Used to key a
     * user's page rows so a reconnect can reconcile the ones they no longer manage.
     */
    public function fetchUserId(#[\SensitiveParameter] string $userToken): string|RenewalResult
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
    public function fetchPages(#[\SensitiveParameter] string $userToken, string $fields = 'id,name,access_token,picture{url}'): array|RenewalResult
    {
        $url = $this->graphUrl('me/accounts');
        $query = ['fields' => $fields, 'limit' => 100] + $this->appSecretProofQuery($userToken);
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

            // paging.next is a complete URL with the first call's parameters;
            // the proof is added if Meta left it out.
            $url = $this->withAppSecretProof($next, $userToken);
            $query = null;
        }

        return RenewalResult::unknownFailure('Too many Facebook Pages to list; refusing to return a partial list.', [
            'result_pages' => self::MAX_RESULT_PAGES,
            'pages_so_far' => count($pages),
        ]);
    }
}
