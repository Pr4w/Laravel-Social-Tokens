<?php

namespace Pr4w\SocialTokens\Support;

use Pr4w\SocialTokens\Enums\ProfileSyncOutcome;

/**
 * The result of syncing one account's profile: the outcome, the values the
 * update replaced, and why it failed or was skipped.
 */
final class ProfileSyncResult
{
    /**
     * @param  array<string, string|null>  $previous  attribute => the value it replaced
     */
    public function __construct(
        public readonly ProfileSyncOutcome $outcome,
        public readonly array $previous = [],
        public readonly ?string $reason = null,
    ) {}
}
