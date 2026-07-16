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
 * Instagram publishing runs on the Facebook Login path, and one Facebook user
 * can manage several Instagram Business accounts. The long-lived user token is a
 * single RENEWABLE credential (a SocialToken keyed on the Facebook user id) that
 * every Instagram account posts with; refreshing it once keeps them all alive.
 *
 * With withLinkedPages, each linked Facebook Page is also stored — as a STATIC
 * page-token credential (renew_at null), exactly like StoreFacebookPages.
 */
class StoreInstagramAccounts
{
    private const PAGE_FIELDS = 'id,name,access_token,instagram_business_account{id,username,profile_picture_url}';

    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * @return Collection<int, SocialAccount> Instagram accounts (plus linked pages).
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

        if (! $facebook instanceof FacebookConnector) {
            throw new RuntimeException('The "facebook" connector must be a FacebookConnector to seed Instagram accounts.');
        }

        $expiresAt = null;

        // 1. Long lived user token — the shared renewable credential.
        if ($extend) {
            $extended = $facebook->extendUserToken($userToken);

            if ($extended instanceof RenewalResult) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = $extended['token'];
            $expiresAt = $extended['expiresAt'];
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

        // Per-account granted scopes (Meta grants granularly). Best effort.
        $scopesByAccount = $facebook->grantedScopesByAccount($userToken, $accountIds);

        if ($scopesByAccount instanceof RenewalResult) {
            $scopesByAccount = [];
        }

        $managedIgIds = collect($pages)->pluck('instagram_business_account.id')->filter()->values()->all();

        // 4. The shared renewable Meta user credential (backs every IG account).
        // Only created when the user actually has a linked Instagram account, so a
        // page-only connection leaves no orphaned credential behind.
        $credential = $managedIgIds === []
            ? null
            : SocialToken::query()->updateOrCreate(
                ['provider' => 'facebook', 'provider_holder_id' => $userId],
                [
                    'access_token' => $userToken,
                    'refresh_token' => null,
                    'expires_at' => $expiresAt,
                    'renew_at' => $expiresAt?->copy()->sub($facebook->leadTime()),
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                ],
            );

        $accounts = collect();

        foreach ($pages as $page) {
            $ig = $page['instagram_business_account'] ?? null;

            if (! $ig || empty($ig['id']) || $credential === null) {
                continue; // Page without a linked Instagram account.
            }

            $accounts->push($this->persistAccount(
                ['provider' => 'instagram', 'provider_user_id' => $ig['id']],
                [
                    'social_token_id' => $credential->getKey(), // posts with the shared user token
                    'provider_holder_id' => $userId,
                    'name' => $ig['username'] ?? ($page['name'] ?? null),
                    'nickname' => $ig['username'] ?? null,
                    'avatar' => $ig['profile_picture_url'] ?? null,
                    'scopes' => $scopesByAccount[(string) $ig['id']] ?? [],
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                    'profile' => ['fb_page_id' => $page['id'] ?? null],
                ],
                $owner,
                $connectedBy,
            ));

            // Companion Facebook Page: a static page-token credential + account.
            if ($withLinkedPages && ! empty($page['id']) && ! empty($page['access_token'])) {
                $pageToken = SocialToken::query()->updateOrCreate(
                    ['provider' => 'facebook', 'provider_holder_id' => $page['id']],
                    [
                        'access_token' => $page['access_token'],
                        'refresh_token' => null,
                        'expires_at' => null,
                        'renew_at' => null, // static
                        'scopes' => $scopesByAccount[(string) $page['id']] ?? [],
                        'status' => AccountStatus::Active,
                        'last_error' => null,
                    ],
                );

                $accounts->push($this->persistAccount(
                    ['provider' => 'facebook', 'provider_user_id' => $page['id']],
                    [
                        'social_token_id' => $pageToken->getKey(),
                        'provider_holder_id' => $userId,
                        'name' => $page['name'] ?? null,
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
