<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pr4w\SocialTokens\Models\SocialAccount;

class AccountNeedsReconnect
{
    use Dispatchable;

    public function __construct(
        public readonly SocialAccount $account,
        public readonly ?string $reason = null,
    ) {
    }
}
