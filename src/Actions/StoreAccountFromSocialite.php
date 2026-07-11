<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use RuntimeException;

/**
 * Call this from your OAuth callback controller after Socialite returns a user.
 * It is the integration point Socialite itself does not provide (Socialite
 * fires no event on return).
 */
class StoreAccountFromSocialite
{
    public function __construct(protected ConnectorRegistry $registry) {}

    public function handle(
        string $provider,
        SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
    ): SocialAccount {
        $connector = $this->registry->has($provider) ? $this->registry->for($provider) : null;

        $accessToken = $user->token;
        $refreshToken = $user->refreshToken ?: null;
        $expiresAt = $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null;

        // refresh_expires_in is not on the Socialite contract; read it from the
        // raw token response when the provider exposes it (e.g. TikTok).
        $refreshExpiresIn = data_get($user, 'accessTokenResponseBody.refresh_expires_in');
        $refreshExpiresAt = $refreshExpiresIn ? now()->addSeconds((int) $refreshExpiresIn) : null;

        // Upgrade to a long-lived token where the provider needs a distinct
        // connect-time exchange (Instagram, Threads). Providers whose connect
        // token is already durable return null and are stored as-is.
        if ($longLived && $connector) {
            $exchanged = $connector->exchangeForLongLived($accessToken);

            if ($exchanged !== null) {
                if (! $exchanged->succeeded()) {
                    throw new RuntimeException(
                        "Could not obtain a long-lived [{$provider}] token: ".($exchanged->reason ?? 'unknown')
                    );
                }

                $accessToken = $exchanged->accessToken;
                $expiresAt = $exchanged->expiresAt ?? $expiresAt;
                $refreshToken = $exchanged->refreshToken ?? $refreshToken;
                $refreshExpiresAt = $exchanged->refreshExpiresAt ?? $refreshExpiresAt;
            }
        }

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
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'renew_at' => $renewAt,
                'scopes' => $user->approvedScopes,
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
