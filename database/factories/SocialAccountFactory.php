<?php

namespace Pr4w\SocialTokens\Database\Factories;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Accounts for an app's tests: an active Google account posting with a
 * credential of its own, created with it and matching it as a connection
 * stores them: the provider its connector renews under (facebook for an
 * Instagram account), the account's holder (provider_holder_id), its owner.
 * A Facebook Page posts with a Page token of its own, held by the Page and
 * never expiring; a Meta or Threads credential has no refresh token.
 * credential() shapes that credential with SocialTokenFactory's states, and
 * Laravel's for($credential, 'credential') points the account at one made
 * beforehand instead.
 *
 * As at a connection, accounts of one holder share a credential: one already
 * stored with the same provider, holder, purpose and owner is used instead of
 * a new one (several Instagram accounts of one Facebook user, the
 * organizations of one LinkedIn member). credential() shapes only a
 * credential the factory creates.
 *
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /**
     * @return array<model-property<SocialAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'google',
            'provider_user_id' => Str::random(16),
            'provider_holder_id' => fn (array $attributes) => $attributes['provider_user_id'],
            'ownable_type' => null,
            'ownable_id' => null,
            'name' => 'Test account',
            'nickname' => null,
            'email' => null,
            'avatar' => null,
            'scopes' => null,
            'profile' => null,
            'status' => AccountStatus::Active,
            'last_error' => null,
            // Last, so that it reads the attributes above as the states left them.
            'social_token_id' => fn (array $attributes) => $this->credentialFor($attributes),
        ];
    }

    public function forProvider(string $provider): static
    {
        return $this->state(['provider' => $provider]);
    }

    /** The external user whose grant the account posts through (a Facebook user's Pages and Instagram accounts). */
    public function heldBy(string $holderId): static
    {
        return $this->state(['provider_holder_id' => $holderId]);
    }

    /**
     * A LinkedIn organization the member administers, posting with the
     * member's organizations credential (StoreLinkedInOrganizations::PURPOSE),
     * which the member's other organizations of the same owner share.
     */
    public function linkedInOrganization(string $memberId): static
    {
        return $this->forProvider('linkedin')
            ->heldBy($memberId)
            ->credential(fn (SocialTokenFactory $credential) => $credential->forPurpose(StoreLinkedInOrganizations::PURPOSE));
    }

    /** The account, and the credential created with it, of one owner (null: owner-less). */
    public function ownedBy(?Model $owner): static
    {
        return $this->state(SocialAccount::ownerKey($owner));
    }

    public function connectedBy(?Model $user): static
    {
        return $this->state([
            'connected_by_type' => $user?->getMorphClass(),
            'connected_by_id' => $user?->getKey(),
        ]);
    }

    /**
     * Shape the credential created with the account, from SocialTokenFactory
     * already matching it: ->credential(fn ($credential) => $credential->expired()),
     * or attributes. Calls add up, in order.
     *
     * @param  (Closure(SocialTokenFactory): SocialTokenFactory)|array<string, mixed>  $state
     */
    public function credential(Closure|array $state): static
    {
        return $this->state(function (array $attributes) use ($state) {
            $previous = $attributes['social_token_id'] ?? null;

            return ['social_token_id' => function (array $resolved) use ($previous, $state) {
                $credential = $previous instanceof Closure ? $previous($resolved) : $previous;

                if (! $credential instanceof SocialTokenFactory) {
                    return $credential; // for() named an existing credential
                }

                return $state instanceof Closure ? $state($credential) : $credential->state($state);
            }];
        });
    }

    /** Flagged on its own (a Page the user no longer manages); its credential stays active. */
    public function needsReconnect(?string $reason = null): static
    {
        return $this->state([
            'status' => AccountStatus::NeedsReconnect,
            'last_error' => $reason,
        ]);
    }

    /** Revoked on its own ($account->markRevoked()); its credential stays active. */
    public function revoked(): static
    {
        return $this->state(['status' => AccountStatus::Revoked]);
    }

    /**
     * Disconnected by the user (SocialTokens::disconnect()): revoked, and left
     * out of fan-out reconnects. Its credential is revoked with it.
     */
    public function disconnected(): static
    {
        return $this->state([
            'status' => AccountStatus::Revoked,
            'disconnected_at' => now(),
        ])->credential(fn (SocialTokenFactory $credential) => $credential->revoked());
    }

    /**
     * Resolve the credential the states left to be created through
     * storedLike(), once every state has had its say: credential() may
     * still change its purpose (linkedInOrganization()).
     *
     * @return array<string, mixed>
     */
    protected function getRawAttributes(?Model $parent)
    {
        $attributes = parent::getRawAttributes($parent);
        $credential = $attributes['social_token_id'] ?? null;

        if ($credential instanceof Closure) {
            $attributes['social_token_id'] = fn (array $resolved) => $this->storedLike($credential($resolved));
        }

        return $attributes;
    }

    /**
     * The credential already stored under the unique key the factory would
     * create one with (provider, holder, purpose, owner), or the factory: a
     * second one breaks that key under an owner, and stands beside the first
     * without one, where a connection stores one per holder.
     */
    protected function storedLike(mixed $credential): mixed
    {
        if (! $credential instanceof SocialTokenFactory || ! $this->expandRelationships) {
            return $credential; // for() named one, or withoutParents() creates none
        }

        $attributes = $credential->raw();

        $stored = SocialToken::query()
            ->where('provider', $attributes['provider'])
            ->where('provider_holder_id', $attributes['provider_holder_id'])
            ->where('purpose', $attributes['purpose'] ?? SocialToken::DEFAULT_PURPOSE);

        $stored = ($attributes['ownable_type'] ?? null) === null
            ? $stored->whereNull('ownable_type')->whereNull('ownable_id')
            : $stored->where('ownable_type', $attributes['ownable_type'])->where('ownable_id', $attributes['ownable_id']);

        return $stored->first() ?? $credential;
    }

    /**
     * The credential a connection would store with this account.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function credentialFor(array $attributes): SocialTokenFactory
    {
        $registry = app(ConnectorRegistry::class);
        $provider = (string) $attributes['provider'];
        $connector = $registry->has($provider) ? $registry->for($provider) : null;

        $credential = SocialToken::factory()->state([
            'provider' => $connector?->credentialProvider() ?? $provider,
            'provider_holder_id' => $attributes['provider_holder_id'] ?? $attributes['provider_user_id'],
            'ownable_type' => $attributes['ownable_type'] ?? null,
            'ownable_id' => $attributes['ownable_id'] ?? null,
        ]);

        if ($provider === 'facebook') {
            return $credential->state(['provider_holder_id' => $attributes['provider_user_id']])->neverExpiring();
        }

        return $connector?->renewalStrategy() === RenewalStrategy::ExtendLongLived
            ? $credential->withoutRefreshToken()
            : $credential;
    }
}
