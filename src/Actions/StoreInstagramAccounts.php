<?php

namespace Pr4w\SocialTokens\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Actions\Concerns\KeepsDisconnectedAccountsOut;
use Pr4w\SocialTokens\Actions\Concerns\PersistsMetaUserCredential;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Contracts\StoresConnections;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use RuntimeException;

/**
 * Instagram publishing runs on the Facebook Login path, and one Facebook user
 * can manage several Instagram Business accounts. The long-lived user token is a
 * single RENEWABLE credential (a SocialToken keyed on the Facebook user id) that
 * every Instagram account posts with; refreshing it once keeps them all alive.
 *
 * With withLinkedPages, each linked Facebook Page is also stored — as a STATIC
 * page-token credential (renew_at null), exactly like StoreFacebookPages.
 */
class StoreInstagramAccounts implements StoresConnections
{
    use KeepsDisconnectedAccountsOut;
    use PersistsMetaUserCredential;

    private const PAGE_FIELDS = 'id,name,access_token,instagram_business_account{id,username,profile_picture_url}';

    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * $extend false: $userToken is already long-lived, a token the app holds
     * (a re-sync), so no fb_exchange_token call, and the stored data access
     * deadline is kept when debug_token does not give the new one.
     *
     * An Instagram account or a Page the user disconnected
     * (SocialTokens::disconnect()) is left out, revoked, unless
     * $restoreDisconnected.
     *
     * @return Collection<int, SocialAccount> Instagram accounts (plus linked pages).
     *
     * @throws RuntimeException when the token cannot be extended, the user id
     *                          cannot be resolved, or the pages cannot be listed.
     */
    public function handle(
        #[\SensitiveParameter] string $userToken,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        ?string $userId = null,
        bool $extend = true,
        bool $withLinkedPages = true,
        bool $restoreDisconnected = false,
    ): Collection {
        return $this->store($userToken, $owner, $connectedBy, $userId, $extend, $withLinkedPages, newLogin: $extend, restoreDisconnected: $restoreDisconnected);
    }

    /**
     * StoreConnection's entry point, for a login that just happened: with
     * $longLived false the token is stored as given (no fb_exchange_token
     * call), and it is a new login all the same. Disconnected targets are
     * left out unless $restoreDisconnected. The linked Pages are stored
     * too.
     *
     * @return Collection<int, SocialAccount>
     */
    public function storeConnection(
        string $provider,
        #[\SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
        bool $restoreDisconnected = false,
    ): Collection {
        return $this->store($user->token, $owner, $connectedBy, $user->getId(), extend: $longLived, withLinkedPages: true, newLogin: true, restoreDisconnected: $restoreDisconnected);
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    protected function store(
        #[\SensitiveParameter] string $userToken,
        ?Model $owner,
        ?Model $connectedBy,
        ?string $userId,
        bool $extend,
        bool $withLinkedPages,
        bool $newLogin,
        bool $restoreDisconnected,
    ): Collection {
        $facebook = $this->registry->for('facebook');

        if (! $facebook instanceof FacebookConnector) {
            throw new RuntimeException('The "facebook" connector must be a FacebookConnector to seed Instagram accounts.');
        }

        $expiresAt = null;

        // 1. Long lived user token — the shared renewable credential.
        if ($extend) {
            $extended = $facebook->exchangeUserToken($userToken);

            if (! $extended->succeeded()) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = (string) $extended->accessToken;
            $expiresAt = $extended->expiresAt;
        }

        // 2. Resolve the user id (the credential holder).
        if ($userId === null) {
            $resolved = $facebook->fetchUserId($userToken);

            if ($resolved instanceof RenewalResult) {
                throw new RuntimeException('Could not resolve the Facebook user id: '.$resolved->reason);
            }

            $userId = $resolved;
        }

        // 3. Pages with their linked Instagram Business accounts.
        $pages = $facebook->fetchPages($userToken, self::PAGE_FIELDS);

        if ($pages instanceof RenewalResult) {
            throw new RuntimeException('Could not list Facebook pages: '.$pages->reason);
        }

        $accountIds = [];

        foreach ($pages as $page) {
            if (! empty($page['id'])) {
                $accountIds[] = (string) $page['id'];
            }

            if ($igId = data_get($page, 'instagram_business_account.id')) {
                $accountIds[] = (string) $igId;
            }
        }

        // Per-account granted scopes (Meta grants granularly) and the user
        // token's lifetime, from one debug_token call. Best effort: unknown
        // (the call failed) keeps whatever scopes are stored.
        $debug = $this->debugUserToken($facebook, $userToken);
        $scopesByAccount = $debug === null ? null : $facebook::grantedScopesFrom($debug, $accountIds);

        $scopesFor = fn (string $id): array => $scopesByAccount === null ? [] : ['scopes' => $scopesByAccount[$id] ?? []];

        $managedIgIds = collect($pages)->pluck('instagram_business_account.id')->filter()->values()->all();

        // Instagram accounts and Pages the user disconnected stay out of this
        // reconnect.
        $igIds = array_map(strval(...), $managedIgIds);
        $skippedIg = $restoreDisconnected ? [] : $this->disconnectedAmong('instagram', $igIds, $owner);
        $skippedPages = $restoreDisconnected || ! $withLinkedPages ? [] : $this->disconnectedAmong('facebook', $accountIds, $owner);
        $userKeys = ['provider' => 'facebook', 'provider_holder_id' => $userId] + SocialAccount::ownerKey($owner);

        // 4. The shared renewable Meta user credential (backs every IG account).
        // Only created when the user actually has a linked Instagram account this
        // connection keeps (or the stored one still backs other accounts), so a
        // page-only connection leaves no orphaned credential behind.
        $keepsCredential = $igIds !== []
            && ($skippedIg === [] || array_diff($igIds, $skippedIg) !== [] || $this->credentialStillLive($userKeys));

        $credential = ! $keepsCredential
            ? null
            : $this->persistUserCredential(
                $facebook,
                $userKeys,
                $userToken,
                $expiresAt,
                $debug,
                $newLogin,
            );

        $accounts = collect();

        foreach ($pages as $page) {
            $ig = $page['instagram_business_account'] ?? null;

            if (! $ig || empty($ig['id']) || $credential === null) {
                continue; // Page without a linked Instagram account.
            }

            if (! in_array((string) $ig['id'], $skippedIg, true)) {
                $accounts->push($this->persistAccount(
                    ['provider' => 'instagram', 'provider_user_id' => $ig['id']] + SocialAccount::ownerKey($owner),
                    [
                        'social_token_id' => $credential->getKey(), // posts with the shared user token
                        'provider_holder_id' => $userId,
                        'name' => $ig['username'] ?? ($page['name'] ?? null),
                        'nickname' => $ig['username'] ?? null,
                        'avatar' => $ig['profile_picture_url'] ?? null,
                        'status' => AccountStatus::Active,
                        'last_error' => null,
                        'profile' => ['fb_page_id' => $page['id'] ?? null],
                    ] + $scopesFor((string) $ig['id']),
                    $owner,
                    $connectedBy,
                ));
            }

            // Companion Facebook Page: a static page-token credential + account.
            if ($withLinkedPages && ! empty($page['id']) && ! empty($page['access_token']) && ! in_array((string) $page['id'], $skippedPages, true)) {
                $pageToken = SocialToken::persistCredential(
                    ['provider' => 'facebook', 'provider_holder_id' => $page['id']] + SocialAccount::ownerKey($owner),
                    [
                        'access_token' => $page['access_token'],
                        'refresh_token' => null,
                        'expires_at' => null,
                        'renew_at' => null, // static
                    ] + $scopesFor((string) $page['id']),
                );

                $accounts->push($this->persistAccount(
                    ['provider' => 'facebook', 'provider_user_id' => $page['id']] + SocialAccount::ownerKey($owner),
                    [
                        'social_token_id' => $pageToken->getKey(),
                        'provider_holder_id' => $userId,
                        'name' => $page['name'] ?? null,
                        'status' => AccountStatus::Active,
                        'last_error' => null,
                        'profile' => ['fb_page_id' => $page['id'], 'ig_account_id' => $ig['id']],
                    ] + $scopesFor((string) $page['id']),
                    $owner,
                    $connectedBy,
                ));
            }
        }

        // 5. Reconcile Instagram accounts this user no longer manages.
        SocialAccount::query()
            ->where('provider', 'instagram')
            ->where('provider_holder_id', $userId)
            ->where('status', AccountStatus::Active->value)
            ->when($managedIgIds !== [], fn ($query) => $query->whereNotIn('provider_user_id', $managedIgIds))
            ->get()
            ->each(fn (SocialAccount $account) => $account->markNeedsReconnect(
                'Instagram account no longer managed by the connected Facebook user.'
            ));

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $attributes
     */
    private function persistAccount(array $keys, array $attributes, ?Model $owner, ?Model $connectedBy): SocialAccount
    {
        // profile is merged, not replaced: keys the app stored itself survive.
        $profile = $attributes['profile'] ?? null;
        unset($attributes['profile']);

        $account = SocialAccount::query()->firstOrNew($keys)->fill($attributes)->clearDisconnected();

        if (is_array($profile)) {
            $account->mergeProfile($profile);
        }

        if ($owner) {
            $account->ownable()->associate($owner);
        }

        if ($connectedBy) {
            $account->connectedBy()->associate($connectedBy);
        }

        if ($account->isDirty()) {
            $account->save();
        }

        event(new AccountConnected($account));

        return $account;
    }
}
