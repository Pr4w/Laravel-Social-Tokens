# Laravel Social Tokens

Persist, renew and manage OAuth social account tokens on top of Laravel Socialite.

Socialite is built for authentication: it gets you a token and a user, then stops.
This package owns what comes after: storing the token, keeping it alive across
very different provider refresh models, and surfacing the moment a human has to
reconnect.

## Why

Six providers across four renewal strategies, and any naive "access token +
refresh token + cron" design breaks on at least one of them:

| Provider | Access token | Renewal model | Strategy |
|---|---|---|---|
| Google / YouTube | ~1h | refresh_token grant, refresh token reused | `StableRefreshToken` |
| TikTok | 24h | refresh_token grant, refresh token rotates | `RotatingRefreshToken` |
| Facebook | Page token, no expiry | extend the 60-day user token, re-derive the page token | `RotatingRefreshToken` |
| Instagram | 60 days | extend the long lived token, no refresh token | `ExtendLongLived` |
| Threads | 60 days | extend the long lived token, no refresh token | `ExtendLongLived` |
| LinkedIn | 60 days | refresh token gated behind MDP, else re-auth | `ReauthOnly` |

The package models "how do I keep this token alive" as a per-provider strategy,
where one valid answer is "I cannot, the user must reconnect." That last case is
treated as an expected, scheduled event, not an error.

## Install

Requires PHP 8.2+, Laravel 11 / 12 / 13, and Laravel Socialite 5.5+.

```bash
composer require pr4w/laravel-social-tokens
php artisan vendor:publish --tag=social-tokens-config
php artisan vendor:publish --tag=social-tokens-migrations
php artisan migrate
```

### Credentials

Provider credentials live in Laravel Socialite's `config/services.php`, so you
declare each one once and both Socialite and this package read them. Instagram
and Facebook share a single Meta app:

```php
// config/services.php
'facebook' => ['client_id' => env('META_APP_ID'),        'client_secret' => env('META_APP_SECRET'),        'redirect' => env('META_REDIRECT')],
'threads'  => ['client_id' => env('THREADS_CLIENT_ID'),  'client_secret' => env('THREADS_CLIENT_SECRET'),  'redirect' => env('THREADS_REDIRECT')],
'tiktok'   => ['client_id' => env('TIKTOK_CLIENT_KEY'),  'client_secret' => env('TIKTOK_CLIENT_SECRET'),   'redirect' => env('TIKTOK_REDIRECT')],
'google'   => ['client_id' => env('GOOGLE_CLIENT_ID'),   'client_secret' => env('GOOGLE_CLIENT_SECRET'),   'redirect' => env('GOOGLE_REDIRECT')],
'linkedin' => ['client_id' => env('LINKEDIN_CLIENT_ID'), 'client_secret' => env('LINKEDIN_CLIENT_SECRET'), 'redirect' => env('LINKEDIN_REDIRECT')],
```

`config/social-tokens.php` then only enables connectors (their `driver`) and
carries non-secret options — Instagram points at the `facebook` services entry
via its `credentials` key. Set a connector's `driver` to `null` to disable it.

TikTok and Threads aren't built into `laravel/socialite` itself. Install their
drivers from [SocialiteProviders](https://socialiteproviders.com) (e.g.
`composer require socialiteproviders/tiktok`) and register the listener, so
`Socialite::driver('tiktok')` resolves. Their credentials still go in the same
`services.php` entries above.

## Connecting an account

Socialite fires no event when the user returns, so you call the action yourself
from your callback. Use **`StoreConnection`** for every provider — one callback
handles TikTok, Google, Instagram, Threads, LinkedIn and Facebook.

Which scopes to request is your app's decision (they drive the consent screen
and your provider app review), so you supply them at redirect — the package does
not. Request the publishing scopes, not just identity scopes.

```php
use Laravel\Socialite\Facades\Socialite;
use Pr4w\SocialTokens\Actions\StoreConnection;

// Keep your scope lists wherever suits your app — config, a constant, etc.
$scopes = [
    'tiktok' => ['user.info.basic', 'video.publish'],
    'google' => ['openid', 'https://www.googleapis.com/auth/youtube.upload'],
    // ...one entry per provider you support
];

Route::get('/oauth/{provider}/redirect', function (string $provider) use ($scopes) {
    return Socialite::driver($provider)
        ->scopes($scopes[$provider] ?? [])
        ->redirect();
});

Route::get('/oauth/{provider}/callback', function (string $provider, StoreConnection $store) {
    $user = Socialite::driver($provider)->user();

    $accounts = $store->handle(
        provider: $provider,
        user: $user,
        owner: auth()->user(), // or a Team, Workspace, or null
    );

    return redirect('/dashboard');
});
```

`handle()` always returns a `Collection<SocialAccount>` — a connection can yield
more than one account. Most providers give exactly one, so use
`$accounts->sole()` if you want the single row; Facebook gives one per page.

### What `StoreConnection` handles for you

- **Long-lived tokens.** Threads hands back a short-lived token, which is upgraded
  to its long-lived (~60 day) form before storing so the row is renewable.
  LinkedIn, TikTok and Google are already durable and stored as-is. Pass
  `longLived: false` to skip the upgrade.
- **Instagram & Facebook fan-out.** These publish per Instagram account / per
  Page, but the only renewable credential is the long-lived Facebook **User**
  token. So a connect fans out to one row per target: Instagram gives one row per
  linked Instagram Business account (plus its companion Page), Facebook gives one
  per managed Page. Each stores the postable token in `access_token` and, for
  pages, the shared user token in `refresh_token`; `expires_at` tracks the user
  token so the scheduler re-extends it before it lapses. Every row records the
  Facebook user id in `provider_holder_id`, so a reconnect flags targets the user
  no longer manages — scoped to that user, so a co-owner's are never touched.
  (Instagram and Facebook authenticate via the Facebook driver.)

Need finer control? `StoreConnection` delegates to lower-level actions you can
call directly: `StoreAccountFromSocialite` (single account), `StoreFacebookPages`
(page fan-out), and `StoreInstagramAccounts` (Instagram-account fan-out).

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
active ──(renew succeeds)──────────────────────> active
       └─(cannot renew, or terminal failure)──> needs_reconnect ──(user reconnects)──> active

revoked: terminal, set explicitly when you revoke an account; never retried.
```

Listen for `AccountConnected`, `TokenRenewed`, `AccountNeedsReconnect`,
`AccountRevoked` to drive notifications and a reconnect button in your panel.

## Adding a provider

Create one class extending `AbstractConnector`, implement `renew()` for that
provider's exact mechanism, declare its `renewalStrategy()` and `leadTime()`, and
register it under its key in the config. See `TikTokConnector` for a complete
reference. Nothing else in the package needs to change.

Two hooks are optional (no-op by default in `AbstractConnector`): override
`exchangeForLongLived()` if the provider needs a short-to-long token swap at
connect, and `revoke()` if it exposes a token-revocation endpoint.

## License

MIT.
