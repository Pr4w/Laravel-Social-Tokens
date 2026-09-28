<?php

namespace Pr4w\SocialTokens\Models;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\Concerns\TransitionsStatus;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The renewable credential. One credential can back many accounts (a Meta user
 * token backs every Instagram account of a Facebook user; a LinkedIn member token
 * backs every organization). Renewal happens here, once, not per account. Each
 * Facebook Page posts with its own static page-token credential.
 *
 * @property string $provider
 * @property ?string $provider_holder_id
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?CarbonInterface $expires_at
 * @property ?CarbonInterface $refresh_expires_at
 * @property ?CarbonInterface $renew_at
 * @property ?CarbonInterface $last_renewed_at
 * @property array<int, string>|null $scopes
 * @property ?string $last_error
 * @property int $failed_checks
 * @property AccountStatus $status
 */
class SocialToken extends Model
{
    use TransitionsStatus;

    protected $guarded = [];

    /**
     * Never serialised (toArray/toJson, e.g. an account loaded with its
     * credential). Still readable as attributes; makeVisible() opts back in.
     *
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'renew_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'scopes' => 'array',
            'status' => AccountStatus::class,
        ];
    }

    public function getTable()
    {
        return config('social-tokens.tokens_table', 'social_tokens');
    }

    // Relationships ---------------------------------------------------------

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    // Scopes ----------------------------------------------------------------

    /**
     * Credentials whose renewal window has opened.
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeDueForRenewal(Builder $query): Builder
    {
        return $query
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('renew_at')
            ->where('renew_at', '<=', now());
    }

    /**
     * Credentials some active account still depends on: through social_token_id
     * (the account posts with it), or as the holder of accounts of the same
     * provider (the Meta user token behind a user's Facebook Pages, which post
     * with their own page tokens but are re-derived from it).
     *
     * @param  Builder<SocialToken>  $query
     * @return Builder<SocialToken>
     */
    public function scopeInUse(Builder $query): Builder
    {
        $tokens = $this->getTable();
        $accounts = (new SocialAccount)->getTable();
        $active = AccountStatus::Active->value;

        return $query->where(fn (Builder $query) => $query
            ->whereHas('accounts', fn (Builder $account) => $account->where('status', $active))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from($accounts)
                ->whereColumn("{$accounts}.provider", "{$tokens}.provider")
                ->whereColumn("{$accounts}.provider_holder_id", "{$tokens}.provider_holder_id")
                ->where("{$accounts}.status", $active)));
    }

    // Scopes ------------------------------------------------------------------

    /**
     * The one shape for stored scopes: trimmed strings, no blanks, no
     * duplicates, order kept. An empty result is null: "unknown", which never
     * overwrites a known list (a known-empty list comes from the provider).
     *
     * @param  array<int, mixed>|null  $scopes
     * @return array<int, string>|null
     */
    public static function normaliseScopes(?array $scopes): ?array
    {
        $scopes = array_values(array_unique(array_filter(
            array_map(fn ($scope) => is_string($scope) ? trim($scope) : '', $scopes ?? []),
            fn (string $scope) => $scope !== '',
        )));

        return $scopes === [] ? null : $scopes;
    }

    /** Whether the granted scopes are known (null means unknown, [] means none granted). */
    public function scopesKnown(): bool
    {
        return is_array($this->scopes);
    }

    // Scheduling --------------------------------------------------------------

    /**
     * When to renew a credential, the one rule used at connect time and after
     * each renewal: one lead time before whichever comes first, the access
     * token's expiry or the refresh token's (so the user is warned before the
     * refresh token dies). An unknown expiry on a renewable credential is
     * checked back after one lead time, never left static; a credential that
     * cannot renew unattended with an unknown expiry stays unscheduled.
     */
    public static function renewAtFor(
        ?CarbonInterface $expiresAt,
        ?ProviderConnector $connector,
        ?CarbonInterface $refreshExpiresAt = null,
    ): ?CarbonInterface {
        if ($connector === null) {
            return null;
        }

        $lead = $connector->leadTime();

        if ($expiresAt !== null) {
            $renewAt = $expiresAt->copy()->sub($lead);
        } elseif ($connector->renewalStrategy()->canRenewUnattended()) {
            $renewAt = now()->add($lead);
        } else {
            return null;
        }

        if ($refreshExpiresAt !== null && $refreshExpiresAt->copy()->sub($lead)->lessThan($renewAt)) {
            $renewAt = $refreshExpiresAt->copy()->sub($lead);
        }

        return $renewAt;
    }

    // State -----------------------------------------------------------------

    public function isAccessTokenExpired(int $bufferSeconds = 30): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo(now()->addSeconds($bufferSeconds));
    }

    /**
     * The renewal window has opened (same rule as the dueForRenewal scope). A
     * token can be due well before it expires: that is the point of the lead time.
     */
    public function isDueForRenewal(): bool
    {
        return $this->renew_at !== null && ! $this->renew_at->isFuture();
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_expires_at !== null && $this->refresh_expires_at->isPast();
    }

    /** The refresh token still works but dies within $lead: time to warn the user. */
    public function isRefreshTokenExpiring(CarbonInterval $lead): bool
    {
        return $this->refresh_expires_at !== null
            && ! $this->isRefreshTokenExpired()
            && $this->refresh_expires_at->copy()->sub($lead)->lessThanOrEqualTo(now());
    }

    /**
     * Apply a successful credential refresh and recompute the renewal window.
     */
    public function applyRenewal(RenewalResult $result, ProviderConnector $connector): self
    {
        // Re-read the status from the database: a revoke may have landed while
        // the provider call was in flight, and must never be undone here.
        if ($this->exists) {
            $current = $this->newQuery()->whereKey($this->getKey())->value('status'); // cast: an AccountStatus

            if ($current !== null) {
                $this->status = $current;
                $this->syncOriginalAttribute('status');
            }
        }

        if ($this->status === AccountStatus::Revoked) {
            return $this; // no token written, no CredentialRenewed
        }

        $this->access_token = $result->accessToken;

        // Only overwrite the refresh token when the provider issued a new one.
        if ($result->refreshToken !== null) {
            $this->refresh_token = $result->refreshToken;
        }

        // Before renew_at: a refresh token nearing its end pulls renewal earlier.
        if ($result->refreshExpiresAt !== null) {
            $this->refresh_expires_at = $result->refreshExpiresAt;
        }

        $notExtended = false;

        if ($result->expiresAt !== null) {
            $this->expires_at = $result->expiresAt;

            // "Not extended" is judged on the access token alone: renew_at may
            // legitimately land earlier because of the refresh token.
            if ($result->expiresAt->copy()->sub($connector->leadTime())->isFuture()) {
                $this->renew_at = static::renewAtFor($result->expiresAt, $connector, $this->refresh_expires_at);
            } else {
                // The provider did not push the expiry past the lead time (e.g.
                // Meta returning the remaining lifetime): renewing again on every
                // tick would change nothing. Land the next pass at the expiry,
                // where the token is flagged if nobody reconnected.
                $notExtended = true;
                $this->renew_at = $result->expiresAt->copy();
            }
        } else {
            // A renewable credential came back without an expiry. Keeping the
            // old expires_at would be stale, and a null renew_at would turn it
            // static and never renew it again — so mark the expiry unknown and
            // check back after one lead time.
            $this->expires_at = null;
            $this->renew_at = static::renewAtFor(null, $connector, $this->refresh_expires_at) ?? now()->add($connector->leadTime());

            if (config('social-tokens.log_unknown_errors', true)) {
                Log::warning('[social-tokens] Renewal returned no expiry', [
                    'provider' => $this->provider,
                    'token_id' => $this->getKey(),
                ]);
            }
        }

        // Providers that echo the granted scopes (TikTok and LinkedIn use commas,
        // Google spaces): keep the credential's list current.
        if (is_string($result->profile['scope'] ?? null)) {
            $scopes = static::normaliseScopes(preg_split('/[\s,]+/', trim($result->profile['scope'])) ?: []);

            if ($scopes !== null) {
                $this->scopes = $scopes;
            }
        }

        $this->status = AccountStatus::Active;
        $this->last_renewed_at = now();
        $this->last_error = null;
        $this->save();

        event(new CredentialRenewed($this));

        $strategy = $connector->renewalStrategy();

        if ($result->expiresAt !== null && $strategy === RenewalStrategy::ExtendLongLived) {
            // Record what the provider granted: whether Meta really extends a
            // still-valid long-lived token is not documented.
            Log::info('[social-tokens] Long-lived token extended', [
                'provider' => $this->provider,
                'token_id' => $this->getKey(),
                'expires_in' => (int) now()->diffInSeconds($result->expiresAt, false),
            ]);
        }

        if ($notExtended && $result->expiresAt !== null) {
            Log::warning('[social-tokens] Renewal did not extend the credential past its lead time', [
                'provider' => $this->provider,
                'token_id' => $this->getKey(),
                'expires_in' => (int) now()->diffInSeconds($result->expiresAt, false),
                'expires_at' => $result->expiresAt->toIso8601String(),
            ]);

            // Nothing renews a long-lived token that will not extend: the user
            // has to reconnect before it expires. Refresh-token providers will
            // still refresh at the expiry, so they get no warning.
            if ($strategy === RenewalStrategy::ExtendLongLived) {
                event(new CredentialExpiringSoon($this, $result->expiresAt, 'Provider did not extend the token.'));
            }
        }

        return $this;
    }

    /**
     * One-way and idempotent: only an active credential moves to
     * needs_reconnect, and only the call that actually moves it fires the event
     * and records its reason (the root cause; later callers change nothing). A
     * revoked credential is never downgraded. Writes only the status and
     * last_error: save() any other change yourself.
     */
    public function markNeedsReconnect(?string $reason = null): self
    {
        $this->markNeedsReconnectOnce($reason);

        return $this;
    }

    /**
     * markNeedsReconnect() that says whether THIS call flagged the credential,
     * and can be limited to a given token: with $checkedToken, the credential
     * is flagged only if it still holds that token (a rejection of a token a
     * reconnect already replaced says nothing about the new one).
     */
    public function markNeedsReconnectOnce(?string $reason = null, ?string $checkedToken = null): bool
    {
        if ($checkedToken !== null && $this->exists) {
            // Tokens are encrypted with a random IV: compare the decrypted value
            // of the current row, then pin the update to that exact ciphertext.
            $current = $this->newQuery()->whereKey($this->getKey())->first();

            if ($current === null || ! hash_equals((string) $current->access_token, $checkedToken)) {
                if ($current !== null) {
                    $this->status = $current->status;
                    $this->syncOriginalAttribute('status');
                }

                return false;
            }

            $changed = $this->newQuery()
                ->whereKey($this->getKey())
                ->where('status', AccountStatus::Active->value)
                ->where('access_token', $current->getRawOriginal('access_token'))
                ->update(['status' => AccountStatus::NeedsReconnect->value, 'last_error' => $reason]) > 0;

            if ($changed) {
                $this->forceFill(['status' => AccountStatus::NeedsReconnect, 'last_error' => $reason])
                    ->syncOriginalAttributes(['status', 'last_error']);
            }
        } else {
            $changed = $this->transitionStatus(AccountStatus::NeedsReconnect, [AccountStatus::Active], ['last_error' => $reason]);
        }

        if ($changed) {
            event(new CredentialNeedsReconnect($this, $reason));
        }

        return $changed;
    }

    /** One-way and idempotent: fires CredentialRevoked only on the actual change. */
    public function markRevoked(): self
    {
        if ($this->transitionStatus(AccountStatus::Revoked, [AccountStatus::Active, AccountStatus::NeedsReconnect])) {
            event(new CredentialRevoked($this));
        }

        return $this;
    }
}
