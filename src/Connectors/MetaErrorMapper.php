<?php

namespace Pr4w\SocialTokens\Connectors;

use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Maps a Meta Graph error object to a transient or terminal renewal result.
 * Shared by the Facebook, Instagram and Threads connectors, which all return
 * errors in the shape
 * { "error": { "message", "type", "code", "error_subcode", "is_transient", "fbtrace_id" } }.
 *
 * Classification is by code, never by type: Meta sends plenty of errors that
 * have nothing to do with the token (rate limits above all) as "OAuthException".
 */
final class MetaErrorMapper
{
    /** The token itself is invalid, expired or revoked. */
    private const INVALID_TOKEN_CODES = [190, 102];

    /** Session subcodes: expired, password changed, app removed, logged out... */
    private const SESSION_SUBCODES = [458, 459, 460, 463, 464, 467, 492];

    /** Temporary errors and rate limits (app, user, page, custom). */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 368, 613];

    /**
     * @param  array<string, mixed>  $error
     */
    public static function map(array $error): RenewalResult
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $type = (string) ($error['type'] ?? '');
        $message = (string) ($error['message'] ?? 'Unknown Meta error.');
        $reason = trim("{$code} {$type}: {$message}");

        if (in_array($code, self::INVALID_TOKEN_CODES, true) || in_array($subcode, self::SESSION_SUBCODES, true)) {
            return RenewalResult::terminalFailure($reason);
        }

        // The token lost a permission renewal needs: only re-consent fixes it.
        if ($code === 10 || ($code >= 200 && $code <= 299)) {
            return RenewalResult::terminalFailure("Missing permission — {$reason}");
        }

        if (($error['is_transient'] ?? false) === true
            || in_array($code, self::TRANSIENT_CODES, true)
            || ($code >= 80001 && $code <= 80014)) { // Business Use Case rate limits
            return RenewalResult::transientFailure($reason);
        }

        return RenewalResult::unknownFailure($reason, [
            'code' => $code,
            'error_subcode' => $subcode,
            'type' => $type,
            'message' => $message,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ]);
    }
}
