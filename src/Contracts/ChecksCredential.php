<?php

namespace Pr4w\SocialTokens\Contracts;

use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Optional connector capability: ask the provider whether a credential still
 * works, without changing it. Used for static credentials (e.g. Facebook page
 * tokens), which never expire and are never renewed, so nothing else would
 * notice when the provider invalidates them.
 */
interface ChecksCredential
{
    /**
     * Success when the provider still accepts the token; a terminal failure when
     * it has been invalidated. Must not write to the database.
     */
    public function checkCredential(SocialToken $token): RenewalResult;
}
