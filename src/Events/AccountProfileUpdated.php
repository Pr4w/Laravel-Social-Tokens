<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Models\SocialAccount;

/**
 * An account's name, handle or picture changed at its provider, and the
 * account now holds the new values. $previous maps each changed attribute to
 * the value it replaced. A picture URL from Meta, LinkedIn or TikTok is
 * signed and changes on its own: copy the image when you need it to last.
 */
class AccountProfileUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, string|null>  $previous
     */
    public function __construct(
        public readonly SocialAccount $account,
        public readonly array $previous,
    ) {}
}
