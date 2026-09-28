<?php

namespace Pr4w\SocialTokens\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Pr4w\SocialTokens\Models\SocialToken;

/**
 * The credential still works but cannot be renewed unattended, so it will stop
 * at $expiresAt unless the user reconnects before then: LinkedIn without
 * refresh tokens, a refresh token past (or near the end of) its own lifetime,
 * or a long-lived Meta/Threads token whose renewal did not extend it. Fired once
 * per expiry. Nothing is blocked yet: CredentialNeedsReconnect follows only if
 * the token actually expires.
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
