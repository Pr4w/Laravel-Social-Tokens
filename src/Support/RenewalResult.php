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
    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly RenewalOutcome $outcome,
        #[\SensitiveParameter] public readonly ?string $accessToken = null,
        #[\SensitiveParameter] public readonly ?string $refreshToken = null,   // null means keep the existing one
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly ?CarbonInterface $refreshExpiresAt = null,
        public readonly array $profile = [],
        public readonly ?string $reason = null,
        public readonly bool $unknown = false,
        public readonly array $context = [],
        public readonly bool $clientError = false,
        public readonly bool $neverExpires = false,
        public readonly ?CarbonInterface $dataAccessExpiresAt = null,
    ) {}

    /**
     * $neverExpires: the provider says the access token does not expire (Meta's
     * debug_token `expires_at: 0`), as opposed to an expiry it did not report.
     * Only read with no $expiresAt. Such a credential is stored without a
     * renewal date (static), where check-static keeps an eye on it.
     *
     * $dataAccessExpiresAt: when the provider stops sharing the user's data
     * with the app although the token still works (Meta's
     * data_access_expires_at). Stored when given; null keeps the stored one.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function success(
        #[\SensitiveParameter] string $accessToken,
        ?CarbonInterface $expiresAt = null,
        #[\SensitiveParameter] ?string $refreshToken = null,
        ?CarbonInterface $refreshExpiresAt = null,
        array $profile = [],
        bool $neverExpires = false,
        ?CarbonInterface $dataAccessExpiresAt = null,
    ): self {
        return new self(
            outcome: RenewalOutcome::Success,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            refreshExpiresAt: $refreshExpiresAt,
            profile: $profile,
            neverExpires: $neverExpires && $expiresAt === null,
            dataAccessExpiresAt: $dataAccessExpiresAt,
        );
    }

    /**
     * @param  array<string, mixed>  $context  Catalogued detail; does not make the failure "unknown".
     */
    public static function transientFailure(string $reason, array $context = []): self
    {
        return new self(outcome: RenewalOutcome::Transient, reason: $reason, context: $context);
    }

    /**
     * @param  array<string, mixed>  $context  Catalogued detail; does not make the failure "unknown".
     *                                         `definitive => true` lets a credential check flag at once.
     */
    public static function terminalFailure(string $reason, array $context = []): self
    {
        return new self(outcome: RenewalOutcome::Terminal, reason: $reason, context: $context);
    }

    /**
     * An error the connector did not recognise. Behaves as transient for control
     * flow (safe default: retry), but is flagged and carries context so it gets
     * logged centrally and can be catalogued into an explicit terminal/transient
     * case later.
     *
     * @param  array<string, mixed>  $context
     */
    public static function unknownFailure(string $reason, array $context = []): self
    {
        return new self(
            outcome: RenewalOutcome::Transient,
            reason: $reason,
            unknown: true,
            context: $context,
        );
    }

    /**
     * The provider rejected the APP's OAuth client (wrong or missing id/secret,
     * deleted client, client not allowed the grant). The member can do nothing
     * about it, and reconnecting would go through the same broken client: it is
     * transient for control flow and never escalated to needs_reconnect. It is
     * catalogued (not unknown); the operator is alerted by a critical log.
     *
     * @param  array<string, mixed>  $context
     */
    public static function clientFailure(string $reason, array $context = []): self
    {
        return new self(outcome: RenewalOutcome::Transient, reason: $reason, context: $context, clientError: true);
    }

    public function succeeded(): bool
    {
        return $this->outcome === RenewalOutcome::Success;
    }
}
