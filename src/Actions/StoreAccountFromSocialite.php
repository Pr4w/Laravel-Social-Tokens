<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use RuntimeException;

/**
 * Call this from your OAuth callback controller after Socialite returns a user.
 * It is the integration point Socialite itself does not provide (Socialite
 * fires no event on return).
 *
 * For a 1:1 provider (TikTok, Google, standalone) the credential and the account
 * are one-to-one: this creates a renewable SocialToken and a SocialAccount that
 * posts with it.
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
        // Throws for a provider without a connector, before anything is written:
        // stored without one, the account would look static and break at expiry.
        $connector = $this->registry->for($provider);

        $accessToken = $user->token;
        $refreshToken = $user->refreshToken ?: null;
        $expiresAt = $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null;

        // The refresh token lifetime is not on the Socialite contract; read it
        // from the raw token response when the driver exposes it (TikTok names
        // it refresh_expires_in, LinkedIn refresh_token_expires_in).
        $refreshExpiresIn = data_get($user, 'accessTokenResponseBody.refresh_expires_in')
            ?? data_get($user, 'accessTokenResponseBody.refresh_token_expires_in');
        $refreshExpiresAt = $refreshExpiresIn ? now()->addSeconds((int) $refreshExpiresIn) : null;

        // Upgrade to a long-lived token where the provider needs a distinct
        // connect-time exchange (Threads). Providers whose connect token is
        // already durable return null and are stored as-is.
        if ($longLived) {
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

        // Never forget a known refresh token: storing a profile without one
        // (e.g. the LinkedIn personal row next to the organizations, which share
        // this credential) must not wipe it or its expiry.
        $existing = SocialToken::query()
            ->where('provider', $connector->credentialProvider())
            ->where('provider_holder_id', $user->getId())
            ->ownedBy($owner)
            ->first();

        if ($existing !== null) {
            $refreshToken ??= $existing->refresh_token;

            if ($refreshExpiresAt === null && $refreshToken === $existing->refresh_token) {
                $refreshExpiresAt = $existing->refresh_expires_at;
            }
        }

        $renewAt = SocialToken::renewAtFor($expiresAt, $connector, $refreshExpiresAt);

        // Unknown scopes (none reported) never overwrite a known list.
        $scopes = SocialToken::normaliseScopes($user->approvedScopes);
        $withScopes = $scopes === null ? [] : ['scopes' => $scopes];

        // The credential (holder = the account's own id for a 1:1 provider).
        $token = SocialToken::query()->updateOrCreate(
            [
                'provider' => $connector->credentialProvider(),
                'provider_holder_id' => $user->getId(),
            ] + SocialAccount::ownerKey($owner), // one credential per owner: each keeps its own grant
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'renew_at' => $renewAt,
                'last_renewed_at' => now(), // the token was just issued: its age starts here
                'status' => AccountStatus::Active,
                'last_error' => null,
            ] + $withScopes,
        );

        $account = SocialAccount::query()->updateOrCreate(
            [
                'provider' => $provider,
                'provider_user_id' => $user->getId(),
            ] + SocialAccount::ownerKey($owner), // one row per owner
            [
                'social_token_id' => $token->getKey(),
                'provider_holder_id' => $user->getId(),
                'name' => $user->getName(),
                'nickname' => $user->getNickname(),
                'email' => $user->getEmail(),
                'avatar' => $user->getAvatar(),
                'status' => AccountStatus::Active,
                'last_error' => null,
            ] + $withScopes,
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
