<?php

namespace Pr4w\SocialTokens\Exceptions;

use Pr4w\SocialTokens\Models\SocialAccount;
use RuntimeException;

class NeedsReconnectException extends RuntimeException
{
    public function __construct(public readonly SocialAccount $account, string $message = '')
    {
        parent::__construct($message ?: "Account [{$account->provider}#{$account->id}] needs to be reconnected.");
    }

    public static function for(SocialAccount $account, ?string $reason = null): self
    {
        return new self($account, $reason ?? '');
    }
}
