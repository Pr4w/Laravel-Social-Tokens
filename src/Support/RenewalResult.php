<?php

namespace Pr4w\SocialTokens\Support;

use Carbon\CarbonInterface;
use Pr4w\SocialTokens\Enums\RenewalOutcome;

/**
 * Immutable result of a connector renewal attempt. A connector never touches
 * the database; it returns one of these and the model applies it.
 */
final class RenewalResult
{
    private function __construct(
        public readonly RenewalOutcome $outcome,
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,   // null means keep the existing one
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly ?CarbonInterface $refreshExpiresAt = null,
        public readonly array $profile = [],
        public readonly ?string $reason = null,
    ) {
    }

    public static function success(
        string $accessToken,
        ?CarbonInterface $expiresAt = null,
        ?string $refreshToken = null,
        ?CarbonInterface $refreshExpiresAt = null,
        array $profile = [],
    ): self {
        return new self(
            outcome: RenewalOutcome::Success,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            refreshExpiresAt: $refreshExpiresAt,
            profile: $profile,
        );
    }

    public static function transientFailure(string $reason): self
    {
        return new self(outcome: RenewalOutcome::Transient, reason: $reason);
    }

    public static function terminalFailure(string $reason): self
    {
        return new self(outcome: RenewalOutcome::Terminal, reason: $reason);
    }

    public function succeeded(): bool
    {
        return $this->outcome === RenewalOutcome::Success;
    }
}
