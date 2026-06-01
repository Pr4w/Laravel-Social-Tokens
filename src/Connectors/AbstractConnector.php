<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Models\SocialAccount;

abstract class AbstractConnector implements ProviderConnector
{
    /**
     * @param  array<string, mixed>  $config  The connector's config block.
     */
    public function __construct(protected array $config = [])
    {
    }

    protected function clientId(): ?string
    {
        return $this->config['client_id'] ?? null;
    }

    protected function clientSecret(): ?string
    {
        return $this->config['client_secret'] ?? null;
    }

    public function leadTime(): CarbonInterval
    {
        // Sensible default. Override per provider.
        return CarbonInterval::minutes(15);
    }

    public function revoke(SocialAccount $account): void
    {
        // No-op by default. Override where the provider exposes a revoke endpoint.
    }
}
