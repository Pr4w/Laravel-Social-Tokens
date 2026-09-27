<?php

namespace Pr4w\SocialTokens\Exceptions;

use Pr4w\SocialTokens\Models\SocialAccount;
use RuntimeException;

/**
 * No valid access token could be produced for the account. Check $transient
 * before telling the user anything: when true the connection is fine and the
 * renewal only failed for now (network, provider 5xx, lock contention) — retry
 * later instead of asking them to reconnect.
 */
class NeedsReconnectException extends RuntimeException
{
    public function __construct(
        public readonly SocialAccount $account,
        string $message = '',
        public readonly bool $transient = false,
    ) {
        parent::__construct($message ?: "Account [{$account->provider}#{$account->id}] needs to be reconnected.");
    }

    public static function for(SocialAccount $account, ?string $reason = null): self
    {
        return new self($account, $reason ?? '');
    }

    /**
     * The renewal failed for a reason that may clear on its own: the account
     * still works, this attempt just cannot post.
     */
    public static function transient(SocialAccount $account, ?string $reason = null): self
    {
        return new self(
            $account,
            $reason ?? "Account [{$account->provider}#{$account->id}] is temporarily unavailable; retry later.",
            transient: true,
        );
    }
}
