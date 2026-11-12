<?php

namespace Pr4w\SocialTokens\Connectors;

use Illuminate\Support\Carbon;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Contracts\FansOutConnections;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
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

    /** How long a user's Page list, read to resolve a 100/33, serves the user's other Pages. */
    protected const PAGE_LIST_SECONDS = 300;

    /**
     * Page lists read to resolve a 100/33, by user credential and its stored
     * token, so a user with several such Pages is asked once per check-static
     * run. In memory only: they hold Page tokens.
     *
     * @var array<string, array{at: int, pages: array<int, array<string, mixed>>}>
     */
    private array $pageLists = [];

    /** One account per Page the user manages, each with its own Page token. */
    public function connectionAction(): string
    {
        return StoreFacebookPages::class;
    }

    /**
     * Page tokens never expire, but die when the user changes their password,
     * removes the app or loses the Page's admin role. A cheap /me call tells.
     * A 100/33 on a Page token is resolved with the Page's user token
     * (resolveUnreachablePage()), which can return a fresh Page token.
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
            $result = MetaErrorMapper::mapCredentialCheck($body['error']);

            return MetaErrorMapper::isUnreachableObject($body['error'])
                ? $this->resolveUnreachablePage($token, $result)
                : $result;
        }

        return RenewalResult::success(accessToken: $token->access_token);
    }

    /**
     * Meta answered 100/33 to a Page token's /me, which happens both to a
     * stale Page token and to a Page Meta keeps out of the API. The Page's
     * user token tells which:
     *  - the Page is no longer among the Pages the user manages: terminal;
     *  - a fresh Page token from /me/accounts reads the Page: success, with
     *    that token, which the caller stores in place of the stale one;
     *  - the fresh Page token gets 100/33 too: terminal, Facebook refuses API
     *    access to the Page, and a reconnect cannot change that.
     * Neither verdict is definitive: two consecutive runs flag, and the
     * per-provider breaker still stops them if every Page fails at once.
     * Without a usable user token, or an answer to read, it stays
     * $unresolved: nothing is decided on missing information.
     */
    protected function resolveUnreachablePage(SocialToken $page, RenewalResult $unresolved): RenewalResult
    {
        $user = $this->pageUserCredential($page);

        if ($user === null) {
            return $unresolved;
        }

        $pages = $this->pageListOf($user);

        if ($pages instanceof RenewalResult) {
            return $unresolved;
        }

        $pageId = (string) $page->provider_holder_id;
        // data_get(): an entry Meta sent as something else than an object is
        // skipped, not a TypeError.
        $listed = collect($pages)->first(fn ($candidate) => (string) data_get($candidate, 'id', '') === $pageId);

        if ($listed === null) {
            return RenewalResult::terminalFailure('The Page is no longer among the Pages its user manages (Meta 100/33).', $unresolved->context);
        }

        $fresh = data_get($listed, 'access_token');

        if (! is_string($fresh) || $fresh === '') {
            return $unresolved;
        }

        $response = $this->attempt(fn () => $this->http()->withToken($fresh)
            ->acceptJson()
            ->get($this->graphUrl($pageId), ['fields' => 'id'] + $this->appSecretProofQuery($fresh)));

        if ($response instanceof RenewalResult) {
            return $unresolved;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && empty($body['error']) && (string) ($body['id'] ?? '') === $pageId) {
            return RenewalResult::success(accessToken: $fresh);
        }

        if (MetaErrorMapper::isUnreachableObject($body['error'] ?? null)) {
            return RenewalResult::terminalFailure(
                'Facebook refuses API access to this Page, even with a fresh Page token: it may be restricted by country or age, or limited by Facebook (Meta 100/33).',
                $unresolved->context,
            );
        }

        return $unresolved;
    }

    /**
     * The Meta user token a Page credential was minted from: the user behind
     * the Page's account (its provider_holder_id; the Page credential's own
     * is the Page id), their default facebook credential, same owner. Null
     * when the credential is no Page's (the user token behind Instagram
     * accounts), or the user's is missing, flagged or unreadable.
     */
    protected function pageUserCredential(SocialToken $page): ?SocialToken
    {
        $account = SocialAccount::query()
            ->where('social_token_id', $page->getKey())
            ->where('provider', 'facebook')
            ->where('provider_user_id', $page->provider_holder_id)
            ->whereNotNull('provider_holder_id')
            ->first();

        if ($account === null) {
            return null;
        }

        $user = SocialToken::query()
            ->where('provider', $page->provider)
            ->where('provider_holder_id', $account->provider_holder_id)
            ->forPurpose()
            ->where('ownable_type', $page->ownable_type)
            ->where('ownable_id', $page->ownable_id)
            ->where('status', AccountStatus::Active->value)
            ->first();

        return $user !== null && $user->tokensDecrypt() && filled($user->access_token) ? $user : null;
    }

    /**
     * The user's Pages with their tokens (fetchPages()), kept a few minutes
     * per user credential and stored token.
     *
     * @return array<int, array<string, mixed>>|RenewalResult
     */
    protected function pageListOf(SocialToken $user): array|RenewalResult
    {
        $key = $user->getKey().':'.hash('sha256', (string) $user->getRawOriginal('access_token'));
        $cached = $this->pageLists[$key] ?? null;

        if ($cached !== null && time() - $cached['at'] < self::PAGE_LIST_SECONDS) {
            return $cached['pages'];
        }

        $pages = $this->fetchPages((string) $user->access_token, 'id,access_token');

        if (! $pages instanceof RenewalResult) {
            $this->pageLists[$key] = ['at' => time(), 'pages' => $pages];
        }

        return $pages;
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
