<?php

namespace Pr4w\SocialTokens\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
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
            // A renewable credential came back without an expiry. Keeping the
            // old expires_at would be stale, and a null renew_at would turn it
            // static and never renew it again — so mark the expiry unknown and
            // check back after one lead time.
            $this->expires_at = null;
            $this->renew_at = now()->add($connector->leadTime());

            if (config('social-tokens.log_unknown_errors', true)) {
                Log::warning('[social-tokens] Renewal returned no expiry', [
                    'provider' => $this->provider,
                    'token_id' => $this->getKey(),
                ]);
            }
        }

        if ($result->refreshExpiresAt !== null) {
            $this->refresh_expires_at = $result->refreshExpiresAt;
        }

        // Providers that echo the granted scopes (TikTok and LinkedIn use commas,
        // Google spaces): keep the credential's list current.
        if (is_string($result->profile['scope'] ?? null)) {
            $this->scopes = preg_split('/[\s,]+/', trim($result->profile['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
