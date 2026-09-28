<?php

namespace Pr4w\SocialTokens\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use RuntimeException;

/**
 * One LinkedIn member can administer several organizations (company Pages), and
 * each posts with the SAME member token (w_organization_social) — there is no
 * per-organization token like a Facebook Page. So this fans out to one row per
 * organization, each mirroring the member's credential.
 *
 * Because LinkedIn's token exchange is app-side (Socialite's driver can't fetch
 * the profile with non-OIDC scopes), this action takes an already-obtained
 * member token and its metadata rather than a Socialite user. The personal
 * profile is a separate row (store it with StoreAccountFromSocialite). It also
 * carries provider_holder_id = member id and shares the (linkedin, member id)
 * credential with the organizations, so reconciliation excludes it explicitly
 * by its provider_user_id (the member id).
 */
class StoreLinkedInOrganizations
{
    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * @param  string  $accessToken  The member access token (already exchanged).
     * @param  string  $memberId  The LinkedIn member id (credential holder).
     * @param  Model|null  $owner  App-side entity that owns these connections.
     * @param  Model|null  $connectedBy  Who performed the connection (optional).
     * @param  string|null  $refreshToken  Member refresh token, if your app has MDP.
     * @param  CarbonInterface|null  $expiresAt  Access token expiry.
     * @param  CarbonInterface|null  $refreshExpiresAt  Refresh token expiry.
     * @return Collection<int, SocialAccount> One row per administered organization.
     *
     * @throws RuntimeException when the organizations cannot be listed.
     */
    public function handle(
        string $accessToken,
        string $memberId,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        ?string $refreshToken = null,
        ?CarbonInterface $expiresAt = null,
        ?CarbonInterface $refreshExpiresAt = null,
    ): Collection {
        $connector = $this->registry->for('linkedin');

        if (! $connector instanceof LinkedInConnector) {
            throw new RuntimeException('The "linkedin" connector must be a LinkedInConnector to seed organizations.');
        }

        $organizations = $connector->fetchOrganizations($accessToken);

        if ($organizations instanceof RenewalResult) {
            throw new RuntimeException('Could not list LinkedIn organizations: '.$organizations->reason);
        }

        // Never forget a known refresh token: a re-sync without one (or the
        // personal profile stored meanwhile) must not wipe it or its expiry.
        $existing = SocialToken::query()
            ->where('provider', 'linkedin')
            ->where('provider_holder_id', $memberId)
            ->first();

        if ($existing !== null) {
            $refreshToken ??= $existing->refresh_token;

            if ($refreshExpiresAt === null && $refreshToken === $existing->refresh_token) {
                $refreshExpiresAt = $existing->refresh_expires_at;
            }
        }

        $renewAt = SocialToken::renewAtFor($expiresAt, $connector, $refreshExpiresAt);

        // The shared renewable member credential every organization posts with.
        $credential = SocialToken::query()->updateOrCreate(
            ['provider' => 'linkedin', 'provider_holder_id' => $memberId],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'renew_at' => $renewAt,
                'status' => AccountStatus::Active,
                'last_error' => null,
            ],
        );

        // One row per organization, keyed on the organization id.
        $accounts = collect($organizations)->map(function (array $org) use (
            $memberId, $credential, $owner, $connectedBy
        ) {
            $attributes = [
                'social_token_id' => $credential->getKey(), // shared member token
                'provider_holder_id' => $memberId,          // the member whose token backs this org
                'name' => $org['name'] ?? null,
                'avatar' => $org['logo'] ?? null,
                'status' => AccountStatus::Active,
                'last_error' => null,
                'profile' => ['organization_urn' => $org['urn'], 'role' => $org['role'] ?? null],
            ];

            // Resolved from its URN because LinkedIn throttled the decoration:
            // keep the name and logo already stored rather than erase them.
            if (($org['name'] ?? null) === null) {
                unset($attributes['name'], $attributes['avatar']);
            }

            $account = SocialAccount::query()->updateOrCreate(
                ['provider' => 'linkedin', 'provider_user_id' => $org['id']],
                $attributes,
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

        // Reconcile: organizations this member no longer administers (absent from
        // the response) are flagged. Scoped to provider_holder_id, so other
        // members' orgs are never touched; the member's personal row shares that
        // holder id and is excluded by its provider_user_id.
        $managedIds = collect($organizations)->pluck('id')->filter()->values()->all();

        SocialAccount::query()
            ->where('provider', 'linkedin')
            ->where('provider_holder_id', $memberId)
            ->where('provider_user_id', '!=', $memberId) // never the member's personal row
            ->where('status', AccountStatus::Active->value)
            ->when($managedIds !== [], fn ($query) => $query->whereNotIn('provider_user_id', $managedIds))
            ->get()
            ->each(fn (SocialAccount $account) => $account->markNeedsReconnect(
                'Organization no longer administered by the connected LinkedIn member.'
            ));

        return $accounts;
    }
}
