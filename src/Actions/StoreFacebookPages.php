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

        // 1. A long lived user token to mint page tokens from.
        $expiresAt = null;

        if ($extend) {
            $extended = $connector->extendUserToken($userToken);

            if ($extended instanceof RenewalResult) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = $extended['token'];
            $expiresAt = $extended['expiresAt'];
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

        // Unknown (debug_token failed): keep whatever scopes are stored.
        if ($scopesByAccount instanceof RenewalResult) {
            $scopesByAccount = null;
        }

        // 4a. The renewable Meta user credential (shared with Instagram). Only
        // when the user manages a Page, so no credential is left orphaned.
        if ($pages !== []) {
            SocialToken::query()->updateOrCreate(
                ['provider' => 'facebook', 'provider_holder_id' => $userId],
                [
                    'access_token' => $userToken,
                    'refresh_token' => null,
                    'expires_at' => $expiresAt,
                    'renew_at' => SocialToken::renewAtFor($expiresAt, $connector),
                    'status' => AccountStatus::Active,
                    'last_renewed_at' => now(), // the token was just issued
                    'last_error' => null,
                    'failed_checks' => 0,
                ],
            );
        }

        // 4. One static page-token credential + one account per page that can
        // post: a Page listed without an access_token (no posting task for this
        // user) is not stored. It still counts as managed below.
        $postable = collect($pages)->filter(fn (array $page) => ! empty($page['id']) && ! empty($page['access_token']));

        $accounts = $postable->values()->map(function (array $page) use ($userId, $scopesByAccount, $owner, $connectedBy) {
            $withScopes = $scopesByAccount === null ? [] : ['scopes' => $scopesByAccount[(string) $page['id']] ?? []];

            $token = SocialToken::query()->updateOrCreate(
                ['provider' => 'facebook', 'provider_holder_id' => $page['id']],
                [
                    'access_token' => $page['access_token'], // page token, ready to post with
                    'refresh_token' => null,
                    'expires_at' => null,   // page tokens from a long lived user token do not expire
                    'renew_at' => null,     // static: never auto-refreshed
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                    'failed_checks' => 0,
                ] + $withScopes,
            );

            return $this->persistAccount(
                ['provider' => 'facebook', 'provider_user_id' => $page['id']],
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
        // profile is merged, not replaced: keys the app stored itself survive.
        $profile = $attributes['profile'] ?? null;
        unset($attributes['profile']);

        $account = SocialAccount::query()->firstOrNew($keys)->fill($attributes);

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
