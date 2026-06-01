<?php

namespace Pr4w\SocialTokens\Enums;

enum AccountStatus: string
{
    case Active = 'active';                 // token valid
    case Expiring = 'expiring';             // within the renewal window, renewal queued
    case NeedsReconnect = 'needs_reconnect'; // cannot renew unattended, user action required
    case Revoked = 'revoked';               // dead, will not be retried

    public function isUsable(): bool
    {
        return $this === self::Active || $this === self::Expiring;
    }
}
