<?php

namespace Pr4w\SocialTokens\Enums;

/**
 * What syncing one account's profile did (Actions\SyncAccountProfile).
 */
enum ProfileSyncOutcome
{
    // The provider's profile differed: the account was updated.
    case Updated;

    // The provider's profile matches the stored one.
    case Unchanged;

    // The token was not granted what the profile needs: nothing to read.
    case Unavailable;

    // The provider could not be read (an outage, an unexpected answer).
    case Failed;

    // Not synced: the account is not active, has no usable credential, needs
    // a reconnect, or its connector cannot read profiles.
    case Skipped;
}
