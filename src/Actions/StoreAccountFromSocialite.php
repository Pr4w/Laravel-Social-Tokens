<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Call this from your OAuth callback controller after Socialite returns a user.
 * It is the integration point Socialite itself does not provide (Socialite
 * fires no event on return).
 */
class StoreAccountFromSocialite
{
    public function __construct(protected ConnectorRegistry $registry)
    {
    }

    public function handle(
        string $provider,
        SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
    ): SocialAccount {
        $connector = $this->registry->has($provider) ? $this->registry->for($provider) : null;

        $expiresAt = $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null;

        // refresh_expires_in is not on the Socialite contract; read it from the
        // raw token response when the provider exposes it (e.g. TikTok).
        $refreshExpiresIn = data_get($user, 'accessTokenResponseBody.refresh_expires_in');
        $refreshExpiresAt = $refreshExpiresIn ? now()->addSeconds((int) $refreshExpiresIn) : null;

        $renewAt = ($expiresAt && $connector)
            ? $expiresAt->copy()->sub($connector->leadTime())
            : null;

        $account = SocialAccount::query()->updateOrCreate(
            [
                'provider' => $provider,
                'provider_user_id' => $user->getId(),
            ],
            [
                'name' => $user->getName(),
                'nickname' => $user->getNickname(),
                'email' => $user->getEmail(),
                'avatar' => $user->getAvatar(),
                'access_token' => $user->token,
                'refresh_token' => $user->refreshToken ?: null,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'renew_at' => $renewAt,
                'scopes' => $user->approvedScopes ?? [],
                'status' => AccountStatus::Active,
                'last_error' => null,
            ],
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
    }
}
