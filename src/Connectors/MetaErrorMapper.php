<?php

namespace Pr4w\SocialTokens\Connectors;

use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Maps a Meta Graph error object to a transient or terminal renewal result.
 * Shared by the Instagram and Threads connectors, which both return errors in
 * the shape { "error": { "message", "type", "code", "error_subcode" } }.
 */
final class MetaErrorMapper
{
    public static function map(array $error): RenewalResult
    {
        $code = (int) ($error['code'] ?? 0);
        $type = (string) ($error['type'] ?? '');
        $message = (string) ($error['message'] ?? 'Unknown Meta error.');

        // OAuthException (code 190) and its subcodes mean the token is invalid,
        // expired, or revoked: the user must reconnect.
        $terminal = $type === 'OAuthException' || in_array($code, [190, 102, 10, 200], true);

        return $terminal
            ? RenewalResult::terminalFailure(trim("{$code} {$type}: {$message}"))
            : RenewalResult::transientFailure(trim("{$code} {$type}: {$message}"));
    }
}
