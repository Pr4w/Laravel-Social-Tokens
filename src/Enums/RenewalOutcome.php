<?php

namespace Pr4w\SocialTokens\Enums;

/**
 * The outcome of a single renewal attempt.
 */
enum RenewalOutcome
{
    // New token obtained.
    case Success;

    // Recoverable failure (network error, provider 5xx, rate limit). Retry later.
    case Transient;

    // Unrecoverable failure (invalid_grant, revoked, refresh token expired). Reconnect needed.
    case Terminal;
}
