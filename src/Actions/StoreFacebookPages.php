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
 * Facebook publishing is per PAGE. Each page token is stored as a STATIC
 * credential (a SocialToken with renew_at null — page tokens minted from a
 * long-lived user token do not expire and are not auto-refreshed), and each
 * account posts with its page token's credential.
 *
 * The long-lived user token the pages were minted from is kept too, as the
 * RENEWABLE Meta user credential (provider facebook, holder = Facebook user id)
 * — the same row StoreInstagramAccounts uses, so a Facebook reconnect also
 * refreshes the token that user's Instagram accounts post with.
 *
 * Call this from your OAuth callback for the "facebook" provider instead of
 * StoreAccountFromSocialite.
 */
class StoreFacebookPages implements StoresConnections
{
    use KeepsDisconnectedAccountsOut;
    use PersistsMetaUserCredential;

    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * $extend false: $userToken is already long-lived, a token the app holds
     * (a re-sync), so no fb_exchange_token call, and the stored data access
     * deadline is kept when debug_token does not give the new one.
     *
     * A Page the user disconnected (SocialTokens::disconnect()) is left out,
     * revoked, unless $restoreDisconnected.
     *
     * @return Collection<int, SocialAccount> One row per managed page (may be empty).
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
        bool $restoreDisconnected = false,
    ): Collection {
        return $this->store($userToken, $owner, $connectedBy, $userId, $extend, newLogin: $extend, restoreDisconnected: $restoreDisconnected);
    }

    /**
     * StoreConnection's entry point, for a login that just happened: with
     * $longLived false the token is stored as given (no fb_exchange_token
     * call), and it is a new login all the same. Disconnected targets are
     * left out unless $restoreDisconnected.
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
        return $this->store($user->token, $owner, $connectedBy, $user->getId(), extend: $longLived, newLogin: true, restoreDisconnected: $restoreDisconnected);
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
        bool $newLogin,
        bool $restoreDisconnected,
    ): Collection {
        $connector = $this->registry->for('facebook');

        if (! $connector instanceof FacebookConnector) {
            throw new RuntimeException('The "facebook" connector must be a FacebookConnector to seed pages.');
        }

        // 1. A long lived user token to mint page tokens from.
        $expiresAt = null;

        if ($extend) {
            $extended = $connector->exchangeUserToken($userToken);

            if (! $extended->succeeded()) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = (string) $extended->accessToken;
            $expiresAt = $extended->expiresAt;
        }

        // 2. Resolve the user id (recorded on each account for reconciliation).
        if ($userId === null) {
            $resolved = $connector->fetchUserId($userToken);

            if ($resolved instanceof RenewalResult) {
                throw new RuntimeException('Could not resolve the Facebook user id: '.$resolved->reason);
            }

            $userId = $resolved;
        }

        // 3. List every page this user manages, each with its own page token.
        $pages = $connector->fetchPages($userToken);

        if ($pages instanceof RenewalResult) {
            throw new RuntimeException('Could not list Facebook pages: '.$pages->reason);
        }

        // Per-account granted scopes (Meta grants granularly) and the user
        // token's lifetime, from one debug_token call. Best effort: unknown
        // (the call failed) keeps whatever scopes are stored.
        $pageIds = collect($pages)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        $debug = $this->debugUserToken($connector, $userToken);
        $scopesByAccount = $debug === null ? null : $connector::grantedScopesFrom($debug, $pageIds);

        // Pages the user disconnected stay out of this reconnect.
        $skipped = $restoreDisconnected ? [] : $this->disconnectedAmong('facebook', $pageIds, $owner);
        $userKeys = ['provider' => 'facebook', 'provider_holder_id' => $userId] + SocialAccount::ownerKey($owner);

        // 4a. The Meta user credential (shared with Instagram). Only when the
        // user manages a Page this connection keeps, or the stored one still
        // backs other accounts, so no credential is left orphaned.
        if ($pages !== [] && ($skipped === [] || array_diff($pageIds, $skipped) !== [] || $this->credentialStillLive($userKeys))) {
            $this->persistUserCredential(
                $connector,
                $userKeys,
                $userToken,
                $expiresAt,
                $debug,
                $newLogin,
            );
        }

        // 4. One static page-token credential + one account per page that can
        // post: a Page listed without an access_token (no posting task for this
        // user) is not stored. It still counts as managed below. A loop, not a
        // collection callback: the Pages and their tokens would be arguments of
        // the collection's frames in the trace of a failed write.
        $accounts = collect();

        foreach ($pages as $page) {
            if (empty($page['id']) || empty($page['access_token']) || in_array((string) $page['id'], $skipped, true)) {
                continue;
            }

            $withScopes = $scopesByAccount === null ? [] : ['scopes' => $scopesByAccount[(string) $page['id']] ?? []];

            $token = SocialToken::persistCredential(
                ['provider' => 'facebook', 'provider_holder_id' => $page['id']] + SocialAccount::ownerKey($owner),
                [
                    'access_token' => $page['access_token'], // page token, ready to post with
                    'refresh_token' => null,
                    'expires_at' => null,   // page tokens from a long lived user token do not expire
                    'renew_at' => null,     // static: never auto-refreshed
                ] + $withScopes,
            );

            $accounts->push($this->persistAccount(
                ['provider' => 'facebook', 'provider_user_id' => $page['id']] + SocialAccount::ownerKey($owner),
                [
                    'social_token_id' => $token->getKey(),
                    'provider_holder_id' => $userId,     // the Facebook user behind this page
                    'name' => $page['name'] ?? null,
                    'avatar' => data_get($page, 'picture.data.url'),
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                ] + $withScopes,
                $owner,
                $connectedBy,
            ));
        }

        // 5. Reconcile: any still-active page account for THIS user that they no
        // longer manage is flagged. Scoped to the Facebook user, not the owner:
        // another user's Pages are never touched, and this user's are flagged
        // under every owner, since Meta grants access per user.
        $managedIds = collect($pages)->pluck('id')->filter()->values()->all();

        SocialAccount::query()
            ->where('provider', 'facebook')
            ->where('provider_holder_id', $userId)
            ->where('status', AccountStatus::Active->value)
            ->when($managedIds !== [], fn ($query) => $query->whereNotIn('provider_user_id', $managedIds))
            ->get()
            ->each(fn (SocialAccount $account) => $account->markNeedsReconnect(
                'Page no longer managed by the connected Facebook user.'
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
