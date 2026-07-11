<?php

namespace Pr4w\SocialTokens\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;

/**
 * A postable identity. It holds no tokens: it posts with its credential
 * (SocialToken), which owns renewal. The account keeps its own status so an
 * individual account can be flagged (e.g. a page the user no longer manages)
 * independently of the credential.
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

    // Scopes (per-account granted scopes) -----------------------------------

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

    // State -----------------------------------------------------------------

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
