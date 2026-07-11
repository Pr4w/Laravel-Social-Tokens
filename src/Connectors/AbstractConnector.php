<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;
use Throwable;

abstract class AbstractConnector implements ProviderConnector
{
    /**
     * @param  array<string, mixed>  $config  The connector's config block.
     * @param  string|null  $provider  The provider key this connector was resolved under.
     */
    public function __construct(protected array $config = [], protected ?string $provider = null) {}

    /**
     * The connector that refreshes this provider's credential. Defaults to the
     * provider's own key; override where a credential is shared (Instagram).
     */
    public function credentialProvider(): string
    {
        return $this->provider ?? '';
    }

    /**
     * Client credentials default to Laravel Socialite's config/services.php, so
     * the app declares each id/secret once. An explicit client_id/client_secret
     * in the connector's own config block still wins. The services entry read
     * defaults to the provider key, but can be pointed elsewhere with a
     * 'credentials' key (Instagram and Facebook share one Meta app).
     */
    protected function clientId(): ?string
    {
        return $this->config['client_id']
            ?? config("services.{$this->credentialsKey()}.client_id");
    }

    protected function clientSecret(): ?string
    {
        return $this->config['client_secret']
            ?? config("services.{$this->credentialsKey()}.client_secret");
    }

    protected function credentialsKey(): string
    {
        return $this->config['credentials'] ?? $this->provider ?? '';
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

    public function exchangeForLongLived(string $accessToken): ?RenewalResult
    {
        // Most providers' connect token is already long lived. Override where a
        // distinct short-to-long exchange is required at connect.
        return null;
    }

    /**
     * Run an HTTP call and normalise the transport-level outcomes that are
     * identical for every provider: a dropped connection, an unexpected
     * exception, a provider 5xx or a 429 are all transient and retryable.
     *
     * Returns the Response for the connector to interpret. Provider-specific
     * error classification and success mapping stay in the connector — this
     * only owns the "is the transport itself healthy?" policy.
     *
     * @param  callable(): Response  $request
     */
    protected function attempt(callable $request): Response|RenewalResult
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            return RenewalResult::transientFailure('Connection error: '.$e->getMessage());
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.$e->getMessage());
        }

        if ($response->serverError() || $response->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$response->status());
        }

        return $response;
    }
}
