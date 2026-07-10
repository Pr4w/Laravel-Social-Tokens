<?php

namespace Pr4w\SocialTokens\Enums;

enum AccountStatus: string
{
    case Active = 'active';                 // token valid (or renewable in the background)
    case NeedsReconnect = 'needs_reconnect'; // cannot renew unattended, user action required
    case Revoked = 'revoked';               // dead, will not be retried

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
