<?php

namespace Pr4w\SocialTokens\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * Meta stops sharing the user's data with the app at $dataAccessExpiresAt
 * (data_access_expires_at, 90 days after the user was last active), although
 * the credential's token keeps working and renewing. Only a new login through
 * your app (a reconnect) moves it. Fired once per deadline, a lead time
 * before it (`data_access_warning`), for a credential an active account posts
 * with, and possibly after the date when it was learnt late.
 *
 * Warn-only: Meta does not say which calls a lapse blocks for an app with
 * business permissions (Instagram publishing included), so the credential is
 * not flagged and stays usable. If the provider then rejects a call, report it
 * with SocialTokens::reportRejected().
 */
class CredentialDataAccessExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SocialToken $token,
        public readonly CarbonInterface $dataAccessExpiresAt,
    ) {}
}
