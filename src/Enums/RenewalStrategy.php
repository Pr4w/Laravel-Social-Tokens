<?php

namespace Pr4w\SocialTokens\Enums;

/**
 * How a given provider keeps a token alive. Every provider falls into one of
 * these, including the case where no unattended renewal is possible.
 */
enum RenewalStrategy: string
{
    // Refresh via refresh_token grant; provider returns a NEW refresh token each time. (TikTok)
    case RotatingRefreshToken = 'rotating_refresh_token';

    // Refresh via refresh_token grant; the existing refresh token is reused. (Google / YouTube)
    case StableRefreshToken = 'stable_refresh_token';

    // No refresh token. The long lived access token is extended in place. (Instagram)
    case ExtendLongLived = 'extend_long_lived';

    // No unattended renewal possible. The user must re-authorise. (LinkedIn without MDP)
    case ReauthOnly = 'reauth_only';

    public function canRenewUnattended(): bool
    {
        return $this !== self::ReauthOnly;
    }
}
