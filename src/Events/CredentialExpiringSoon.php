<?php

namespace Pr4w\SocialTokens\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * The credential still works but cannot be renewed unattended, so it will stop
 * at $expiresAt unless the user reconnects before then (e.g. LinkedIn without
 * refresh tokens, or a refresh token past its own lifetime). Fired once per
 * expiry, ahead of it by the provider's lead time. Nothing is blocked yet:
 * CredentialNeedsReconnect follows only if the token actually expires.
 */
class CredentialExpiringSoon
{
    use Dispatchable;

    public function __construct(
        public readonly SocialToken $token,
        public readonly CarbonInterface $expiresAt,
        public readonly ?string $reason = null,
    ) {}
}
