<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provider asked for an external user's data to be deleted (Meta's Data
 * Deletion Request callback, Threads' Delete callback), and the package has
 * deleted their tokens (SocialTokens::purgeExternalUser()). The accounts in
 * $accountIds are revoked and kept, without a credential: delete or
 * anonymise them, and whatever else your app keeps about this user, here.
 * $confirmationCode is what Meta was answered, for your records.
 *
 * Carries ids, not models: a listener may delete the rows.
 */
class ExternalUserDataDeleted
{
    use Dispatchable;

    /**
     * @param  array<int, int|string>  $accountIds
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $confirmationCode,
        public readonly array $accountIds,
    ) {}
}
