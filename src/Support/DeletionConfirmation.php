<?php

namespace Pr4w\SocialTokens\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The confirmation code a data deletion callback answers Meta with, and the
 * status URL the user is shown. The code is signed with the app key and
 * carries its issue date, so the status page can confirm a code the package
 * issued without storing anything: the deletion is done by the time the code
 * is handed out.
 */
final class DeletionConfirmation
{
    public static function issue(string $provider): string
    {
        $payload = base_convert((string) now()->getTimestamp(), 10, 36).'-'.Str::lower(Str::random(12));

        return $payload.'-'.self::signature($provider, $payload);
    }

    /** When the package issued $code for $provider, or null for a code it did not issue. */
    public static function issuedAt(string $provider, string $code): ?CarbonInterface
    {
        if (! preg_match('/^([0-9a-z]{1,13})-([0-9a-z]{12})-([0-9a-f]{24})$/', $code, $parts)
            || ! hash_equals(self::signature($provider, "{$parts[1]}-{$parts[2]}"), $parts[3])) {
            return null;
        }

        return Carbon::createFromTimestamp((int) base_convert($parts[1], 36, 10), date_default_timezone_get());
    }

    /**
     * Where the user checks the request: `callbacks.deletion_status_url`
     * ({provider} replaced, and {code}, or ?code= appended when it has none),
     * else the package's own status route.
     *
     * @throws RuntimeException when neither exists: checked before anything is deleted.
     */
    public static function statusUrl(string $provider, string $code): string
    {
        $url = Settings::callbacks()['deletion_status_url'];

        if ($url !== null) {
            $url = strtr($url, ['{provider}' => rawurlencode($provider)]);

            return str_contains($url, '{code}')
                ? strtr($url, ['{code}' => rawurlencode($code)])
                : $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['code' => $code]);
        }

        if (! app('router')->has('social-tokens.callbacks.deletion-status')) {
            throw new RuntimeException('No data deletion status page: enable social-tokens.callbacks or set social-tokens.callbacks.deletion_status_url.');
        }

        return route('social-tokens.callbacks.deletion-status', ['provider' => $provider, 'code' => $code]);
    }

    private static function signature(string $provider, string $payload): string
    {
        return substr(hash_hmac('sha256', "social-tokens:deletion:{$provider}:{$payload}", (string) config('app.key')), 0, 24);
    }
}
