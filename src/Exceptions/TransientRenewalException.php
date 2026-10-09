<?php

namespace Pr4w\SocialTokens\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Pr4w\SocialTokens\Models\SocialToken;
use RuntimeException;

/**
 * A renewal that failed for now (network, provider 5xx or 429, rate limit,
 * lock contention, an uncatalogued error). RenewCredential throws it so the
 * queue retries the job with backoff.
 *
 * Never reported to the exception handler: it is an expected outcome, and a
 * provider outage would otherwise send one error-tracker event per credential
 * per attempt. The trail stays in the log: uncatalogued errors are logged where
 * they are classified, and a credential that exhausts its retries is logged
 * once by RenewCredential::failed(), which also records it in last_error.
 */
class TransientRenewalException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?string $provider = null,
        public readonly int|string|null $tokenId = null,
    ) {
        parent::__construct('Transient renewal failure: '.($reason ?? 'unknown'));
    }

    public static function for(SocialToken $token, ?string $reason): self
    {
        return new self($reason, $token->provider, $token->getKey());
    }

    /**
     * @return array{provider: string|null, token_id: int|string|null}
     */
    public function context(): array
    {
        return ['provider' => $this->provider, 'token_id' => $this->tokenId];
    }
}
