<?php

namespace Pr4w\SocialTokens\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\TokenRenewed;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * @property string $provider
 * @property ?string $provider_user_id
 * @property ?string $provider_holder_id
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $refresh_expires_at
 * @property ?\Illuminate\Support\Carbon $renew_at
 * @property AccountStatus $status
 */
class SocialAccount extends Model
{
    protected $guarded = [];

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
            'profile' => 'array',
            'status' => AccountStatus::class,
        ];
    }

    public function getTable()
    {
        return config('social-tokens.table', 'social_accounts');
    }

    // Relationships ---------------------------------------------------------

    public function ownable(): MorphTo
    {
        return $this->morphTo();
    }

    public function connectedBy(): MorphTo
    {
        // Explicit name: the columns are connected_by_*, which the default
        // guess (derived from the method name) would not match.
        return $this->morphTo('connected_by');
    }

    // Scopes ----------------------------------------------------------------

    /**
     * Accounts whose token is due for renewal.
     */
    public function scopeDueForRenewal(Builder $query): Builder
    {
        return $query
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('renew_at')
            ->where('renew_at', '<=', now());
    }

    // State -----------------------------------------------------------------

    public function isAccessTokenExpired(int $bufferSeconds = 30): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo(now()->addSeconds($bufferSeconds));
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_expires_at !== null && $this->refresh_expires_at->isPast();
    }

    /**
     * Apply a successful renewal result and recompute the renewal window.
     */
    public function applyRenewal(RenewalResult $result, ProviderConnector $connector): self
    {
        $this->access_token = $result->accessToken;

        // Only overwrite the refresh token when the provider issued a new one
        // (rotating strategy). For stable strategies it stays untouched.
        if ($result->refreshToken !== null) {
            $this->refresh_token = $result->refreshToken;
        }

        if ($result->expiresAt !== null) {
            $this->expires_at = $result->expiresAt;
            $this->renew_at = $result->expiresAt->copy()->sub($connector->leadTime());
        } else {
            // No expiry reported (e.g. a non-expiring Meta token): clear renew_at
            // so the dispatcher stops re-queuing against a stale past timestamp.
            $this->renew_at = null;
        }

        if ($result->refreshExpiresAt !== null) {
            $this->refresh_expires_at = $result->refreshExpiresAt;
        }

        if ($result->profile !== []) {
            $this->profile = array_merge($this->profile ?? [], $result->profile);
        }

        $this->status = AccountStatus::Active;
        $this->last_renewed_at = now();
        $this->last_error = null;
        $this->save();

        event(new TokenRenewed($this));

        return $this;
    }

    public function markNeedsReconnect(?string $reason = null): self
    {
        $this->status = AccountStatus::NeedsReconnect;
        $this->last_error = $reason;
        $this->save();

        event(new AccountNeedsReconnect($this, $reason));

        return $this;
    }

    public function markRevoked(): self
    {
        $this->status = AccountStatus::Revoked;
        $this->save();

        event(new AccountRevoked($this));

        return $this;
    }
}
