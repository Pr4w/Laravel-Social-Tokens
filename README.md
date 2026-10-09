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

Eight providers across four renewal strategies, and any naive "access token +
refresh token + cron" design breaks on at least one of them:

| Provider | Access token | Renewal model | Strategy |
|---|---|---|---|
| Google / YouTube | ~1h | refresh_token grant, refresh token reused | `StableRefreshToken` |
| TikTok | 24h | refresh_token grant, refresh token rotates | `RotatingRefreshToken` |
| X (Twitter) | 2h | refresh_token grant, single-use refresh token rotates | `RotatingRefreshToken` |
| Facebook | Page token, no expiry | page token stored static; the shared user token is extended | `ExtendLongLived` |
| Instagram (through a Facebook Page) | 60 days, or no expiry | extend the long lived user token, no refresh token | `ExtendLongLived` |
| Instagram (Instagram Login, no Page) | 60 days | extend the long lived token (`ig_refresh_token`), no refresh token | `ExtendLongLived` |
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
- a LinkedIn member token backs every organization they administer (the
  member's personal profile has its own credential, from its own authorization)
- a TikTok / Google account is 1:1 with its own credential

Renewal happens on the credential, **once** — every account sharing it sees the
fresh token. A Facebook page token is stored as a *static* credential (no expiry,
never auto-refreshed); everything else is renewable on its lead time.

Tokens are stored encrypted with your `APP_KEY` and hidden from `toArray()`. The
package marks every parameter that carries one `#[\SensitiveParameter]` and
encrypts tokens before writing them, so none of its frames, nor the database
writes it makes, put a token in an exception trace. Set
`zend.exception_ignore_args=On` in production to cover code outside the package
too.

**Rotating `APP_KEY`.** Put the old key in `APP_PREVIOUS_KEYS` when you set the
new one: tokens still decrypt, and each is re-encrypted with the new key when
it is written again. Some never are (a Google refresh token, a Facebook Page
token), so run `php artisan social-tokens:reencrypt` before you drop the old
key. It rewrites every token not under the current key, never over a token
renewed meanwhile, and can be run again. It exits with a failure code, and
logs the credentials, when some token decrypts with none of the keys: keep the
previous key until those users reconnect.

### Owners

Identity is scoped to the **owner** you pass when connecting (`owner:` — a User,
a Team, a Workspace…): an account is unique per *(provider, external id,
owner)* and a credential per *(provider, external holder, purpose, owner)*. So
two owners can connect the same Facebook Page, Instagram account, LinkedIn
organization or TikTok account: each gets its own row, backed by its own
credential — the grant its own user gave — and one owner's reconnect, revoke or
disconnect never moves or overwrites the other's. Reconnecting for the same
owner (or without an owner) updates the existing row in place.

Because one external account can now have several rows, scope lookups to the
owner:

```php
SocialAccount::ownedBy($workspace)->where('provider', 'facebook')->where('provider_user_id', $pageId)->first();
SocialToken::ownedBy($workspace)->where('provider', 'tiktok')->where('provider_holder_id', $openId)->forPurpose()->first();
SocialAccount::ownedBy(null); // the owner-less rows
```

The **purpose** keeps apart the credentials one external holder grants the same
owner through different flows, so that one flow never replaces another's token:
`''` (`SocialToken::DEFAULT_PURPOSE`) for the default credential, and
`StoreLinkedInOrganizations::PURPOSE` (`'organizations'`) for the LinkedIn member
token behind the organizations, next to the member's personal profile. Name it
when you look a credential up by its holder (`forPurpose()` is the default one):

```php
SocialToken::ownedBy($workspace)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)
    ->forPurpose(StoreLinkedInOrganizations::PURPOSE)->first(); // the organizations' credential
```

What stays per **external user**, across owners, because that is how the
providers grant access:

- **Reconciliation.** When a Facebook user no longer manages a Page (or a
  LinkedIn member no longer administers an organization), every row of that user
  is flagged, whichever owner connected it: Meta and LinkedIn grant access per
  user, not per connection.
- **Provider-side revocation.** Revoking a token at Google or TikTok can end
  that user's whole consent for your app, so `revoke()` / `disconnect()` only
  call the provider once no other credential (another owner's, or another
  purpose's) is active for the same external user; until then the revoke is
  local.

A connection with an owner never adopts an existing owner-less row: it creates
its own. To attach an owner-less row, `associate()` it yourself.

**Deleting an owner.** The owner columns carry no foreign key, so a deleted
workspace would leave its accounts active, its credentials renewed, and each
of them counting as a grant that keeps other owners' revocations local. Call
`forgetOwner()` before deleting it: it revokes every credential of the owner
(the provider is called unless another owner still holds a grant for the same
external user), revokes its accounts, deletes both and returns the deleted
accounts. Deal with your own rows that point at those accounts first.

```php
static::deleting(fn (Workspace $workspace) => app(SocialTokens::class)->forgetOwner($workspace));
// with SoftDeletes, use forceDeleting: a restored workspace keeps its connections
```

`CredentialRevoked` and `AccountRevoked` fire before the rows are deleted, so a
queued listener finds them gone.

The owner is a polymorphic relation (`ownable_type`, `ownable_id`), and both
columns are part of the unique keys:

- **Key type.** `ownable_id` is created as an integer unless you declare uuid
  or ulid owners before the first `migrate` (see [Install](#install)). A uuid
  owner stored in an integer column fails on insert. The credentials' owner
  column takes the type of the accounts' one.
- **Morph map.** Without one, `ownable_type` stores the owner's class name, so
  renaming or moving that class orphans its rows. Register an alias with
  `Relation::morphMap(['workspace' => Workspace::class])` before the first
  connection: a row keeps the type it was written with, and `ownedBy()` looks
  for the current one.

## Install

Requires PHP 8.2+ (8.3+ on Laravel 13), Laravel 12 / 13, and Laravel Socialite 5.5+.
Upgrading? See [UPGRADE.md](UPGRADE.md).

```bash
composer require pr4w/laravel-social-tokens
php artisan vendor:publish --tag=social-tokens-config
php artisan vendor:publish --tag=social-tokens-migrations
php artisan migrate
```

**Owners with uuid or ulid keys:** declare it before the first `migrate`, so
the owner columns are created to match. Either app-wide, in a service
provider's `boot()`:

```php
use Illuminate\Support\Facades\Schema;

Schema::morphUsingUuids(); // or Schema::morphUsingUlids()
```

This applies to every morph column created afterwards, not just the owner:
`connected_by` (the user passed as `connectedBy`), Sanctum's `tokenable`,
notifications' `notifiable`. If your users keep integer keys, declare it for
the owner only instead: publish the migrations and, in
`2025_01_01_000000_create_social_accounts_table.php`, change
`nullableMorphs('ownable')` to `nullableUuidMorphs('ownable')` (or
`nullableUlidMorphs('ownable')`). The credentials' owner columns follow the
accounts' ones.

The migrations work on MySQL, PostgreSQL and SQLite, with integer, uuid or ulid
owner keys. They load from the package whether or not you publish them; if you
keep published copies, publish again after an upgrade to add the new files.

### Credentials

Provider credentials live in Laravel Socialite's `config/services.php`, so you
declare each one once and both Socialite and this package read them. Instagram
and Facebook share a single Meta app:

```php
// config/services.php
'facebook' => ['client_id' => env('META_APP_ID'),        'client_secret' => env('META_APP_SECRET'),        'redirect' => env('META_REDIRECT')],
'instagram_login' => ['client_id' => env('INSTAGRAM_APP_ID'), 'client_secret' => env('INSTAGRAM_APP_SECRET'), 'redirect' => env('INSTAGRAM_REDIRECT')],
'threads'  => ['client_id' => env('THREADS_CLIENT_ID'),  'client_secret' => env('THREADS_CLIENT_SECRET'),  'redirect' => env('THREADS_REDIRECT')],
'tiktok'   => ['client_id' => env('TIKTOK_CLIENT_KEY'),  'client_secret' => env('TIKTOK_CLIENT_SECRET'),   'redirect' => env('TIKTOK_REDIRECT')],
'google'   => ['client_id' => env('GOOGLE_CLIENT_ID'),   'client_secret' => env('GOOGLE_CLIENT_SECRET'),   'redirect' => env('GOOGLE_REDIRECT')],
'linkedin' => ['client_id' => env('LINKEDIN_CLIENT_ID'), 'client_secret' => env('LINKEDIN_CLIENT_SECRET'), 'redirect' => env('LINKEDIN_REDIRECT')],
'x'        => ['client_id' => env('X_CLIENT_ID'),        'client_secret' => env('X_CLIENT_SECRET'),        'redirect' => env('X_REDIRECT')],
```

`config/social-tokens.php` then only enables connectors (their `driver`) and
carries non-secret options — Instagram points at the `facebook` services entry
via its `credentials` key. Set a connector's `driver` to `null` to disable it:
new connections for that provider are then refused (`InvalidArgumentException`),
the dispatcher skips its existing credentials with a warning, and once one
expires `validAccessTokenFor()` throws a transient `NeedsReconnectException`.
A `driver` naming a class that does not exist or does not implement
`ProviderConnector` is handled the same way, and logged as an error so the
typo does not go unnoticed. Every provider you connect through the package
needs a connector.

TikTok, Threads and Instagram Login aren't built into `laravel/socialite` itself. Install their
drivers from [SocialiteProviders](https://socialiteproviders.com) (e.g.
`composer require socialiteproviders/tiktok`) and register the listener, so
`Socialite::driver('tiktok')` resolves. Their credentials still go in the same
`services.php` entries above.

X uses Socialite's own `x` driver (laravel/socialite 5.16+), with the **OAuth 2.0**
Client ID and Secret of a "Web App" (confidential client) whose User
authentication settings allow Read and write. OAuth 1.0a is not supported.

## Connecting an account

Socialite fires no event when the user returns, so you call the action yourself
from your callback. Use **`StoreConnection`** for every provider — one callback
handles TikTok, Google, X, Instagram (through a Facebook Page, or with Instagram
Login), Threads and Facebook, and the LinkedIn personal profile (for LinkedIn
organizations, see below).

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
    'x' => ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'],
    // ...one entry per provider you support
];

Route::get('/oauth/{provider}/redirect', function (string $provider) use ($scopes) {
    $driver = Socialite::driver($provider)->scopes($scopes[$provider] ?? []);

    // Google only issues a refresh token for offline access, and only on the
    // consent screen: without these, the credential cannot be renewed.
    if ($provider === 'google') {
        $driver->with(['access_type' => 'offline', 'prompt' => 'consent']);
    }

    return $driver->redirect();
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

### X (Twitter)

Request `offline.access`, or X issues no refresh token and the credential dies
after two hours. Its refresh tokens are **single-use**: every renewal returns a
new one, which the package stores under the renewal lock, so never refresh an X
token outside the package. X also keeps **one live grant per app and X user**:
if the same X account authorises your app again (another owner, another
environment sharing the same X app), the previous tokens die, and that credential
is flagged `needs_reconnect` at its next renewal. The package renews each X
credential about every 90 minutes, which also keeps its ~6-month refresh token
alive; with many X accounts, keep X's token-endpoint rate limits in mind.

### Instagram without a Facebook Page

An Instagram professional account (business or creator) that is not linked to
a Facebook Page connects through the **Instagram API with Instagram Login**,
under its own connector key, `instagram_login`. The Facebook Login path
(`instagram`) finds Instagram accounts through the Pages a Facebook user
manages, so it cannot reach these.

- **Socialite.** Use a driver that completes Business Login for Instagram
  (`https://www.instagram.com/oauth/authorize`, scopes such as
  `instagram_business_basic` and `instagram_business_content_publish`), and
  pass `provider: 'instagram_login'` to `StoreConnection`, whatever the
  driver's own name. The connector reads the **Instagram** app id and secret
  (App Dashboard, Instagram > API setup with Instagram login), from
  `services.instagram_login`; if your driver reads another services entry,
  point the connector at it with `'credentials' => 'instagram'`.
- **What is stored.** One account per connection (`provider` `instagram_login`,
  the Instagram-scoped user id), on its own renewable credential. The 1-hour
  token Socialite returns is exchanged for the 60-day one
  (`ig_exchange_token`), which is extended a week before it expires
  (`ig_refresh_token`, once it is at least 24 hours old). `check-static` with
  `check_renewable` asks `graph.instagram.com/me` about it.
- **Publishing.** Call `graph.instagram.com` with the token, not
  `graph.facebook.com`. Code that picks Instagram accounts by
  `provider = 'instagram'` must include `instagram_login`.

### Meta: Require App Secret

Every Graph call the package makes with a user or Page token (`/me`,
`/me/accounts`) carries `appsecret_proof`, the HMAC-SHA256 of that token keyed
with `services.facebook.client_secret`, as Socialite's Facebook driver already
does. You can therefore turn on **Require App Secret** in the Meta app's
advanced settings: a Page or user token that leaks is then useless without the
app secret. Sign your own publishing calls the same way:

```php
$proof = app(SocialTokens::class)->connector('instagram')->appSecretProof($token); // or 'facebook'

Http::withToken($token)->post("https://graph.facebook.com/v23.0/{$igUserId}/media", array_filter([
    'image_url' => $imageUrl,
    'appsecret_proof' => $proof, // null when signing is off
]));
```

A proof only matches tokens issued to the configured Meta app. Set
`META_APPSECRET_PROOF=false` (the `appsecret_proof` option of the `facebook` and
`instagram` connectors) to stop sending it, for example while tokens issued by
a former Meta app are still stored. Threads calls (`graph.threads.net`) and
Instagram Login calls (`graph.instagram.com`) are not signed.

### What `StoreConnection` handles for you

- **Long-lived tokens.** Threads and Instagram Login hand back a short-lived
  token, which is upgraded to its long-lived (~60 day) form before storing so
  the row is renewable, and so is the Facebook user token behind Facebook and
  Instagram (`fb_exchange_token`). LinkedIn, TikTok, Google and X are already
  durable and stored as-is. Pass `longLived: false` to skip the upgrade, only
  with a token that already is long-lived: Facebook Page tokens minted from a
  short-lived user token stop working within hours.
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
  a reconnect flags targets that user no longer manages — scoped to that user, so
  another Facebook user's rows are never touched (see "Owners" for rows of the
  same user under another owner). (Instagram and Facebook authenticate via the
  Facebook driver.)

Need finer control? `StoreConnection` delegates to lower-level actions you can
call directly: `StoreAccountFromSocialite` (single account), `StoreFacebookPages`
(page fan-out), and `StoreInstagramAccounts` (Instagram-account fan-out). The
connector picks the action: one whose connections fan out implements
`Contracts\FansOutConnections` and names its own, and
`StoreAccountFromSocialite` refuses it (`InvalidArgumentException`).

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
    refreshExpiresAt: isset($token['refresh_token_expires_in']) ? now()->addSeconds($token['refresh_token_expires_in']) : null,
    scopes: explode(',', $token['scope'] ?? ''), // an empty string is stored as "unknown"
);
```

To re-sync the list later (to pick up new Pages), call `handle()` again with the
member token stored on the organizations' credential and no `expiresAt`: the
token's expiry, its renewal schedule and its issue date are kept. Pass
`expiresAt` whenever the token is new.

```php
$credential = $organizationAccount->credential; // any of the member's organization rows, or:
$credential = SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)
    ->forPurpose(StoreLinkedInOrganizations::PURPOSE)->first();

app(StoreLinkedInOrganizations::class)->handle(
    accessToken: $credential->access_token,
    memberId: $memberId,
    owner: $owner,
);
```

Take it from that credential, not from a lookup by member id alone: a member
who also connected their profile has a second credential (see below), whose
token cannot post as the organizations. Passed here, it would be taken for a
new token and replace theirs, with no expiry: without a refresh token, that
credential would then never be warned about before it dies.

Every organization posts with the same member token, so each row mirrors it (the
organization URN is stored in `profile` for posting). Only organizations where
the member holds a posting role are stored — by default `ADMINISTRATOR`,
`CONTENT_ADMINISTRATOR`, `DIRECT_SPONSORED_CONTENT_POSTER` and
`RECRUITING_POSTER`, configurable with `connectors.linkedin.posting_roles`; an
organization the member now holds only as `ANALYST`, `CURATOR`, etc. is flagged
at the next connect.

The member token is stored on the member's **organizations credential**
(purpose `StoreLinkedInOrganizations::PURPOSE`). If you also post as the
member, store the personal profile with `StoreConnection` (or
`StoreAccountFromSocialite`): it gets its own credential, the default one, from
its own authorization. Neither flow ever replaces the other's token, refresh
token or scopes, so connecting the profile after the Pages (or the other way
round) leaves both posting. They also renew, warn (`CredentialExpiringSoon`) and
get flagged separately, and each is reconnected through its own flow: read
`$event->token->purpose` to send the member to the right one. The personal row
still carries the member id as `provider_holder_id`, and reconciliation
excludes it by its `provider_user_id` (the member id), so it only ever touches
the organization rows.

LinkedIn documents that authorising the same LinkedIn app again with a
different set of scopes invalidates the member's earlier access tokens. If both
flows go through one LinkedIn app, request the same scopes in both (the union),
or use a separate app for each; otherwise the first flow's token stops working
when the member goes through the second one.

## Renewing

A scheduled command scans `social_tokens` for credentials whose `renew_at` has
passed and dispatches one `RenewCredential` job per credential — so one renewal
keeps every account sharing that credential alive. Each provider declares its own
lead time, so a single command handles token lifetimes from one hour to sixty
days. The schedule is registered automatically; just run the Laravel scheduler.
`dispatch_schedule` (every 15 minutes), `check_static_schedule` and
`prune_schedule` (daily, see [Pruning](#pruning)) take
a scheduler frequency method with no argument (`'everyTenMinutes'`) or a cron
expression (`'*/10 * * * *'`, `'@hourly'`); a value that is neither logs an
error and keeps the default. A method that only picks the days (`'weekdays'`,
`'mondays'`) is not a frequency: write a cron expression (`'*/15 * * * 1-5'`).
Set `dispatch_schedule` to `null` to schedule the command yourself. The scheduled
commands run `withoutOverlapping()->onOneServer()` (`check-static` also in the
background): on several servers, use a shared cache store (redis, memcached,
database, dynamodb) so that really runs once. The renewal lock needs the same
(see below).

Renewal failures are classified:

- transient (network, provider 5xx, rate limit): retried with backoff while the
  expiry window is still open
- terminal (invalid_grant, revoked, refresh token expired): the credential moves
  to `needs_reconnect` and an event fires, no further attempts
- the app's own client refused (a wrong, rotated or missing client id or
  secret, on any provider, Meta's app secret and `appsecret_proof` included):
  a critical log (`{provider} rejected the app's OAuth client`) at most every
  15 minutes per provider, renewals of that provider pause for 15 minutes, and
  the credentials stay active, since a reconnect would go through the same
  broken app. `check-static` logs it once per run and flags nothing

A transient failure throws `Exceptions\TransientRenewalException` so the queue
retries the job. It implements `ShouldntReport`: your exception handler does not
report it, since an outage would send one event per credential per attempt.
When a credential runs out of retries, the job logs one warning
(`Renewal failed after retries; retrying later`), records the reason in
`last_error` and tries again later. Uncatalogued provider errors are logged as
errors while `log_unknown_errors` is on.

Run a real queue worker. On the `sync` connection each job runs once, inline in
the dispatcher, without `tries` or `backoff`. The dispatcher still walks every
due credential past a failed one; only an unexpected error makes it exit with a
failure code. On a real queue, a job the queue rejects (its backend or the cache
lock store is down) is reported once and ends the run with a failure code; the
next run tries again.

A credential that cannot be renewed unattended (LinkedIn without refresh tokens,
or a refresh token past its own lifetime) is not cut off early: when its window
opens, `CredentialExpiringSoon` fires once with the expiry date, the credential
keeps posting, and it moves to `needs_reconnect` only if it actually expires
before the user reconnects. The same warning comes a lead time **before** a
refresh token dies (LinkedIn's live 365 days and are never extended), with the
date the credential really stops working, so the member can re-authorise in
time. A reconnect that brings a new refresh token re-arms it; storing a profile
without a refresh token never erases a known one.

LinkedIn issues refresh tokens per app, so `refresh_enabled` cannot vouch for
every credential: one stored without a refresh token (from an app without
programmatic refresh tokens, or a row from before you turned the option on) is
renewed as if refresh were off, warned ahead of its expiry and flagged once it
has expired. A custom connector makes the same per-credential call by
overriding `renewalStrategyFor(SocialToken $token)`; ask
`$token->renewalStrategyWith($connector)` rather than
`$connector->renewalStrategy()` when you decide about a stored credential.

The refresh token's own expiry is not on the Socialite contract. TikTok's
SocialiteProviders driver exposes the token response and it is read from
there; core Socialite's LinkedIn drivers drop LinkedIn's
`refresh_token_expires_in`, so it stays unknown until the first renewal
reports it, unless you pass it: `StoreConnection` and
`StoreAccountFromSocialite` take `refreshExpiresAt:`, as does
`StoreLinkedInOrganizations`.

Static credentials (Facebook page tokens, and Meta user tokens that never
expire) are never renewed, but die when the user changes their password,
removes the app or loses the Page's admin role. A daily `social-tokens:check-static` run asks Meta about each one that an active
account still uses, and flags the dead ones `needs_reconnect` (set
`check_static_schedule` to `null` to disable; rejection reports then wait for
a run you start, see [Posting](#posting)). A credential whose accounts are
all flagged or revoked, such as a Page the user no longer manages, is not
checked. Because a verdict cuts the Page off, the run is cautious: a session
error ("password changed", "app removed") flags at once, any other rejection
must be confirmed by the next day's run, a permission error on `/me` is only
logged, and when more than 20% of at least 10 checked tokens of one provider
are rejected at once (an incident on the provider's side, or a change in your
app's setup such as a new LinkedIn app) none of that provider's credentials is
flagged and a critical log names it instead (`check_static_breaker`). The other
providers' verdicts still apply; the same test over the whole run stops it
entirely. Either way the command exits with a failure code. A credential that
cannot be checked (e.g. it cannot be decrypted after an `APP_KEY` change) is
logged and skipped.

A custom connector opts in by implementing `Contracts\ChecksCredential`. Its
plain `terminalFailure()` needs two consecutive runs to flag (a
`reportRejected()` check only records the first strike); return
`terminalFailure($reason, ['definitive' => true])` to flag on the first.

Renewals run under a per-credential lock so a scheduled job and a synchronous
`validAccessTokenFor()` never refresh the same credential at once: TikTok and X
refresh tokens are single-use, so the second refresh would be refused and the
credential flagged. The second caller waits up to 10 seconds, then uses the token
the first one stored instead of calling the provider; if the lock is still held,
it gets a transient failure (the job retries, `validAccessTokenFor()` throws a
transient `NeedsReconnectException`). The lock lives in your default cache
store, which must support atomic locks **and be shared by every server that
serves web requests, runs queue workers or runs the scheduler**: redis,
memcached, database or dynamodb, on a server they all reach. The `file` store,
or a redis on each host, locks one host only: with a separate worker server, a
job and a web request each take their own lock and refresh the same TikTok or X
token. The `array` store locks nothing across processes, even on one server (a
queue worker and a web request each have their own): use it in tests only.

A reconnect that lands while a renewal is in flight wins: the renewal's result
is dropped instead of overwriting the new tokens, and a refusal of the old
refresh token does not flag the reconnected credential.

Under the lock the credential is re-read with a locking read
(`SELECT ... FOR UPDATE`; SQLite has no row locks), so a renewal another process
stored is seen, even inside a transaction, or waited for until it commits. That
wait lasts as long as the database lets it: on MySQL up to
`innodb_lock_wait_timeout` (50 seconds by default), on PostgreSQL up to 30
seconds, or your `lock_timeout` if you set one. A lock wait timeout, a deadlock,
or a wait of more than 30 seconds (after which the renewal lock could lapse
during the provider call) is a transient failure.

Ask for a token outside your own database transaction. Inside one, the
credential's row stays locked until your transaction ends, and a rollback
discards the token the renewal stored: for TikTok and X the provider has
already retired the old refresh token, so the credential dies at its next
renewal. On PostgreSQL the wait for the row lasts until the transaction holding
it ends, unless you set `lock_timeout`. And a lock wait timeout or a deadlock is
not a transient failure there: the database may already have rolled your transaction back (a MySQL
deadlock does, and any error aborts a PostgreSQL transaction), so the
`QueryException` reaches your `DB::transaction()` (as a `DeadlockException` from
a nested one), which rolls back or retries. Renewing a TikTok or X credential
inside a transaction logs a warning (`Renewing a single-use refresh token inside
a database transaction`).

### Meta: tokens that never expire, and data access

A Facebook Login user token (the one a user's Instagram accounts post with) has
two clocks, and `debug_token` reports both. The package reads them at connect
and after each renewal:

- **The token's own expiry.** Usually 60 days, extended at each renewal. Some
  Facebook Login configurations issue tokens that never expire (`expires_at:
  0`): such a credential is stored *static*, with no `renew_at`, and
  `check-static` asks Meta about it every day instead of extending it every
  week. A credential stored by 2.x becomes static at its next renewal.
- **Data access** (`data_access_expires_at` on the credential). Meta stops
  sharing the user's data with your app 90 days after the user was last
  active in it, although the token keeps working and renewing; only a new
  login through your app moves the date. A reconnect (`StoreFacebookPages` or
  `StoreInstagramAccounts` with `extend: true`, the default) stores the new
  date or, when `debug_token` does not answer, clears the old one (null,
  unknown) until the next renewal reads it; a re-sync with `extend: false`
  keeps the stored date in that case. `social-tokens:dispatch-renewals` fires
  `CredentialDataAccessExpiring` once, seven days before it (or as soon as it
  learns of a date already close or past), for a credential an active account
  posts with. It checks after dispatching the run's renewals: if the check
  fails, the renewals still go out and the command exits with a failure code.
  Ask the user to reconnect. Meta does not say which calls a lapse blocks for
  an app with business permissions (Instagram publishing included; the
  `pages_*` permissions are documented as not expiring), so the package only
  warns: the credential is not flagged and stays usable. If Meta then rejects
  a call, report it with `reportRejected()`. Tune or turn off the warning in
  `data_access_warning` (`SOCIAL_TOKENS_DATA_ACCESS_WARNING=false`).

```php
$account->credential->data_access_expires_at; // null when unknown
$account->credential->isDataAccessExpired();
```

## Posting

The publishing layer never touches refresh tokens. It asks for a valid access
token and gets one, or a clear signal to reconnect. If the job will hold the
token a while, say how long, and a token expiring sooner is renewed first:
`validAccessTokenFor($account, 20 * 60)` for an Instagram/Threads container poll
of up to 15 minutes. Keep it under the provider's token lifetime (1 hour for
Google), or every call refreshes. Ask before you open a database transaction,
not inside it (see [Renewing](#renewing)).

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

When Meta, Threads or LinkedIn rejects a token at publish time, report it, so
every account sharing that credential stops posting (and your app is told
once):

```php
try {
    // ... call the provider API with $token
} catch (YourProviderError $e) {
    app(SocialTokens::class)->reportRejected(
        $account,
        $e->safeMessage(),                 // no secrets in the reason
        terminal: $e->provesTokenIsDead(), // see below: rarely true
        rejectedToken: $token,             // ignored if the user reconnected meanwhile
    );
}
```

- `terminal: true` flags the credential at once, with no provider call. Pass
  it only when the error itself proves the credential is dead: a Meta code 190
  or 102 **with a session subcode** (458, 459, 460, 463, 464, 467, 492: password
  changed, app removed, user logged out), or, for LinkedIn without refresh
  tokens, `REVOKED_ACCESS_TOKEN` or `EXPIRED_ACCESS_TOKEN`.
- `terminal: false` is for anything ambiguous: a 401, or a bare Meta 190/102
  with no subcode, which Meta also sends to valid tokens during an incident.
  The package asks the provider whether the token still works and flags the
  credential only on a definitive answer (a session subcode, LinkedIn's
  `revoked`). A plain rejection counts as one strike against the token, and
  only `social-tokens:check-static` confirms it, flagging the credential if
  the provider still rejects the token: Facebook Page tokens are always
  checked, renewable credentials only when `SOCIAL_TOKENS_CHECK_RENEWABLE=true`.
  If you set `check_static_schedule` to `null`, schedule or run the command
  yourself, or such a report never flags the credential. Repeated reports
  never confirm each other: publish retries seconds apart would all see the
  same incident.
- Google, TikTok and X: do not report a 401 `invalid_token`. It usually means
  the access token expired while the refresh token still works. Call
  `validAccessTokenFor()` again (it renews an expired token, or tells you to
  reconnect) and retry; a dead refresh token is caught by the renewal itself
  (`invalid_grant`), which flags the credential. These connectors cannot check
  a token, so `terminal: false` does nothing for them and returns `false`, and
  `terminal: true` would flag a healthy credential.
- LinkedIn with refresh tokens: report a `REVOKED_ACCESS_TOKEN`, or any other
  ambiguous 401, with `terminal: false`. The package introspects the token
  and flags the credential only when LinkedIn answers `revoked`; an `expired`
  access token is left to the renewal. On an `EXPIRED_ACCESS_TOKEN`, just
  call `validAccessTokenFor()` again and retry. `terminal: true` would flag a
  credential whose refresh token still works.

To catch revoked tokens between two renewals without waiting for a failed post,
set `SOCIAL_TOKENS_CHECK_RENEWABLE=true`: the daily `check-static` run then also
checks renewable Meta, Threads and LinkedIn credentials.

LinkedIn's token introspection uses the configured client (`services.linkedin`,
or `client_id` / `client_secret` on the `linkedin` connector), which must be the
LinkedIn app that issued the member tokens. LinkedIn answers a bare "inactive"
for a token of another app, so the package flags a LinkedIn credential only
when the answer says the token was `revoked`, or `expired` without refresh
tokens; anything else is logged as an uncatalogued check error. If you obtain
member tokens from a separate app (a Community Management app next to your
Sign In app), point the `linkedin` connector at that app.

## Account status

```
active ──(renew succeeds)──────────────────────> active
       ├─(cannot renew unattended)──> active + CredentialExpiringSoon ──(expires)──> needs_reconnect
       └─(terminal failure)──────────> needs_reconnect ──(user reconnects)──> active

revoked: set by disconnect() (one account) or revoke() (a whole login); never retried.
```

A reconnect makes a revoked account active again, except one the user
disconnected when the connection fans out (Facebook Pages, Instagram accounts,
LinkedIn organizations; see below).

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
`CredentialDataAccessExpiring`, `CredentialNeedsReconnect`, `CredentialRevoked`,
`AccountNeedsReconnect` and `AccountRevoked` to drive
notifications and a reconnect button in your panel. A dead credential fires
`CredentialNeedsReconnect` once, not once per account: notify the user from that
event, listing the affected accounts through `$event->token->accounts`, so a user
with ten Instagram accounts or LinkedIn organizations behind one token gets one
message, not ten. Facebook Pages are the exception: each Page has its own static
credential, so `check-static` fires one event per dead Page. Group those
notifications yourself if you need to, e.g. by the accounts'
`provider_holder_id` (the Facebook user) or by owner.

The events use `SerializesModels`: a queued (`ShouldQueue`) listener receives
the account or credential re-read from the database when it runs, so neither
your owner model nor the token ciphertext is copied into the queue. It fails
with `ModelNotFoundException` if the row was deleted meanwhile; if you fire the
connection inside your own transaction, make the listener
`ShouldQueueAfterCommit`.

To disconnect **one** account, call `disconnect()`. Accounts that share its
credential (the other Instagram accounts of the same Facebook user, the other
organizations of a LinkedIn member) keep posting. The credential itself is
revoked only when its last connected account goes, and so is the Meta user
token behind a user's Facebook Pages, once no Page and no Instagram account of
that user is connected under the same owner. A LinkedIn member's personal
profile and organizations have separate credentials: disconnecting the profile
leaves the organizations posting, and the reverse:

```php
app(SocialTokens::class)->disconnect($account);
```

A disconnected account stays disconnected. A reconnect of the same Facebook,
Instagram or LinkedIn organizations login lists every Page, account or
organization the user still manages, the removed one included: it is left
out, revoked, not returned, and fires no `AccountConnected`. Pass
`restoreDisconnected: true` (to `StoreConnection`, or the fan-out action) when
the user means to bring removed targets back; a connection that lists only
disconnected ones returns an empty collection. To bring back one account
only, forget its disconnection first:

```php
$account->clearDisconnected()->save(); // the next reconnect restores it
$account->wasDisconnected();           // true while it stays out
```

Reconnecting a single account (TikTok, Google, X, Threads, Instagram Login, a
LinkedIn profile) always restores it, and an account revoked any other way
(`revoke()`, a deauthorization) comes back with the next reconnect.

To disconnect a whole login (the credential and every account it backs), revoke
the credential:

```php
app(SocialTokens::class)->revoke($account->credential);
```

For a LinkedIn member, that is one of two logins: revoke the personal
profile's credential and the organizations' one to disconnect the member
entirely.

Both record the revocation locally first (the `Revoked` events fire then), and
only then ask the provider to invalidate the token. A revoked credential keeps
no tokens: the write that revokes it erases its access and refresh tokens and
their dates, and the provider is called with a copy taken before. Google,
TikTok and X have a revocation endpoint, called once no other credential is
active for the same external user (see [Owners](#owners)); Facebook,
Instagram, Threads and LinkedIn have none the package can use, so their
revocation is local, and erasing the token is what removes it. The provider
call is best effort: when it fails (provider down, a token that no longer
decrypts after an `APP_KEY` change) or the provider does not confirm it, a
warning is logged and the local revocation stands. A revocation listener that
throws does not prevent it either: the provider is still called, then the
listener's exception reaches you. Read a token before revoking it if you need
it: from `CredentialRevoked` on, it is null.

`$account->markRevoked()` only flags the account locally: no provider call, and
the credential is left as is.

### Pruning

A credential nothing will use again is removed. When its renewal comes due
and no active account depends on it, the dispatcher unschedules it
(`renew_at` null) rather than renew it; a reconnect schedules it again. A
daily `social-tokens:prune` then deletes the credentials that are revoked,
flagged `needs_reconnect`, or used by no connected account (left behind by a
reconnect, or by accounts you deleted), once they have not changed for
`prune_after_days` (30). Accounts are never deleted: one whose credential is
pruned keeps its row, without a credential (it reads as `needs_reconnect`
unless it is revoked itself), so read `$account->credential` null-safely. Set `prune_schedule` to `null` to keep
everything, or run `php artisan social-tokens:prune --days=7` yourself.

### When a user removes your app (Meta, Threads)

Meta posts a `signed_request` to your app's **Deauthorize** callback when a
user removes it, and to its **Data Deletion Request** callback when they ask
for their data to be deleted; Instagram Login apps have the same two, and
Threads apps call them **Uninstall** and **Delete**. The package can answer
them. Turn the routes on (`SOCIAL_TOKENS_CALLBACKS=true`, off by default) and
paste the URLs in the App Dashboard, `{provider}` being the connector key
(`facebook`, which also covers the Instagram accounts reached through a Page,
`instagram_login`, `threads`):

```
POST https://your-app.test/social-tokens/meta/{provider}/deauthorize
POST https://your-app.test/social-tokens/meta/{provider}/delete
```

Each request is verified with that connector's app secret (HMAC-SHA256,
compared in constant time); one that does not verify gets a 400 and changes
nothing. The routes carry no middleware by default (Meta sends no CSRF token):
add some, a throttle for instance, in `callbacks.middleware`, and move them with
`callbacks.prefix`.

- **Deauthorize / Uninstall** revokes every credential and account of that
  user, under every owner: their user credential, their Pages' own
  credentials and accounts, their Instagram accounts
  (`SocialTokens::revokeExternalUser()`). The provider is not called: the
  grant is already gone. `CredentialRevoked` and `AccountRevoked` fire once
  per row, and a reconnect makes the rows active again.
- **Data deletion / Delete** deletes their tokens
  (`SocialTokens::purgeExternalUser()`): the credentials are deleted, and the
  accounts are revoked and kept, without a credential, since your own tables
  may point at them. Then `ExternalUserDataDeleted` fires with the provider,
  the user id, the confirmation code and the account ids: **delete or
  anonymise those accounts, and whatever else you keep about the user, in a
  listener.** Meta gets the `{url, confirmation_code}` answer it expects. Once
  the tokens are deleted, both happen even when an `AccountRevoked` listener
  throws (its exception is reported); only a failed deletion answers a 500,
  with no code. The url is a status page the package serves
  (`GET {prefix}/{provider}/deletion/{code}`, which recognises only the codes
  it signed with your `APP_KEY`), or yours, with
  `SOCIAL_TOKENS_DELETION_STATUS_URL` (`{provider}` is replaced, and so is
  `{code}`; without `{code}`, `?code=` is appended).

To place the routes yourself, leave `enabled` off and call
`Pr4w\SocialTokens\Support\CallbackRoutes::register()` from your routes file, or
call the actions from your own controllers:
`app(HandleDeauthorizeCallback::class)->handle($provider, $signedRequest)` and
`app(HandleDataDeletionCallback::class)->handle($provider, $signedRequest)`.
Calling `purgeExternalUser()` yourself, pass it a closure as third argument to
get the accounts even when a listener throws: it receives them as soon as the
tokens are deleted, before the listener's exception is rethrown.
The callbacks find rows by the `user_id` Meta signs, so the holder ids must be
those: the app-scoped ids Socialite's Facebook and Threads drivers return. For
Instagram Login, check that your driver's user id is the one Meta sends (a
callback for an id the package does not hold changes nothing). Other
providers' push signals
(TikTok's `authorization.removed` webhook, Google's RISC `tokens-revoked`
events) work the same way: verify them as the provider documents, then call
`revokeExternalUser($provider, $externalId)`.

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
list, and lists are normalised: one scope per element (a comma- or
space-separated string, as LinkedIn and TikTok send them, is split), no blanks
or duplicates. Threads never reports scopes, so its rows stay `null` unless you
write them. The account's
`profile` is merged, not replaced: keys you store there yourself survive a
reconnect. A Facebook Page listed without an `access_token` (the user has no
posting task on it) is not stored.

## Adding a provider

For an OAuth 2.0 provider, extend `OAuth2Connector`: declare `tokenUrl()`, the
`renewalStrategy()` and `leadTime()`, and override only what differs —
`refreshParameters()`, `tokenRequest()` (client authentication), `classifyError()`
(which error codes mean a dead grant, an app-side problem or a temporary one),
`refreshExpiresInKey()`, `profileFrom()`. `XConnector` and `TikTokConnector` are
complete references. A Graph API product on Facebook Login tokens can extend
`MetaGraphConnector` (the base of `FacebookConnector` and `InstagramConnector`),
which extends the user token with `fb_exchange_token`; a Meta API whose
long-lived token extends itself with a `refresh_access_token` call can extend
`LongLivedTokenConnector` (the base of `ThreadsConnector` and
`InstagramLoginConnector`). For anything else, extend
`AbstractConnector` and implement `refreshCredential()`. Register the class under its key in the config: at most 50
characters, since it is stored in the `provider` columns. Nothing else in the package needs to change. Build
requests from `$this->http()` rather than the `Http` facade so they carry the
configured timeouts, and wrap them in `$this->attempt()` so network errors, 5xx
and 429 come back as transient failures. Mark every parameter that carries a
token or a secret `#[\SensitiveParameter]`, as the package does: exception
traces record call arguments, and error trackers collect them.

Optional hooks (defaulted in `AbstractConnector`): `credentialProvider()` when the
credential is refreshed under another provider's key (Instagram returns
`facebook`), `renewalStrategyFor(SocialToken)` when a credential can lack what
`renewalStrategy()` needs (LinkedIn's without a refresh token renew as
`ReauthOnly`), `exchangeForLongLived()` for a short-to-long token swap at connect,
and `revoke(SocialToken)` if the provider exposes a token-revocation endpoint.
A connector whose connections yield several accounts also implements
`Contracts\FansOutConnections`: its `connectionAction()` names the action
(implementing `Contracts\StoresConnections`) that `StoreConnection` hands them
to. `revoke()` must not throw: read the token and send the request inside a
`try`, and log a failure with `$this->logRevokeFailure($token, $e)`, and an
answer that does not confirm the revocation with
`$this->logUnconfirmedRevoke($token, $response)`. It receives a copy of the
credential taken before the revocation: the stored row no longer holds the
tokens.

### Using a connector on its own

`app(SocialTokens::class)->connector('tiktok')->refreshCredential(new SocialToken([...]))`
returns a `RenewalResult` and writes nothing. The lock, the double-check, the jobs
and `validAccessTokenFor()` need the package's tables; `renewCredential()` throws
for a token that was never saved.

## License

MIT.
