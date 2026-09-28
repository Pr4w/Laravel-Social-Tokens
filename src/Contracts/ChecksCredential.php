<?php

namespace Pr4w\SocialTokens\Contracts;

use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Optional connector capability: ask the provider whether a credential still
 * works, without changing it. Used for static credentials (e.g. Facebook page
 * tokens), which never expire and are never renewed, so nothing else would
 * notice when the provider invalidates them; for renewable credentials between
 * two renewals (`check_renewable`); and by SocialTokens::reportRejected() to
 * confirm an ambiguous rejection.
 *
 * Implement it only where a rejected access token means the credential cannot
 * recover without the user (Meta, Threads, LinkedIn) — not for providers whose
 * short-lived access tokens say nothing about the refresh token (TikTok, Google).
 * A plain terminalFailure() needs two consecutive checks to flag; add
 * ['definitive' => true] to its context to flag on the first.
 */
interface ChecksCredential
{
    /**
     * Success when the provider still accepts the token; a terminal failure when
     * it has been invalidated. Must not write to the database.
     */
    public function checkCredential(SocialToken $token): RenewalResult;
}
