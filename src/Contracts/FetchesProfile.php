<?php

namespace Pr4w\SocialTokens\Contracts;

use Closure;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\AccountProfile;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Optional connector capability: read an account's profile (name, handle,
 * picture) as the provider shows it now. social-tokens:sync-profiles keeps the
 * stored ones current with it (Actions\SyncAccountProfile): names change, and
 * Meta, LinkedIn and TikTok hand out picture URLs that are signed and expire.
 */
interface FetchesProfile
{
    /**
     * The account's current profile. $accessToken hands out a valid token of
     * the credential the account posts with (SocialTokens::validAccessTokenFor(),
     * so it may renew it, or throw NeedsReconnectException): call it only when
     * the profile needs one. Null when the token was not granted what the
     * profile needs (a scope the app did not request): the stored profile
     * stays. A failure is a RenewalResult and says nothing about the token: a
     * profile read never flags a credential. Must not write to the database.
     *
     * @param  Closure(): string  $accessToken
     */
    public function fetchProfile(SocialAccount $account, Closure $accessToken): AccountProfile|RenewalResult|null;
}
