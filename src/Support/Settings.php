<?php

namespace Pr4w\SocialTokens\Support;

/**
 * Typed reads of the package config, normalised in one place. A published
 * config may hold any form Laravel accepts elsewhere (`'backoff' => 60`, a
 * `'false'` string, a numeric string), and a value of the wrong type must not
 * throw deep inside the dispatcher or a queued job, where a crash stops every
 * renewal. Defaults live here too: a published file replaces each top-level
 * key whole, so a nested key it leaves out is simply missing.
 *
 * Read on every call, never cached: apps (and tests) change config at runtime,
 * and a long-running worker picks up what the current process sees.
 */
final class Settings
{
    /** @var list<int> */
    public const DEFAULT_BACKOFF = [60, 300, 900];

    public const DEFAULT_TRIES = 4;

    /** Attempts per renewal job. 0 means unlimited, as for any Laravel job. */
    public static function tries(): int
    {
        return max(0, self::int(config('social-tokens.queue.tries'), self::DEFAULT_TRIES));
    }

    /**
     * Seconds to wait before each retry, as a list. Accepts a single value
     * (`60`) or a comma-separated string (`'60,300'`), like a job's own
     * `$backoff`: the last value repeats for later retries.
     *
     * @return list<int>
     */
    public static function backoff(): array
    {
        $value = config('social-tokens.queue.backoff') ?? self::DEFAULT_BACKOFF;

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_map(
            fn (mixed $seconds) => max(0, self::int($seconds, 0)),
            is_array($value) ? $value : [$value],
        ));
    }

    public static function httpTimeout(): int|float
    {
        return self::number(config('social-tokens.http.timeout'), 15);
    }

    public static function connectTimeout(): int|float
    {
        return self::number(config('social-tokens.http.connect_timeout'), 5);
    }

    public static function logUnknownErrors(): bool
    {
        return self::bool('log_unknown_errors', true);
    }

    public static function checkRenewable(): bool
    {
        return self::bool('check_renewable', false);
    }

    /**
     * The static check's safety breaker. At least one credential must have
     * been checked before the ratio means anything (a minimum of 0 would
     * divide by zero on a run that checked none).
     *
     * @return array{min_checked: int, max_terminal_ratio: float}
     */
    public static function staticCheckBreaker(): array
    {
        $breaker = config('social-tokens.check_static_breaker');
        $breaker = is_array($breaker) ? $breaker : [];

        return [
            'min_checked' => max(1, self::int($breaker['min_checked'] ?? null, 10)),
            'max_terminal_ratio' => (float) self::number($breaker['max_terminal_ratio'] ?? null, 0.2),
        ];
    }

    /**
     * A missing key takes the default; null reads as false, as the plain
     * truthiness test this replaces did; anything unreadable takes the default.
     */
    private static function bool(string $key, bool $default): bool
    {
        $value = config("social-tokens.{$key}", $default);

        if (is_array($value) || is_object($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function number(mixed $value, int|float $default): int|float
    {
        return is_numeric($value) ? $value + 0 : $default;
    }
}
