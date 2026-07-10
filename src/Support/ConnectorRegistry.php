<?php

namespace Pr4w\SocialTokens\Support;

use InvalidArgumentException;
use Pr4w\SocialTokens\Contracts\ProviderConnector;

/**
 * Resolves a ProviderConnector instance for a given provider key, using the
 * "connectors" config map. Instances are cached for the request.
 */
class ConnectorRegistry
{
    /** @var array<string, ProviderConnector> */
    protected array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    public function __construct(protected array $config)
    {
    }

    public function for(string $provider): ProviderConnector
    {
        if (isset($this->resolved[$provider])) {
            return $this->resolved[$provider];
        }

        $entry = $this->config[$provider] ?? null;

        if ($entry === null) {
            throw new InvalidArgumentException("No connector configured for provider [{$provider}].");
        }

        $driver = $entry['driver'] ?? null;

        if ($driver === null || ! class_exists($driver)) {
            throw new InvalidArgumentException("No connector driver implemented for provider [{$provider}].");
        }

        return $this->resolved[$provider] = new $driver($entry, $provider);
    }

    public function has(string $provider): bool
    {
        return ! empty($this->config[$provider]['driver'] ?? null);
    }

    /** @return array<int, string> */
    public function configured(): array
    {
        return array_keys(array_filter(
            $this->config,
            fn ($entry) => ! empty($entry['driver'] ?? null),
        ));
    }
}
