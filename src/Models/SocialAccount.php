<?php

namespace Pr4w\SocialTokens\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Models\Concerns\TransitionsStatus;

/**
 * A postable identity. It holds no tokens: it posts with its credential
 * (SocialToken), which owns renewal. The account keeps its own status so an
 * individual account can be flagged (e.g. a page the user no longer manages)
 * independently of the credential. A dead credential is not copied onto its
 * accounts: effectiveStatus() / usable() combine the two.
 *
 * @property ?int $social_token_id
 * @property ?SocialToken $credential
 * @property string $provider
 * @property ?string $provider_user_id
 * @property ?string $provider_holder_id
 * @property ?string $name
 * @property ?string $nickname
 * @property ?string $email
 * @property ?string $avatar
 * @property array<int, string>|null $scopes
 * @property array<string, mixed>|null $profile
 * @property ?string $last_error
 * @property AccountStatus $status
 */
class SocialAccount extends Model
{
    use TransitionsStatus;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'profile' => 'array',
            'status' => AccountStatus::class,
        ];
    }

    public function getTable()
    {
        return config('social-tokens.table', 'social_accounts');
    }

    // Relationships ---------------------------------------------------------

    /**
     * The credential this account posts with and renews through.
     *
     * @return BelongsTo<SocialToken, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(SocialToken::class, 'social_token_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function ownable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function connectedBy(): MorphTo
    {
        // Explicit name: the columns are connected_by_*, which the default
        // guess (derived from the method name) would not match.
        return $this->morphTo('connected_by');
    }

    // Owner -----------------------------------------------------------------

    /**
     * The owner part of an account's (or credential's) identity: since 2.0 a
     * row is unique per (provider, external id, owner). Uses the morph class,
     * as associate() does, so a morph map is honoured. Null means no owner.
     *
     * @return array{ownable_type: ?string, ownable_id: mixed}
     */
    public static function ownerKey(?Model $owner): array
    {
        return [
            'ownable_type' => $owner?->getMorphClass(),
            'ownable_id' => $owner?->getKey(),
        ];
    }

    /**
     * Rows of one owner (or the owner-less ones for null). An external account
     * can now have one row per owner: scope lookups by provider_user_id with it.
     *
     * @param  Builder<SocialAccount>  $query
     * @return Builder<SocialAccount>
     */
    public function scopeOwnedBy(Builder $query, ?Model $owner): Builder
    {
        return $owner === null
            ? $query->whereNull('ownable_type')->whereNull('ownable_id')
            : $query->whereMorphedTo('ownable', $owner);
    }

    // Scopes (per-account granted scopes) -----------------------------------

    /**
     * Whether the granted scopes are known. null means unknown (grantedScopes()
     * then reports none); [] means the provider granted none.
     */
    public function scopesKnown(): bool
    {
        return is_array($this->scopes);
    }

    /** @return array<int, string> */
    public function grantedScopes(): array
    {
        return $this->scopes ?? [];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->grantedScopes(), true);
    }

    /** @param array<int, string> $scopes  True when every scope is granted. */
    public function hasScopes(array $scopes): bool
    {
        return $this->missingScopes($scopes) === [];
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string> The requested scopes not granted to this account.
     */
    public function missingScopes(array $scopes): array
    {
        return array_values(array_diff($scopes, $this->grantedScopes()));
    }

    /**
     * Merge keys into `profile` rather than replace it: the package's keys win,
     * keys the app stored itself are kept.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeProfile(array $values): static
    {
        $this->profile = array_merge($this->profile ?? [], $values);

        return $this;
    }

    // Effective status ------------------------------------------------------

    /**
     * What the account can actually do, combining its own status with its
     * credential's. The `status` column only records account-level flags (e.g. a
     * page the user no longer manages); when the shared credential dies, its
     * accounts' rows stay untouched, so read this — not `status` — to show
     * whether an account can post. The most severe of the two wins, and an
     * account without a credential cannot post.
     */
    public function effectiveStatus(): AccountStatus
    {
        $credentialStatus = $this->credential->status ?? AccountStatus::NeedsReconnect;

        foreach ([AccountStatus::Revoked, AccountStatus::NeedsReconnect] as $status) {
            if ($this->status === $status || $credentialStatus === $status) {
                return $status;
            }
        }

        return AccountStatus::Active;
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus()->isUsable();
    }

    /**
     * Accounts that can post: active themselves, backed by an active credential.
     *
     * @param  Builder<SocialAccount>  $query
     * @return Builder<SocialAccount>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('status', AccountStatus::Active->value)
            ->whereHas('credential', fn (Builder $credential) => $credential->where('status', AccountStatus::Active->value));
    }

    /**
     * Accounts that cannot post, whether flagged themselves or through their
     * credential (or with no credential at all). The complement of usable().
     *
     * @param  Builder<SocialAccount>  $query
     * @return Builder<SocialAccount>
     */
    public function scopeUnusable(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('status', '!=', AccountStatus::Active->value)
            ->orWhereDoesntHave('credential', fn (Builder $credential) => $credential->where('status', AccountStatus::Active->value)));
    }

    // State -----------------------------------------------------------------

    /**
     * One-way and idempotent, like the credential's: only an active account is
     * flagged, and only the call that flags it fires the event. A revoked
     * account is never downgraded.
     */
    public function markNeedsReconnect(?string $reason = null): self
    {
        if ($this->transitionStatus(AccountStatus::NeedsReconnect, [AccountStatus::Active], ['last_error' => $reason])) {
            event(new AccountNeedsReconnect($this, $reason));
        }

        return $this;
    }

    /** One-way and idempotent: fires AccountRevoked only on the actual change. */
    public function markRevoked(): self
    {
        if ($this->transitionStatus(AccountStatus::Revoked, [AccountStatus::Active, AccountStatus::NeedsReconnect])) {
            event(new AccountRevoked($this));
        }

        return $this;
    }
}
