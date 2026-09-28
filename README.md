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

- a Meta user token backs every Instagram account for that user (and is kept,
  renewable, for a Facebook-only connection too: the Pages are minted from it)
- a LinkedIn member token backs every organization they administer
- a TikTok / Google account is 1:1 with its own credential

Renewal happens on the credential, **once** — every account sharing it sees the
fresh token. A Facebook page token is stored as a *static* credential (no expiry,
never auto-refreshed); everything else is renewable on its lead time.

## Install

Requires PHP 8.2+ (8.3+ on Laravel 13), Laravel 12 / 13, and Laravel Socialite 5.5+.

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
via its `credentials` key. Set a connector's `driver` to `null` to disable it:
new connections for that provider are then refused (`InvalidArgumentException`),
the dispatcher skips its existing credentials with a warning, and once one
expires `validAccessTokenFor()` throws a transient `NeedsReconnectException`.
Every provider you connect through the package needs a connector.

TikTok and Threads aren't built into `laravel/socialite` itself. Install their
drivers from [SocialiteProviders](https://socialiteproviders.com) (e.g.
`composer require socialiteproviders/tiktok`) and register the listener, so
`Socialite::driver('tiktok')` resolves. Their credentials still go in the same
`services.php` entries above.

## Connecting an account

Socialite fires no event when the user returns, so you call the action yourself
from your callback. Use **`StoreConnection`** for every provider — one callback
handles TikTok, Google, Instagram, Threads and Facebook, and the LinkedIn
personal profile (for LinkedIn organizations, see below).

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
  **static** page-token credential and keeps posting with it. A Facebook
  connection stores that same renewable user credential too (holder = Facebook
  user id), so a Facebook reconnect also refreshes the token the user's Instagram
  accounts post with. Its `accounts` relation can be empty (Pages point at their
  page tokens); find them by `provider_holder_id`. If you use distinct Facebook
  Login for Business configurations for Facebook and Instagram, check the
  Facebook flow's token also carries the `instagram_*` permissions, since it
  replaces the shared credential. Every account records the Facebook user id, so
  a reconnect flags targets the user no longer manages — scoped to that user, so a
  co-owner's are never touched. (Instagram and Facebook authenticate via the
  Facebook driver.)

Need finer control? `StoreConnection` delegates to lower-level actions you can
call directly: `StoreAccountFromSocialite` (single account), `StoreFacebookPages`
(page fan-out), and `StoreInstagramAccounts` (Instagram-account fan-out).

### LinkedIn: company pages

LinkedIn's token exchange is app-side (Socialite's driver can't fetch the profile
with the non-OIDC posting scopes). `StoreConnection` stores only the member's
personal profile (one row, through `StoreAccountFromSocialite`); it does not fan
out to organizations. You obtain the member token yourself, then fan out to the
organizations they administer with `StoreLinkedInOrganizations`:

```php
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;

// after your manual code -> token exchange and /me profile fetch:
$accounts = app(StoreLinkedInOrganizations::class)->handle(
    accessToken: $token['access_token'],
    memberId: $profile['id'],
    owner: auth()->user(),
    refreshToken: $token['refresh_token'] ?? null,
    expiresAt: isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
    scopes: explode(',', $token['scope'] ?? ''), // an empty string is stored as "unknown"
);
```

Every organization posts with the same member token, so each row mirrors it (the
organization URN is stored in `profile` for posting). Only organizations where
the member holds a posting role are stored — by default `ADMINISTRATOR`,
`CONTENT_ADMINISTRATOR`, `DIRECT_SPONSORED_CONTENT_POSTER` and
`RECRUITING_POSTER`, configurable with `connectors.linkedin.posting_roles`; an
organization the member now holds only as `ANALYST`, `CURATOR`, etc. is flagged
at the next connect. If you also post as the
member, store that as its own row with `StoreAccountFromSocialite`. It shares the
member's credential and `provider_holder_id` with the organizations, and
reconciliation excludes it by its `provider_user_id` (the member id), so it only
ever touches the organization rows.

## Renewing

A scheduled command scans `social_tokens` for credentials whose `renew_at` has
passed and dispatches one `RenewCredential` job per credential — so one renewal
keeps every account sharing that credential alive. Each provider declares its own
lead time, so a single command handles token lifetimes from one hour to sixty
days. The schedule is registered automatically; just run the Laravel scheduler.
Set `dispatch_schedule` to `null` to schedule the command yourself. Both scheduled
commands run `withoutOverlapping()->onOneServer()` (`check-static` also in the
background): on several servers, use a shared cache store (redis, memcached,
database, dynamodb) so that really runs once.

Renewal failures are classified:

- transient (network, provider 5xx, rate limit): retried with backoff while the
  expiry window is still open
- terminal (invalid_grant, revoked, refresh token expired): the credential moves
  to `needs_reconnect` and an event fires, no further attempts

A credential that cannot be renewed unattended (LinkedIn without refresh tokens,
or a refresh token past its own lifetime) is not cut off early: when its window
opens, `CredentialExpiringSoon` fires once with the expiry date, the credential
keeps posting, and it moves to `needs_reconnect` only if it actually expires
before the user reconnects. The same warning comes a lead time **before** a
refresh token dies (LinkedIn's live 365 days and are never extended), with the
date the credential really stops working, so the member can re-authorise in
time. A reconnect that brings a new refresh token re-arms it; storing a profile
without a refresh token never erases a known one.

Static credentials (Facebook page tokens) are never renewed, but die when the
user changes their password, removes the app or loses the Page's admin role. A
daily `social-tokens:check-static` run asks Meta about each one and flags the
dead ones `needs_reconnect` (set `check_static_schedule` to `null` to disable).
Because a verdict cuts the Page off, the run is cautious: a session error
("password changed", "app removed") flags at once, any other rejection must be
confirmed by the next day's run, a permission error on `/me` is only logged, and
when more than 20% of at least 10 checked tokens are rejected at once (a
Meta-side incident) nothing is flagged and a critical log is raised instead
(`check_static_breaker`). A credential that cannot be checked (e.g. it cannot be
decrypted after an `APP_KEY` change) is logged and skipped.

A custom connector opts in by implementing `Contracts\ChecksCredential`. Its
plain `terminalFailure()` needs two consecutive runs to flag; return
`terminalFailure($reason, ['definitive' => true])` to flag on the first.

Renewals run under a per-credential lock so a scheduled job and a synchronous
`validAccessTokenFor()` can never refresh the same credential at once (which would
break rotating-refresh-token providers like TikTok). This needs a cache store that
supports atomic locks: redis, memcached, database, dynamodb, or file.

## Posting

The publishing layer never touches refresh tokens. It asks for a valid access
token and gets one, or a clear signal to reconnect. If the job will hold the
token a while, say how long, and a token expiring sooner is renewed first:
`validAccessTokenFor($account, 20 * 60)` for an Instagram/Threads container poll
of up to 15 minutes. Keep it under the provider's token lifetime (1 hour for
Google), or every call refreshes.

```php
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;

try {
    $token = app(SocialTokens::class)->validAccessTokenFor($account);
    // ... call the provider API with $token
} catch (NeedsReconnectException $e) {
    if ($e->transient) {
        // the connection is fine, renewal just failed for now (network,
        // provider 5xx, lock contention): retry later, don't alarm the user
    } else {
        // prompt the user to reconnect $e->account in your UI
    }
}
```

When the provider rejects a token at publish time, report it, so every account
sharing that credential stops posting (and your app is told once):

```php
try {
    // ... call the provider API with $token
} catch (YourProviderError $e) {
    app(SocialTokens::class)->reportRejected(
        $account,
        $e->safeMessage(),                 // no secrets in the reason
        terminal: $e->isDeadToken(),       // Meta 190/102 or a session subcode, OAuth invalid_grant/invalid_token
        rejectedToken: $token,             // ignored if the user reconnected meanwhile
    );
}
```

With `terminal: false` (an ambiguous 401), the package first asks the provider
whether the token still works, and flags only if it confirms. To catch revoked
tokens between two renewals without waiting for a failed post, set
`SOCIAL_TOKENS_CHECK_RENEWABLE=true`: the daily `check-static` run then also
checks renewable Meta, Threads and LinkedIn credentials (LinkedIn uses
`services.linkedin` for token introspection).

## Account status

```
active ──(renew succeeds)──────────────────────> active
       ├─(cannot renew unattended)──> active + CredentialExpiringSoon ──(expires)──> needs_reconnect
       └─(terminal failure)──────────> needs_reconnect ──(user reconnects)──> active

revoked: terminal, set by disconnect() (one account) or revoke() (a whole login); never retried.
```

Status lives at two levels. A credential's status covers every account it backs
(its shared token died or was revoked); an account's own `status` column only
records account-level flags (e.g. a page the user no longer manages). When a
credential dies, its accounts' rows are **not** rewritten — so to show whether
an account can post, read its effective status, never the raw column:

```php
$account->effectiveStatus(); // most severe of the account's and its credential's status
$account->isUsable();        // true only when both are active

SocialAccount::usable()->get();   // accounts that can post
SocialAccount::unusable()->get(); // accounts that need attention, whatever the cause
```

Eager-load `credential` when listing accounts (`SocialAccount::with('credential')`)
to avoid one query per row.

Listen for `AccountConnected`, `CredentialRenewed`, `CredentialExpiringSoon`,
`CredentialNeedsReconnect`, `CredentialRevoked`, `AccountNeedsReconnect` and
`AccountRevoked` to drive
notifications and a reconnect button in your panel. A dead credential fires
`CredentialNeedsReconnect` once, not once per account: notify the user from that
event, listing the affected accounts through `$event->token->accounts`, so a user
with ten Instagram accounts or LinkedIn organizations behind one token gets one
message, not ten. Facebook Pages are the exception: each Page has its own static
credential, so `check-static` fires one event per dead Page. Group those
notifications yourself if you need to, e.g. by the accounts'
`provider_holder_id` (the Facebook user) or by owner.

To disconnect **one** account, call `disconnect()`. Accounts that share its
credential (the other Instagram accounts of the same Facebook user, a LinkedIn
member's personal profile and organizations) keep posting. The credential itself
is revoked, at the provider too, only when its last connected account goes:

```php
app(SocialTokens::class)->disconnect($account);
```

To disconnect a whole login (the credential and every account it backs), revoke
the credential:

```php
app(SocialTokens::class)->revoke($account->credential);
```

`$account->markRevoked()` only flags the account locally: no provider call, and
the credential is left as is.

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

`scopes` is `null` when they are **unknown** (the provider did not report them,
or `debug_token` failed) and a list when known — `[]` means none granted.
`grantedScopes()` treats unknown as none; call `$account->scopesKnown()` first if
you want to treat unknown differently. An unknown value never overwrites a known
list, and lists are normalised (trimmed, no blanks or duplicates). Threads never
reports scopes, so its rows stay `null` unless you write them. The account's
`profile` is merged, not replaced: keys you store there yourself survive a
reconnect. A Facebook Page listed without an `access_token` (the user has no
posting task on it) is not stored.

## Adding a provider

Create one class extending `AbstractConnector`, implement `refreshCredential()`
for that provider's exact refresh mechanism, declare its `renewalStrategy()` and
`leadTime()`, and register it under its key in the config. See `TikTokConnector`
for a complete reference. Nothing else in the package needs to change. Build
requests from `$this->http()` rather than the `Http` facade so they carry the
configured timeouts, and wrap them in `$this->attempt()` so network errors, 5xx
and 429 come back as transient failures.

Optional hooks (defaulted in `AbstractConnector`): `credentialProvider()` when the
credential is refreshed under another provider's key (Instagram returns
`facebook`), `exchangeForLongLived()` for a short-to-long token swap at connect,
and `revoke(SocialToken)` if the provider exposes a token-revocation endpoint.

### Using a connector on its own

`app(SocialTokens::class)->connector('tiktok')->refreshCredential(new SocialToken([...]))`
returns a `RenewalResult` and writes nothing. The lock, the double-check, the jobs
and `validAccessTokenFor()` need the package's tables; `renewCredential()` throws
for a token that was never saved.

## License

MIT.
