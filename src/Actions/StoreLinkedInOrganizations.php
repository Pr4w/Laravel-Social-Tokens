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
 * member token and its metadata rather than a Socialite user. That token is
 * stored on the member's organizations credential (purpose PURPOSE), apart
 * from the default credential of the member's personal profile, which comes
 * from another authorization with other scopes (StoreAccountFromSocialite):
 * on one shared credential, each flow replaced the other's token. The personal
 * row still carries provider_holder_id = member id, so reconciliation excludes
 * it explicitly by its provider_user_id (the member id).
 */
class StoreLinkedInOrganizations
{
    /** The purpose of the member credential the organizations post with. */
    public const PURPOSE = 'organizations';

    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * @param  string  $accessToken  The member access token (already exchanged).
     * @param  string  $memberId  The LinkedIn member id (credential holder).
     * @param  Model|null  $owner  App-side entity that owns these connections.
     * @param  Model|null  $connectedBy  Who performed the connection (optional).
     * @param  string|null  $refreshToken  Member refresh token, if your app has MDP.
     * @param  CarbonInterface|null  $expiresAt  Access token expiry; null keeps the stored one when
     *                                           $accessToken is the token already stored (a re-sync).
     * @param  CarbonInterface|null  $refreshExpiresAt  Refresh token expiry.
     * @param  array<int, string>|null  $scopes  Granted scopes (LinkedIn's comma-separated `scope`, split
     *                                           or as one element); null means unknown and keeps the stored ones.
     * @return Collection<int, SocialAccount> One row per administered organization.
     *
     * @throws RuntimeException when the organizations cannot be listed.
     */
    public function handle(
        #[\SensitiveParameter] string $accessToken,
        string $memberId,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        #[\SensitiveParameter] ?string $refreshToken = null,
        ?CarbonInterface $expiresAt = null,
        ?CarbonInterface $refreshExpiresAt = null,
        ?array $scopes = null,
    ): Collection {
        $connector = $this->registry->for('linkedin');

        if (! $connector instanceof LinkedInConnector) {
            throw new RuntimeException('The "linkedin" connector must be a LinkedInConnector to seed organizations.');
        }

        $refreshToken = $refreshToken ?: null;
        $organizations = $connector->fetchOrganizations($accessToken);

        if ($organizations instanceof RenewalResult) {
            throw new RuntimeException('Could not list LinkedIn organizations: '.$organizations->reason);
        }

        // Never forget a known refresh token: a re-sync without one must not
        // wipe it or its expiry.
        $existing = SocialToken::query()
            ->where('provider', 'linkedin')
            ->where('provider_holder_id', $memberId)
            ->forPurpose(self::PURPOSE)
            ->ownedBy($owner)
            ->first();

        if ($existing !== null) {
            $refreshToken ??= $existing->refresh_token;

            if ($refreshExpiresAt === null && $refreshToken === $existing->refresh_token) {
                $refreshExpiresAt = $existing->refresh_expires_at;
            }
        }

        // A re-sync with the member token already stored (to pick up new
        // organizations) is not a new token, and tells nothing new about it.
        // Its issue date stays. So does its expiry when none is given: erased,
        // a ReauthOnly credential would turn static (never warned, handed out
        // after it died). And so does renew_at while the expiries are the same:
        // a warning already sent may have parked it on the expiry.
        $kept = [];

        if ($existing !== null && hash_equals((string) $existing->access_token, $accessToken)) {
            $kept['last_renewed_at'] = $existing->last_renewed_at;

            if ($expiresAt === null) {
                $expiresAt = $existing->expires_at;

                if ($refreshToken === $existing->refresh_token && self::sameMoment($refreshExpiresAt, $existing->refresh_expires_at)) {
                    $kept['renew_at'] = $existing->renew_at;
                }
            }
        }

        // Unknown scopes (null) never overwrite a known list.
        $scopes = SocialToken::normaliseScopes($scopes);
        $withScopes = $scopes === null ? [] : ['scopes' => $scopes];

        // The renewable member credential every organization posts with.
        $credential = SocialToken::persistCredential(
            ['provider' => 'linkedin', 'provider_holder_id' => $memberId, 'purpose' => self::PURPOSE] + SocialAccount::ownerKey($owner),
            $kept + [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
            ] + $withScopes,
            $connector, // schedules renew_at, unless kept
        );

        // One row per organization, keyed on the organization id.
        $accounts = collect($organizations)->map(function (array $org) use (
            $memberId, $credential, $owner, $connectedBy, $withScopes
        ) {
            $attributes = [
                'social_token_id' => $credential->getKey(), // shared member token
                'provider_holder_id' => $memberId,          // the member whose token backs this org
                'name' => $org['name'] ?? null,
                'avatar' => $org['logo'] ?? null,
                'status' => AccountStatus::Active,
                'last_error' => null,
            ] + $withScopes;

            // Resolved from its URN because LinkedIn throttled the decoration:
            // keep the name and logo already stored rather than erase them.
            if (($org['name'] ?? null) === null) {
                unset($attributes['name'], $attributes['avatar']);
            }

            // profile is merged, not replaced: keys the app stored itself survive.
            $account = SocialAccount::query()
                ->firstOrNew(['provider' => 'linkedin', 'provider_user_id' => $org['id']] + SocialAccount::ownerKey($owner))
                ->fill($attributes)
                ->mergeProfile(['organization_urn' => $org['urn'], 'role' => $org['role'] ?? null]);

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
        // members' orgs are never touched; the member's personal row carries
        // that holder id too and is excluded by its provider_user_id.
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

    private static function sameMoment(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->equalTo($b);
    }
}
