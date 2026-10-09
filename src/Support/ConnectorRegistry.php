<?php

namespace Pr4w\SocialTokens\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pr4w\SocialTokens\Contracts\ProviderConnector;

/**
 * Resolves a ProviderConnector instance for a given provider key, using the
 * "connectors" config map. Instances are cached for the request.
 *
 * has() and configured() answer "can for() resolve it?", so every guard that
 * checks them before calling for() holds: a driver naming a class that does
 * not exist (a renamed custom connector) or is not a connector counts as no
 * connector, exactly like `driver => null`, and is logged once as an error.
 * Connecting still calls for() directly and fails loudly.
 */
class ConnectorRegistry
{
    /** @var array<string, ProviderConnector> */
    protected array $resolved = [];

    /** @var array<string, true> Providers already logged as unresolvable. */
    protected array $reported = [];

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    public function __construct(protected array $config) {}

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

        if (! is_string($driver) || ! class_exists($driver)) {
            throw new InvalidArgumentException("No connector driver implemented for provider [{$provider}].");
        }

        if (! is_subclass_of($driver, ProviderConnector::class)) {
            throw new InvalidArgumentException(
                "Connector driver [{$driver}] for provider [{$provider}] must implement ProviderConnector."
            );
        }

        return $this->resolved[$provider] = new $driver($entry, $provider);
    }

    /** Whether for() can resolve a connector for the provider. */
    public function has(string $provider): bool
    {
        return isset($this->resolved[$provider]) || $this->resolvable($provider);
    }

    /**
     * The providers for() can resolve.
     *
     * @return array<int, string>
     */
    public function configured(): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($this->config)),
            fn (string $provider) => $this->has($provider),
        ));
    }

    protected function resolvable(string $provider): bool
    {
        $driver = $this->config[$provider]['driver'] ?? null;

        if (empty($driver)) {
            return false; // disabled on purpose
        }

        if (is_string($driver) && class_exists($driver) && is_subclass_of($driver, ProviderConnector::class)) {
            return true;
        }

        if (! isset($this->reported[$provider])) {
            $this->reported[$provider] = true;

            Log::error("[social-tokens] The connector driver for provider [{$provider}] does not name a ProviderConnector class; the provider is treated as having no connector.", [
                'provider' => $provider,
                'driver' => is_string($driver) ? $driver : get_debug_type($driver),
            ]);
        }

        return false;
    }
}
