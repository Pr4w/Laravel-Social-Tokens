<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use RuntimeException;

/**
 * Instagram publishing runs on the Facebook Login path, and one Facebook user
 * can manage several Instagram Business accounts — one per linked Page. This
 * action bridges that: given a user token (as returned by Socialite's facebook
 * driver), it extends it to a long lived one, enumerates the Pages and their
 * linked Instagram accounts, and writes one row per Instagram account.
 *
 * Each Instagram row stores the long lived user token in access_token (the
 * ExtendLongLived credential the connector re-extends in place). With
 * withLinkedPages, the linked Facebook Page is stored too — page token in
 * access_token, user token in refresh_token — so you can post to both. Every
 * row records the Facebook user id in provider_holder_id, so a reconnect can
 * flag Instagram accounts the user no longer manages.
 */
class StoreInstagramAccounts
{
    private const PAGE_FIELDS = 'id,name,access_token,instagram_business_account{id,username,profile_picture_url}';

    public function __construct(protected ConnectorRegistry $registry)
    {
    }

    /**
     * @param  string  $userToken  The user access token from the OAuth callback.
     * @param  Model|null  $owner  App-side entity that owns these connections.
     * @param  Model|null  $connectedBy  Who performed the connection (optional).
     * @param  string|null  $userId  The Facebook user id. Pass Socialite's
     *                               $user->getId() to skip an extra /me call.
     * @param  bool  $extend  Exchange the token for a long lived one first.
     * @param  bool  $withLinkedPages  Also store each linked Facebook Page row.
     * @return Collection<int, SocialAccount>  One row per Instagram account (plus
     *                                         linked pages when withLinkedPages).
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
        bool $withLinkedPages = true,
    ): Collection {
        $facebook = $this->registry->for('facebook');
        $instagram = $this->registry->for('instagram');

        if (! $facebook instanceof FacebookConnector) {
            throw new RuntimeException('The "facebook" connector must be a FacebookConnector to seed Instagram accounts.');
        }

        $expiresAt = null;

        // 1. Long lived user token (the renewable root shared by every row).
        if ($extend) {
            $extended = $facebook->extendUserToken($userToken);

            if ($extended instanceof RenewalResult) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = $extended['token'];
            $expiresAt = $extended['expiresAt'];
        }

        // 2. Resolve the user id (credential holder) if not provided.
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

        $igRenewAt = $expiresAt?->copy()->sub($instagram->leadTime());
        $fbRenewAt = $expiresAt?->copy()->sub($facebook->leadTime());

        // Per-account granted scopes (Meta grants granularly). Best effort: scope
        // metadata must never block the connection.
        $accountIds = [];

        foreach ($pages as $page) {
            if (! empty($page['id'])) {
                $accountIds[] = (string) $page['id'];
            }

            if ($igId = data_get($page, 'instagram_business_account.id')) {
                $accountIds[] = (string) $igId;
            }
        }

        $scopesByAccount = $facebook->grantedScopesByAccount($userToken, $accountIds);

        if ($scopesByAccount instanceof RenewalResult) {
            $scopesByAccount = [];
        }

        $accounts = collect();

        foreach ($pages as $page) {
            $ig = $page['instagram_business_account'] ?? null;

            if (! $ig || empty($ig['id'])) {
                continue; // Page without a linked Instagram account.
            }

            $accounts->push($this->persist(
                ['provider' => 'instagram', 'provider_user_id' => $ig['id']],
                [
                    'provider_holder_id' => $userId,
                    'name' => $ig['username'] ?? ($page['name'] ?? null),
                    'nickname' => $ig['username'] ?? null,
                    'avatar' => $ig['profile_picture_url'] ?? null,
                    'access_token' => $userToken,   // long lived user token (ExtendLongLived)
                    'refresh_token' => null,
                    'expires_at' => $expiresAt,
                    'refresh_expires_at' => null,
                    'renew_at' => $igRenewAt,
                    'scopes' => $scopesByAccount[(string) $ig['id']] ?? [],
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                    'profile' => ['fb_page_id' => $page['id'] ?? null],
                ],
                $owner,
                $connectedBy,
            ));

            // Companion Facebook Page row for the linked page.
            if ($withLinkedPages && ! empty($page['id']) && ! empty($page['access_token'])) {
                $accounts->push($this->persist(
                    ['provider' => 'facebook', 'provider_user_id' => $page['id']],
                    [
                        'provider_holder_id' => $userId,
                        'name' => $page['name'] ?? null,
                        'access_token' => $page['access_token'], // page token, ready to post with
                        'refresh_token' => $userToken,           // shared user token to re-derive it
                        'expires_at' => $expiresAt,
                        'refresh_expires_at' => null,
                        'renew_at' => $fbRenewAt,
                        'scopes' => $scopesByAccount[(string) $page['id']] ?? [],
                        'status' => AccountStatus::Active,
                        'last_error' => null,
                        'profile' => ['fb_page_id' => $page['id'], 'ig_account_id' => $ig['id']],
                    ],
                    $owner,
                    $connectedBy,
                ));
            }
        }

        // 4. Reconcile: Instagram accounts we hold for THIS user that they no
        // longer manage (absent from the response) are flagged for reconnection.
        // Scoped to provider_holder_id so a co-owner's accounts are never touched.
        $managedIgIds = collect($pages)->pluck('instagram_business_account.id')->filter()->values()->all();

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

    private function persist(array $keys, array $attributes, ?Model $owner, ?Model $connectedBy): SocialAccount
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
