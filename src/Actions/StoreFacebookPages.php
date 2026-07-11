<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use RuntimeException;

/**
 * Facebook publishing is per PAGE. The user token is used transiently to mint a
 * page token per page; each page token is stored as a STATIC credential (a
 * SocialToken with renew_at null — page tokens minted from a long-lived user
 * token do not expire and are not auto-refreshed). Each account posts with its
 * page token's credential.
 *
 * Call this from your OAuth callback for the "facebook" provider instead of
 * StoreAccountFromSocialite.
 */
class StoreFacebookPages
{
    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * @return Collection<int, SocialAccount> One row per managed page (may be empty).
     *
     * @throws RuntimeException when the token cannot be extended, the user id
     *                          cannot be resolved, or the pages cannot be listed.
     */
    public function handle(
        string $userToken,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        ?string $userId = null,
        bool $extend = true,
    ): Collection {
        $connector = $this->registry->for('facebook');

        if (! $connector instanceof FacebookConnector) {
            throw new RuntimeException('The "facebook" connector must be a FacebookConnector to seed pages.');
        }

        // 1. A long lived user token to mint page tokens from (used transiently).
        if ($extend) {
            $extended = $connector->extendUserToken($userToken);

            if ($extended instanceof RenewalResult) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = $extended['token'];
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

        // Per-account granted scopes (Meta grants granularly). Best effort.
        $pageIds = collect($pages)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        $scopesByAccount = $connector->grantedScopesByAccount($userToken, $pageIds);

        if ($scopesByAccount instanceof RenewalResult) {
            $scopesByAccount = [];
        }

        // 4. One static page-token credential + one account per page.
        $accounts = collect($pages)->map(function (array $page) use ($userId, $scopesByAccount, $owner, $connectedBy) {
            $scopes = $scopesByAccount[(string) ($page['id'] ?? '')] ?? [];

            $token = SocialToken::query()->updateOrCreate(
                ['provider' => 'facebook', 'provider_holder_id' => $page['id'] ?? null],
                [
                    'access_token' => $page['access_token'] ?? null, // page token, ready to post with
                    'refresh_token' => null,
                    'expires_at' => null,   // page tokens from a long lived user token do not expire
                    'renew_at' => null,     // static: never auto-refreshed
                    'scopes' => $scopes,
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                ],
            );

            return $this->persistAccount(
                ['provider' => 'facebook', 'provider_user_id' => $page['id'] ?? null],
                [
                    'social_token_id' => $token->getKey(),
                    'provider_holder_id' => $userId,     // the Facebook user behind this page
                    'name' => $page['name'] ?? null,
                    'avatar' => data_get($page, 'picture.data.url'),
                    'scopes' => $scopes,
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                ],
                $owner,
                $connectedBy,
            );
        });

        // 5. Reconcile: any still-active page account for THIS user that they no
        // longer manage is flagged. Scoped to the user id, so a co-owner's pages
        // are never touched.
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
        $account = SocialAccount::query()->updateOrCreate($keys, $attributes);

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
