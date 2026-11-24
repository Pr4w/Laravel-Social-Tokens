<?php

namespace Pr4w\SocialTokens\Actions;

use Pr4w\SocialTokens\Contracts\FetchesProfile;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\ProfileSyncOutcome;
use Pr4w\SocialTokens\Events\AccountProfileUpdated;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\AccountProfile;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\ProfileSyncResult;
use Pr4w\SocialTokens\Support\RenewalResult;
use Throwable;

/**
 * Bring an account's name, handle and picture up to date with its provider
 * (Contracts\FetchesProfile). social-tokens:sync-profiles runs it daily for
 * every active account; call it yourself to refresh one now.
 *
 * It only ever writes those three attributes, and only values the provider
 * gave: a blank one never erases a stored one, and the account's identity
 * (its ids, its owner) and status are never touched. A profile it cannot
 * read changes nothing and flags nothing: whether the token still works is
 * the renewal's and check-static's business. Nor does it renew a token: an
 * account whose access token has expired (or does not decrypt) is skipped,
 * and waits for the renewal. AccountProfileUpdated fires when something
 * changed.
 */
class SyncAccountProfile
{
    public function __construct(protected ConnectorRegistry $registry) {}

    public function handle(SocialAccount $account): ProfileSyncResult
    {
        if ($account->status !== AccountStatus::Active || $account->social_token_id === null) {
            return new ProfileSyncResult(ProfileSyncOutcome::Skipped, reason: 'The account is not active.');
        }

        if (! $this->registry->has($account->provider)) {
            return new ProfileSyncResult(ProfileSyncOutcome::Skipped, reason: "No connector for [{$account->provider}].");
        }

        $connector = $this->registry->for($account->provider);

        if (! $connector instanceof FetchesProfile) {
            return new ProfileSyncResult(ProfileSyncOutcome::Skipped, reason: "The [{$account->provider}] connector does not read profiles.");
        }

        try {
            $profile = $connector->fetchProfile($account, fn (): string => $this->storedAccessToken($account));
        } catch (NeedsReconnectException $e) {
            return new ProfileSyncResult(ProfileSyncOutcome::Skipped, reason: $e->getMessage() ?: 'No usable access token.');
        } catch (Throwable $e) {
            return new ProfileSyncResult(ProfileSyncOutcome::Failed, reason: 'Unexpected error: '.$e::class);
        }

        if ($profile === null) {
            return new ProfileSyncResult(ProfileSyncOutcome::Unavailable);
        }

        if ($profile instanceof RenewalResult) {
            return new ProfileSyncResult(ProfileSyncOutcome::Failed, reason: $profile->reason);
        }

        return $this->apply($account, $profile);
    }

    /**
     * The access token stored on the account's credential, as it is: a read
     * must not renew it (that is the renewal's job, under its lock). Throws a
     * transient NeedsReconnectException when there is none to use now: the
     * credential is not active, its token does not decrypt, or it has
     * expired.
     */
    protected function storedAccessToken(SocialAccount $account): string
    {
        $credential = $account->credential()->first();

        if ($credential === null || $credential->status !== AccountStatus::Active || ! $credential->tokensDecrypt() || blank($credential->access_token)) {
            throw NeedsReconnectException::transient($account, 'No usable access token.');
        }

        if ($credential->isAccessTokenExpired()) {
            throw NeedsReconnectException::transient($account, 'The access token has expired: left to the renewal.');
        }

        return (string) $credential->access_token;
    }

    protected function apply(SocialAccount $account, AccountProfile $profile): ProfileSyncResult
    {
        $changes = array_filter(
            $profile->attributes(),
            fn (string $value, string $attribute) => $account->getAttribute($attribute) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            return new ProfileSyncResult(ProfileSyncOutcome::Unchanged);
        }

        // Only while the account is still active: a disconnect or a flag
        // during the read wins.
        $updated = SocialAccount::query()
            ->whereKey($account->getKey())
            ->where('status', AccountStatus::Active->value)
            ->update($changes);

        if ($updated === 0) {
            return new ProfileSyncResult(ProfileSyncOutcome::Skipped, reason: 'The account changed during the sync.');
        }

        $previous = [];

        foreach (array_keys($changes) as $attribute) {
            $previous[$attribute] = $account->getAttribute($attribute);
        }

        $account->forceFill($changes)->syncOriginalAttributes(array_keys($changes));

        event(new AccountProfileUpdated($account, $previous));

        return new ProfileSyncResult(ProfileSyncOutcome::Updated, $previous);
    }
}
