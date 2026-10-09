<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Models\SocialAccount;

class AccountNeedsReconnect
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SocialAccount $account,
        public readonly ?string $reason = null,
    ) {}
}
