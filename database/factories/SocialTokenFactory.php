<?php

namespace Pr4w\SocialTokens\Database\Factories;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Credentials for an app's tests: an active Google credential holding a
 * token valid for 30 days, scheduled (renew_at) as its provider's connector
 * schedules a connection. The states give the shapes a publish job, a
 * renewal or a check has to deal with.
 *
 * renew_at follows expires_at and refresh_expires_at as set by the states,
 * unless a state sets it: null when the provider has no connector.
 *
 * @extends Factory<SocialToken>
 */
class SocialTokenFactory extends Factory
{
    protected $model = SocialToken::class;

    /**
     * @return array<model-property<SocialToken>, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'google',
            'provider_holder_id' => Str::random(16),
            'purpose' => SocialToken::DEFAULT_PURPOSE,
            'ownable_type' => null,
            'ownable_id' => null,
            'access_token' => 'access-'.Str::random(32),
            'refresh_token' => 'refresh-'.Str::random(32),
            'expires_at' => now()->addDays(30),
            'refresh_expires_at' => null,
            'renew_at' => fn (array $attributes) => static::scheduledRenewal($attributes),
            'last_renewed_at' => now(),
            'scopes' => null,
            'status' => AccountStatus::Active,
            'last_error' => null,
            'failed_checks' => 0,
        ];
    }

    /** A credential of another provider (the connector key its renewals go through: facebook for Instagram). */
    public function forProvider(string $provider, ?string $holderId = null): static
    {
        return $this->state(array_filter([
            'provider' => $provider,
            'provider_holder_id' => $holderId,
        ], fn ($value) => $value !== null));
    }

    /** The grant of one owner (null: owner-less). */
    public function ownedBy(?Model $owner): static
    {
        return $this->state(SocialAccount::ownerKey($owner));
    }

    /** Another purpose than the default one (StoreLinkedInOrganizations::PURPOSE). */
    public function forPurpose(string $purpose): static
    {
        return $this->state(['purpose' => $purpose]);
    }

    /**
     * Its renewal window has opened, its token still works: the dispatcher
     * renews it, validAccessTokenFor() hands the token out as is.
     */
    public function dueForRenewal(): static
    {
        return $this->state([
            'expires_at' => now()->addMinutes(10),
            'renew_at' => now()->subMinute(),
        ]);
    }

    /** Its token has expired: validAccessTokenFor() renews it first. */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    /** A token that does not expire and is never renewed, like a Facebook Page token. */
    public function neverExpiring(): static
    {
        return $this->state([
            'refresh_token' => null,
            'expires_at' => null,
            'renew_at' => null,
        ]);
    }

    /** No refresh token: LinkedIn without refresh tokens, a Meta or Threads long-lived token. */
    public function withoutRefreshToken(): static
    {
        return $this->state(['refresh_token' => null]);
    }

    /** Flagged: only a reconnect brings it back. */
    public function needsReconnect(?string $reason = 'The provider rejected the token.'): static
    {
        return $this->state([
            'status' => AccountStatus::NeedsReconnect,
            'last_error' => $reason,
        ]);
    }

    /** Revoked as SocialTokens::revoke() leaves it: no token, nothing scheduled. */
    public function revoked(): static
    {
        return $this->state([
            'status' => AccountStatus::Revoked,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'refresh_expires_at' => null,
            'renew_at' => null,
        ]);
    }

    /**
     * When the provider's connector would renew a credential stored with
     * these attributes (SocialToken::renewAtFor()).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function scheduledRenewal(array $attributes): ?CarbonInterface
    {
        $registry = app(ConnectorRegistry::class);
        $provider = $attributes['provider'] ?? null;

        if (! is_string($provider) || ! $registry->has($provider)) {
            return null;
        }

        return SocialToken::renewAtFor(
            static::date($attributes['expires_at'] ?? null),
            $registry->for($provider),
            static::date($attributes['refresh_expires_at'] ?? null),
        );
    }

    protected static function date(mixed $value): ?CarbonInterface
    {
        return $value === null ? null : Date::parse($value);
    }
}
