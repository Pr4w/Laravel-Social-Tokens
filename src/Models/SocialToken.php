<?php

namespace Pr4w\SocialTokens\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * The renewable credential. One credential backs many accounts (a Meta user
 * token backs every Facebook page and Instagram account; a LinkedIn member token
 * backs every organization). Renewal happens here, once, not per account.
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
 * @property AccountStatus $status
 */
class SocialToken extends Model
{
    protected $guarded = [];

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
     * Apply a successful credential refresh and recompute the renewal window.
     */
    public function applyRenewal(RenewalResult $result, ProviderConnector $connector): self
    {
        $this->access_token = $result->accessToken;

        // Only overwrite the refresh token when the provider issued a new one.
        if ($result->refreshToken !== null) {
            $this->refresh_token = $result->refreshToken;
        }

        if ($result->expiresAt !== null) {
            $this->expires_at = $result->expiresAt;
            $this->renew_at = $result->expiresAt->copy()->sub($connector->leadTime());
        } else {
            $this->renew_at = null;
        }

        if ($result->refreshExpiresAt !== null) {
            $this->refresh_expires_at = $result->refreshExpiresAt;
        }

        $this->status = AccountStatus::Active;
        $this->last_renewed_at = now();
        $this->last_error = null;
        $this->save();

        event(new CredentialRenewed($this));

        return $this;
    }

    public function markNeedsReconnect(?string $reason = null): self
    {
        $this->status = AccountStatus::NeedsReconnect;
        $this->last_error = $reason;
        $this->save();

        event(new CredentialNeedsReconnect($this, $reason));

        return $this;
    }

    public function markRevoked(): self
    {
        $this->status = AccountStatus::Revoked;
        $this->save();

        event(new CredentialRevoked($this));

        return $this;
    }
}
