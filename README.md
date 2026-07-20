# Laravel Social Tokens

[![Tests](https://github.com/Pr4w/Laravel-Social-Tokens/actions/workflows/tests.yml/badge.svg)](https://github.com/Pr4w/Laravel-Social-Tokens/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/pr4w/laravel-social-tokens)](https://packagist.org/packages/pr4w/laravel-social-tokens)
[![License](https://img.shields.io/packagist/l/pr4w/laravel-social-tokens)](LICENSE)

Persist, renew and manage OAuth social account tokens on top of Laravel Socialite.

Socialite is built for authentication: it gets you a token and a user, then stops.
This package owns what comes after: storing the token, keeping it alive across
very different provider refresh models, and surfacing the moment a human has to
reconnect.

## Part of a suite

This is the **connect** layer of a set of packages for working with social
accounts. Each works on its own; together they cover connect → publish → measure:

- **Laravel Social Tokens** (this package) — connect accounts and keep their tokens alive.
- [Laravel Social Poster](https://github.com/Pr4w/Laravel-Social-Poster) — publish content to the connected accounts.
- [Laravel Social Metrics](https://github.com/Pr4w/Laravel-Social-Metrics) — pull analytics for them.

## Why

Six providers across four renewal strategies, and any naive "access token +
refresh token + cron" design breaks on at least one of them:

| Provider | Access token | Renewal model | Strategy |
|---|---|---|---|
| Google / YouTube | ~1h | refresh_token grant, refresh token reused | `StableRefreshToken` |
| TikTok | 24h | refresh_token grant, refresh token rotates | `RotatingRefreshToken` |
| Facebook | Page token, no expiry | page token stored static; the shared user token is extended | `ExtendLongLived` |
| Instagram | 60 days | extend the long lived user token, no refresh token | `ExtendLongLived` |
| Threads | 60 days | extend the long lived token, no refresh token | `ExtendLongLived` |
| LinkedIn | 60 days | refresh token gated behind MDP, else re-auth | `ReauthOnly` |

The package models "how do I keep this token alive" as a per-provider strategy,
where one valid answer is "I cannot, the user must reconnect." That last case is
treated as an expected, scheduled event, not an error.

## How it's stored

Two tables. **`social_tokens`** holds the renewable **credential** — the thing
that actually gets refreshed. **`social_accounts`** holds the postable identities,
each pointing at the credential it posts with. One credential can back many
accounts:

- a Meta user token backs every Facebook Page and Instagram account for that user
- a LinkedIn member token backs every organization they administer
- a TikTok / Google account is 1:1 with its own credential

Renewal happens on the credential, **once** — every account sharing it sees the
fresh token. A Facebook page token is stored as a *static* credential (no expiry,
never auto-refreshed); everything else is renewable on its lead time.

## Install

Requires PHP 8.2+, Laravel 12 / 13, and Laravel Socialite 5.5+.

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
  Page. A connect fans out to one account row per target, each pointing at a
  credential: Instagram accounts **share one renewable** Meta user credential
  (refresh it once, they all stay alive); each Facebook Page gets its own
  **static** page-token credential. Every account records the Facebook user id, so
  a reconnect flags targets the user no longer manages — scoped to that user, so a
  co-owner's are never touched. (Instagram and Facebook authenticate via the
  Facebook driver.)

Need finer control? `StoreConnection` delegates to lower-level actions you can
call directly: `StoreAccountFromSocialite` (single account), `StoreFacebookPages`
(page fan-out), and `StoreInstagramAccounts` (Instagram-account fan-out).

### LinkedIn: company pages

LinkedIn's token exchange is app-side (Socialite's driver can't fetch the profile
with the non-OIDC posting scopes), so `StoreConnection` doesn't route it. You
obtain the member token yourself, then fan out to the organizations they
administer with `StoreLinkedInOrganizations`:

```php
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;

// after your manual code -> token exchange and /me profile fetch:
$accounts = app(StoreLinkedInOrganizations::class)->handle(
    accessToken: $token['access_token'],
    memberId: $profile['id'],
    owner: auth()->user(),
    refreshToken: $token['refresh_token'] ?? null,
    expiresAt: isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
);
```

Every organization posts with the same member token, so each row mirrors it (the
organization URN is stored in `profile` for posting). If you also post as the
member, store that as its own row with `StoreAccountFromSocialite` — it carries no
`provider_holder_id`, so reconciliation only ever touches the organization rows.

## Renewing

A scheduled command scans `social_tokens` for credentials whose `renew_at` has
passed and dispatches one `RenewCredential` job per credential — so one renewal
keeps every account sharing that credential alive. Each provider declares its own
lead time, so a single command handles token lifetimes from one hour to sixty
days. The schedule is registered automatically; just run the Laravel scheduler.

Renewal failures are classified:

- transient (network, provider 5xx, rate limit): retried with backoff while the
  expiry window is still open
- terminal (invalid_grant, revoked, refresh token expired): the credential moves
  to `needs_reconnect` and an event fires, no further attempts

Renewals run under a per-credential lock so a scheduled job and a synchronous
`validAccessTokenFor()` can never refresh the same credential at once (which would
break rotating-refresh-token providers like TikTok). This needs a cache store that
supports atomic locks: redis, memcached, database, dynamodb, or file.

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

Listen for `AccountConnected`, `CredentialRenewed`, `CredentialNeedsReconnect`,
`CredentialRevoked`, `AccountNeedsReconnect` and `AccountRevoked` to drive
notifications and a reconnect button in your panel. The credential events cover
every account a credential backs (its shared token died / was revoked); the
account events are per account (e.g. a page the user no longer manages).

To disconnect an account, revoke its credential — this tells the provider to
invalidate it and marks the credential and its accounts revoked:

```php
app(SocialTokens::class)->revoke($account->credential);
```

## Scopes

The scopes granted to each account are recorded on the row, so you can check —
per account — whether it can do what you need before relying on it:

```php
$account->grantedScopes();                       // string[] — the granted scopes
$account->hasScope('pages_manage_posts');        // bool — is this one scope granted?
$account->hasScopes(['pages_manage_posts', 'pages_read_engagement']); // bool — are ALL granted?
$account->missingScopes(['pages_manage_posts']); // string[] — the requested scopes NOT granted
```

For Meta these scopes are recorded **per account**, not per token: a user can
grant a scope for some Pages or Instagram accounts and not others (granular
permissions), so `StoreFacebookPages` and `StoreInstagramAccounts` resolve each
row's real scopes via `debug_token`. That lets you decide whether a given account
has everything it needs — and skip or warn on the ones that fall short.

## Adding a provider

Create one class extending `AbstractConnector`, implement `refreshCredential()`
for that provider's exact refresh mechanism, declare its `renewalStrategy()` and
`leadTime()`, and register it under its key in the config. See `TikTokConnector`
for a complete reference. Nothing else in the package needs to change.

Optional hooks (defaulted in `AbstractConnector`): `credentialProvider()` when the
credential is refreshed under another provider's key (Instagram returns
`facebook`), `exchangeForLongLived()` for a short-to-long token swap at connect,
and `revoke(SocialToken)` if the provider exposes a token-revocation endpoint.

## License

MIT.
