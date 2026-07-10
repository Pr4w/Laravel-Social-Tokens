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
 * Facebook publishing is per PAGE, but the only renewable credential is the
 * long lived USER token. This action bridges the two: given a user token (as
 * returned by Socialite), it extends it to a long lived one, lists every Page
 * the user manages, and writes one SocialAccount row per page.
 *
 * Each row stores the PAGE token in access_token (ready to post with) and the
 * shared USER token in refresh_token, with expires_at tracking the USER token
 * so the scheduler renews it before it lapses. See FacebookConnector::renew().
 *
 * Call this from your OAuth callback controller for the "facebook" provider
 * instead of StoreAccountFromSocialite, which stores a single row and cannot
 * split the user token from the page tokens.
 */
class StoreFacebookPages
{
    public function __construct(protected ConnectorRegistry $registry)
    {
    }

    /**
     * @param  string  $userToken  The user access token from the OAuth callback.
     * @param  Model|null  $owner  App-side entity that owns these connections.
     * @param  Model|null  $connectedBy  Who performed the connection (optional).
     * @param  string|null  $userId  The Facebook user id. Pass Socialite's
     *                               $user->getId() to skip an extra /me call;
     *                               resolved automatically when null.
     * @param  bool  $extend  Exchange the token for a long lived one first. Leave
     *                        true unless you already hold a long lived user token.
     * @return Collection<int, SocialAccount>  One row per managed page (may be empty).
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

        $expiresAt = null;

        // 1. Make sure we hold a long lived user token (the renewable root).
        if ($extend) {
            $extended = $connector->extendUserToken($userToken);

            if ($extended instanceof RenewalResult) {
                throw new RuntimeException('Could not extend the Facebook user token: '.$extended->reason);
            }

            $userToken = $extended['token'];
            $expiresAt = $extended['expiresAt'];
        }

        // 2. Resolve the user id (credential holder) if the caller did not pass it.
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

        $renewAt = $expiresAt?->copy()->sub($connector->leadTime());

        // 4. One row per page, keyed on the page id.
        $accounts = collect($pages)->map(function (array $page) use ($userId, $userToken, $expiresAt, $renewAt, $owner, $connectedBy) {
            $account = SocialAccount::query()->updateOrCreate(
                [
                    'provider' => 'facebook',
                    'provider_user_id' => $page['id'] ?? null,
                ],
                [
                    'provider_holder_id' => $userId,                 // the Facebook user behind this page
                    'name' => $page['name'] ?? null,
                    'avatar' => data_get($page, 'picture.data.url'),
                    'access_token' => $page['access_token'] ?? null, // page token, ready to post with
                    'refresh_token' => $userToken,                   // shared long lived user token
                    'expires_at' => $expiresAt,                      // user token expiry drives renew_at
                    'refresh_expires_at' => null,
                    'renew_at' => $renewAt,
                    'status' => AccountStatus::Active,
                    'last_error' => null,
                ],
            );

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
        });

        // 5. Reconcile: any still-active page we hold for THIS user that they no
        // longer manage (absent from the response) is flagged for reconnection.
        // Scoped to provider_holder_id so a co-owner's pages are never touched.
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
}
