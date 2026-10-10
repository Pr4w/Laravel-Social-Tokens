<?php

namespace Pr4w\SocialTokens\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Pr4w\SocialTokens\Contracts\FansOutConnections;
use Pr4w\SocialTokens\Contracts\StoresConnections;
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
 * For a 1:1 provider (TikTok, Google, X, Threads, Instagram Login, a LinkedIn
 * personal profile) the credential and the account are one-to-one: this
 * creates a renewable SocialToken and a SocialAccount that posts with it. A
 * provider whose connections fan out (Facebook, Instagram through a Page) is
 * refused: StoreConnection sends it to its own action. A fan-out connector
 * whose connectionAction() names this action (a subclass of a Meta connector
 * that stores one account per connection) is accepted.
 */
class StoreAccountFromSocialite implements StoresConnections
{
    public function __construct(protected ConnectorRegistry $registry) {}

    /**
     * StoreConnection's entry point: the one account, in a collection. A
     * disconnected account is always restored: the user reconnects that very
     * account.
     *
     * @return Collection<int, SocialAccount>
     */
    public function storeConnection(
        string $provider,
        #[\SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
        bool $restoreDisconnected = false,
    ): Collection {
        return collect([$this->handle($provider, $user, $owner, $connectedBy, $longLived, $refreshExpiresAt)]);
    }

    /**
     * @param  CarbonInterface|null  $refreshExpiresAt  The refresh token's expiry, when you know it.
     *                                                  It is not on the Socialite contract: without it, it is
     *                                                  read from the token response of a driver that exposes
     *                                                  one (SocialiteProviders' accessTokenResponseBody), and
     *                                                  stays unknown with core Socialite's drivers (LinkedIn)
     *                                                  until the first renewal reports it.
     *
     * @throws InvalidArgumentException for a provider without a connector, or one whose connections fan
     *                                  out to another action.
     */
    public function handle(
        string $provider,
        #[\SensitiveParameter] SocialiteUser $user,
        ?Model $owner = null,
        ?Model $connectedBy = null,
        bool $longLived = true,
        ?CarbonInterface $refreshExpiresAt = null,
    ): SocialAccount {
        // Throws for a provider without a connector, before anything is written:
        // stored without one, the account would look static and break at expiry.
        $connector = $this->registry->for($provider);

        // Keyed on the Socialite user, a Facebook connection would become a
        // Page-less account (an Instagram one with the Facebook user's id),
        // and overwrite the shared Meta user credential with a short-lived
        // token. Unless the connector names this action itself: a subclass of
        // a Meta connector that stores one account per connection.
        if ($connector instanceof FansOutConnections && ! is_a($connector->connectionAction(), self::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Connections of [%s] fan out to several accounts: store them with StoreConnection, or %s.',
                $provider,
                class_basename($connector->connectionAction()),
            ));
        }

        $accessToken = $user->token;
        $refreshToken = $user->refreshToken ?: null;
        $expiresAt = $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null;

        // Otherwise from the token response, when the driver exposes it (TikTok
        // names it refresh_expires_in, LinkedIn refresh_token_expires_in).
        if ($refreshExpiresAt === null) {
            $refreshExpiresIn = data_get($user, 'accessTokenResponseBody.refresh_expires_in')
                ?? data_get($user, 'accessTokenResponseBody.refresh_token_expires_in');
            $refreshExpiresAt = $refreshExpiresIn ? now()->addSeconds((int) $refreshExpiresIn) : null;
        }

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

        // Never forget a known refresh token: a reconnect without one must not
        // wipe it or its expiry. The default credential: the LinkedIn
        // organizations have their own (StoreLinkedInOrganizations::PURPOSE).
        // One that no longer decrypts (written under an APP_KEY that is gone)
        // is known to nobody: forgotten, with its expiry.
        $existing = SocialToken::query()
            ->where('provider', $connector->credentialProvider())
            ->where('provider_holder_id', $user->getId())
            ->forPurpose()
            ->ownedBy($owner)
            ->first();

        if ($existing !== null && $existing->canDecrypt('refresh_token')) {
            $refreshToken ??= $existing->refresh_token;

            if ($refreshExpiresAt === null && $refreshToken === $existing->refresh_token) {
                $refreshExpiresAt = $existing->refresh_expires_at;
            }
        }

        // Unknown scopes (none reported) never overwrite a known list.
        $scopes = SocialToken::normaliseScopes($user->approvedScopes);
        $withScopes = $scopes === null ? [] : ['scopes' => $scopes];

        // The credential (holder = the account's own id for a 1:1 provider).
        $token = SocialToken::persistCredential(
            [
                'provider' => $connector->credentialProvider(),
                'provider_holder_id' => $user->getId(),
            ] + SocialAccount::ownerKey($owner), // one credential per owner: each keeps its own grant
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
            ] + $withScopes,
            $connector, // schedules renew_at
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

        // Reconnecting this very account undoes its disconnection.
        $account->clearDisconnected();

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
