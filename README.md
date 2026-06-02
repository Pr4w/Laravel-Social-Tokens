# Laravel Social Tokens

Persist, renew and manage OAuth social account tokens on top of Laravel Socialite.

Socialite is built for authentication: it gets you a token and a user, then stops.
This package owns what comes after: storing the token, keeping it alive across
very different provider refresh models, and surfacing the moment a human has to
reconnect.

## Why

Four platforms, four token models, and any naive "access token + refresh token +
cron" design breaks on at least one of them:

| Provider | Access token | Renewal model | Strategy |
|---|---|---|---|
| Google / YouTube | ~1h | refresh_token grant, refresh token reused | `StableRefreshToken` |
| TikTok | 24h | refresh_token grant, refresh token rotates | `RotatingRefreshToken` |
| Instagram | 60 days | extend the long lived token, no refresh token | `ExtendLongLived` |
| LinkedIn | 60 days | refresh token gated behind MDP, else re-auth | `ReauthOnly` |

The package models "how do I keep this token alive" as a per-provider strategy,
where one valid answer is "I cannot, the user must reconnect." That last case is
treated as an expected, scheduled event, not an error.

## Install

```bash
composer require pr4w/laravel-social-tokens
php artisan vendor:publish --tag=social-tokens-config
php artisan vendor:publish --tag=social-tokens-migrations
php artisan migrate
```

Set provider credentials in your `.env` and enable a connector in
`config/social-tokens.php` by setting its `driver`.

## Connecting an account

Socialite fires no event when the user returns, so you call the action yourself
from your callback. Request the publishing scopes, not just identity scopes.

```php
use Laravel\Socialite\Facades\Socialite;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

Route::get('/oauth/{provider}/redirect', function (string $provider, ConnectorRegistry $registry) {
    return Socialite::driver($provider)
        ->scopes($registry->for($provider)->publishingScopes())
        ->redirect();
});

Route::get('/oauth/{provider}/callback', function (string $provider, StoreAccountFromSocialite $store) {
    $user = Socialite::driver($provider)->user();

    $account = $store->handle(
        provider: $provider,
        user: $user,
        owner: auth()->user(), // or a Team, Workspace, or null
    );

    return redirect('/dashboard');
});
```

## Renewing

A scheduled command scans for accounts whose `renew_at` has passed and dispatches
one `RenewAccountToken` job per account. Each provider declares its own lead time,
so a single command handles token lifetimes from one hour to sixty days. The
schedule is registered automatically; just run the Laravel scheduler.

Renewal failures are classified:

- transient (network, provider 5xx, rate limit): retried with backoff while the
  expiry window is still open
- terminal (invalid_grant, revoked, refresh token expired): the account moves to
  `needs_reconnect` and an event fires, no further attempts

Renewals run under a per-account lock so a scheduled job and a synchronous
`validAccessTokenFor()` can never refresh the same account at once (which would
break rotating-refresh-token providers like TikTok). This needs a cache store
that supports atomic locks: redis, memcached, database, dynamodb, or file.

## Posting

The publishing layer never touches refresh tokens. It asks for a valid access
token and gets one, or a clear signal to reconnect.

```php
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;

try {
    $token = app(SocialTokens::class)->validAccessTokenFor($account);
    // ... call the provider API with $token
} catch (NeedsReconnectException $e) {
    // prompt the user to reconnect $e->account in your UI
}
```

## Account status

```
active -> expiring -> (renew) -> active
                   -> needs_reconnect -> (user reconnects) -> active
revoked
```

Listen for `AccountConnected`, `TokenRenewed`, `AccountNeedsReconnect`,
`AccountRevoked` to drive notifications and a reconnect button in your panel.

## Adding a provider

Create one class extending `AbstractConnector`, implement `renew()` for that
provider's exact mechanism, declare its `renewalStrategy()` and `leadTime()`, and
register it under its key in the config. See `TikTokConnector` for a complete
reference. Nothing else in the package needs to change.

## License

MIT.
