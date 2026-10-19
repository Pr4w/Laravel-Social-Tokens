<?php

namespace Pr4w\SocialTokens\Socialite;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use LogicException;
use Pr4w\SocialTokens\Connectors\PinterestConnector;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\Settings;
use RuntimeException;

/**
 * The `pinterest` Socialite driver: Pinterest API v5 OAuth 2.0, configured in
 * services.pinterest. SocialiteProviders' Pinterest driver identifies the
 * account by its username, which the member can change (killing its tokens);
 * this one uses the account's id.
 *
 *   Socialite::driver('pinterest')->scopes(['boards:read', 'pins:write'])->redirect();
 *   $user = Socialite::driver('pinterest')->user(); // a PinterestUser
 *
 * The code is exchanged where the PinterestConnector points (apiUrl()): the
 * Sandbox, whose tokens only work there, when its `sandbox` option is on.
 */
class PinterestProvider extends AbstractProvider
{
    /** Pinterest takes scopes comma-separated, and lists the granted ones with spaces. */
    protected $scopeSeparator = ',';

    /** @var array<int, string> */
    protected $scopes = ['user_accounts:read'];

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://www.pinterest.com/oauth/', $state);
    }

    protected function getTokenUrl()
    {
        return $this->connector()->apiUrl().'oauth/token';
    }

    /**
     * The client authenticates with HTTP Basic, and the body carries only the
     * grant. Through Laravel's HTTP client, with the package's timeouts.
     */
    public function getAccessTokenResponse($code)
    {
        $response = $this->http()
            ->asForm()
            ->withBasicAuth((string) $this->clientId, (string) $this->clientSecret)
            ->post($this->getTokenUrl(), $this->getTokenFields($code));

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new RuntimeException('Pinterest refused the authorization code'.self::describe($response->status(), $body).'.');
        }

        return $body;
    }

    /** @return array<string, mixed> */
    protected function getTokenFields($code)
    {
        return array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ], $this->parameters);
    }

    protected function getUserByToken($token)
    {
        $response = $this->http()->withToken($token)->get($this->connector()->apiUrl().'user_account');
        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw new RuntimeException('Could not read the Pinterest account'.self::describe($response->status(), $body).'.');
        }

        if (! is_scalar($body['id'] ?? null) || (string) $body['id'] === '') {
            throw new RuntimeException('Pinterest returned the account without its id.');
        }

        return $body;
    }

    /** @param  array<string, mixed>  $user */
    protected function mapUserToObject(array $user)
    {
        $username = is_string($user['username'] ?? null) ? $user['username'] : null;
        $businessName = is_string($user['business_name'] ?? null) && $user['business_name'] !== '' ? $user['business_name'] : null;

        return (new PinterestUser)->setRaw($user)->map([
            'id' => (string) $user['id'],
            'nickname' => $username,
            'name' => $businessName ?? $username,
            'email' => null,
            'avatar' => is_string($user['profile_image'] ?? null) ? $user['profile_image'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $user
     */
    protected function userInstance(array $response, array $user)
    {
        $instance = parent::userInstance($response, $user);

        $instance->setApprovedScopes(preg_split('/[\s,]+/', (string) Arr::get($response, 'scope', ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($instance instanceof PinterestUser) {
            $instance->accessTokenResponseBody = $response;
        }

        return $instance;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(Settings::httpTimeout())
            ->connectTimeout(Settings::connectTimeout())
            ->acceptJson();
    }

    protected function connector(): PinterestConnector
    {
        $connector = app(ConnectorRegistry::class)->for('pinterest');

        if (! $connector instanceof PinterestConnector) {
            throw new LogicException('The "pinterest" connector must be a PinterestConnector.');
        }

        return $connector;
    }

    /** " (HTTP 401, code 2: Authentication failed)": the status and Pinterest's error, never the body. */
    private static function describe(int $status, mixed $body): string
    {
        $code = is_array($body) && is_scalar($body['code'] ?? null) ? (string) $body['code'] : null;
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : null;

        return " (HTTP {$status}".($code !== null ? ", code {$code}" : '').($message !== null ? ": {$message}" : '').')';
    }
}
