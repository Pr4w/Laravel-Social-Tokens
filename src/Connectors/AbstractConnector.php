<?php

namespace Pr4w\SocialTokens\Connectors;

use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ProviderConnector;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;
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

    /**
     * Whether an OAuth error description is about the member's refresh token
     * (terminal) rather than the app's client (a configuration problem).
     */
    protected function isAboutRefreshToken(string $description): bool
    {
        return (bool) preg_match('/refresh[\s_-]?token/i', $description);
    }

    /**
     * How one stored credential is kept alive. The package asks this, not
     * renewalStrategy(), wherever it decides about a credential: the renewal
     * job, validAccessTokenFor(), the schedule a connect or a renewal writes.
     * Defaults to renewalStrategy(); override where a credential can lack what
     * that strategy needs (LinkedIn issues refresh tokens per app).
     */
    public function renewalStrategyFor(SocialToken $token): RenewalStrategy
    {
        return $this->renewalStrategy();
    }

    public function leadTime(): CarbonInterval
    {
        // Sensible default. Override per provider.
        return CarbonInterval::minutes(15);
    }

    public function revoke(SocialToken $token): void
    {
        // No-op by default. Override where the provider exposes a revoke endpoint.
    }

    /**
     * Log a provider-side revocation that threw. revoke() must never throw, so
     * an override catches every Throwable and reports it here. Reading the
     * token belongs inside that try too: it decrypts, and fails after an
     * APP_KEY change.
     */
    protected function logRevokeFailure(SocialToken $token, Throwable $e): void
    {
        Log::warning("[social-tokens] {$token->provider} token revocation failed", [
            'provider' => $token->provider,
            'token_id' => $token->getKey(),
            'exception' => $e::class,
            'message' => static::redactQueryStrings($e->getMessage()),
        ]);
    }

    public function exchangeForLongLived(#[\SensitiveParameter] string $accessToken): ?RenewalResult
    {
        // Most providers' connect token is already long lived. Override where a
        // distinct short-to-long exchange is required at connect.
        return null;
    }

    /**
     * The base request every provider call starts from. The timeouts keep a
     * slow provider from outliving the 60s renewal lock, which would let a
     * second process refresh the same credential (fatal with TikTok's
     * single-use refresh tokens). Laravel's default is a 30s timeout and no
     * connect timeout.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(Settings::httpTimeout())
            ->connectTimeout(Settings::connectTimeout());
    }

    /**
     * Guzzle ends transport error messages with the full request URI, query
     * string included, and Meta calls carry client_secret and user tokens
     * there. Keep scheme, host and path for debugging; drop the whole query
     * (an allow-list: a secret parameter added later cannot leak either).
     */
    public static function redactQueryStrings(#[\SensitiveParameter] string $message): string
    {
        return preg_replace('~(https?://[^\s?#]+)\?[^\s#]*~i', '$1?[redacted]', $message) ?? '[redacted]';
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
            return RenewalResult::transientFailure('Connection error: '.static::redactQueryStrings($e->getMessage()));
        } catch (Throwable $e) {
            return RenewalResult::transientFailure('Unexpected error: '.static::redactQueryStrings($e->getMessage()));
        }

        if ($response->serverError() || $response->status() === 429) {
            return RenewalResult::transientFailure('Provider returned HTTP '.$response->status());
        }

        return $response;
    }
}
