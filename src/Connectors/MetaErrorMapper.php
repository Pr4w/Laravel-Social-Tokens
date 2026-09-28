<?php

namespace Pr4w\SocialTokens\Connectors;

use Illuminate\Support\Str;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Maps a Meta Graph error object to a transient or terminal renewal result.
 * Shared by the Facebook, Instagram and Threads connectors, which all return
 * errors in the shape
 * { "error": { "message", "type", "code", "error_subcode", "is_transient", "fbtrace_id" } }.
 *
 * Classification is by code, never by type: Meta sends plenty of errors that
 * have nothing to do with the token (rate limits above all) as "OAuthException".
 * Every result carries code, error_subcode, type, message and fbtrace_id in its
 * context, and the reason names the subcode ("190/460 OAuthException: ...").
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
     * @param  mixed  $error  The "error" value of the response; anything but an object is unexpected.
     */
    public static function map(mixed $error): RenewalResult
    {
        if (! is_array($error)) {
            return self::unexpectedShape($error);
        }

        [$code, $subcode, $reason, $context] = self::parse($error);

        if (in_array($code, self::INVALID_TOKEN_CODES, true) || in_array($subcode, self::SESSION_SUBCODES, true)) {
            return RenewalResult::terminalFailure($reason, $context);
        }

        // The token lost a permission renewal needs: only re-consent fixes it.
        if ($code === 10 || ($code >= 200 && $code <= 299)) {
            return RenewalResult::terminalFailure("Missing permission — {$reason}", $context);
        }

        if (($error['is_transient'] ?? false) === true
            || in_array($code, self::TRANSIENT_CODES, true)
            || ($code >= 80001 && $code <= 80014)) { // Business Use Case rate limits
            return RenewalResult::transientFailure($reason, $context);
        }

        return RenewalResult::unknownFailure($reason, $context);
    }

    /**
     * Classification for a credential health check (GET /me with a token that
     * is not being renewed), where a verdict cuts the account off. Stricter than
     * map(): only a session subcode is definitive; a bare 190/102 is terminal
     * but must be confirmed by a second run; a permission code on /me proves
     * nothing about the token and is only logged.
     */
    public static function mapCredentialCheck(mixed $error): RenewalResult
    {
        if (! is_array($error)) {
            return self::unexpectedShape($error);
        }

        [$code, $subcode, $reason, $context] = self::parse($error);

        if (in_array($subcode, self::SESSION_SUBCODES, true)) {
            return RenewalResult::terminalFailure($reason, $context + ['definitive' => true]);
        }

        if (in_array($code, self::INVALID_TOKEN_CODES, true)) {
            return RenewalResult::terminalFailure($reason, $context);
        }

        if ($code === 10 || ($code >= 200 && $code <= 299)) {
            return RenewalResult::unknownFailure("Missing permission — {$reason}", $context);
        }

        return self::map($error);
    }

    /**
     * @param  array<mixed>  $error
     * @return array{0: int, 1: ?int, 2: string, 3: array<string, mixed>}
     */
    private static function parse(array $error): array
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $type = (string) ($error['type'] ?? '');
        $message = (string) ($error['message'] ?? 'Unknown Meta error.');

        $codes = $subcode === null ? (string) $code : "{$code}/{$subcode}";

        return [$code, $subcode, trim("{$codes} {$type}: {$message}"), [
            'code' => $code,
            'error_subcode' => $subcode,
            'type' => $type,
            'message' => $message,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ]];
    }

    /** An OAuth-2-style string error, or anything else that is not a Graph error object. */
    private static function unexpectedShape(mixed $error): RenewalResult
    {
        $raw = is_scalar($error) ? (string) $error : get_debug_type($error);

        return RenewalResult::unknownFailure('Unexpected Meta error shape: '.Str::limit($raw, 200), ['error' => $raw]);
    }
}
