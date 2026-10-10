# Upgrading

## From 2.x to 3.0

Coming from 1.x? Follow [From 1.x to 2.0](#from-1x-to-20) as well: one
`php artisan migrate` runs both versions' migrations, in the right order. From
1.0 or 1.1, start with [Coming from 1.0 or 1.1](#coming-from-10-or-11): 1.2's
changes.

### Update the package

```bash
composer require pr4w/laravel-social-tokens:^3.0
```

### Run the new migrations

```bash
php artisan migrate
```

The package loads its migrations itself, so these run whether or not you
published them. If you keep published copies, run
`php artisan vendor:publish --tag=social-tokens-migrations` to add the new
files (existing files are left alone).

- `2025_01_01_000006_shorten_provider_columns` makes `provider` `varchar(50)` on
  `social_accounts` and `social_tokens`, so the owner-scoped unique keys fit
  InnoDB's 3072-byte limit with uuid and ulid owner ids too. **A connector key
  must now be at most 50 characters.** The migration checks both tables first
  and refuses, changing nothing, while a row holds a longer `provider`: rename
  that connector's key in `config/social-tokens.php`, update its rows
  (`UPDATE social_accounts SET provider = 'new-key' WHERE provider = 'old-key'`,
  and the same on `social_tokens`), then migrate again. Only MySQL, MariaDB
  and PostgreSQL are changed: SQLite declares no length, and SQL Server has no
  key-length cap (and cannot shrink an indexed column in place), so both are
  left as is. On MySQL and PostgreSQL the change rebuilds both tables and their
  indexes, locking each while it runs: short on tables of this size. Rolling
  it back keeps `varchar(50)`, which 2.x reads fine (widening it again could
  push the owner keys of a uuid install back over MySQL's limit).
- `2025_01_01_000006_index_social_token_id_on_social_accounts` indexes
  `social_accounts.social_token_id` on PostgreSQL and SQLite, which do not index
  a foreign key column. On MySQL the foreign key already has an index, and the
  migration adds nothing.
- `2025_01_01_000008_add_purpose_to_social_tokens` adds
  `social_tokens.purpose` (`varchar(32)`, not null, default `''`), replaces the
  unique key on `(provider, provider_holder_id, ownable_type, ownable_id)`
  (`social_tokens_owner_identity_unique`) with one on
  `(provider, provider_holder_id, purpose, ownable_type, ownable_id)`
  (`social_tokens_identity_purpose_unique`, prefixed like Laravel's own index
  names when `prefix_indexes` is on), and moves the LinkedIn organizations to
  credentials of their own (see
  [LinkedIn profiles and organizations](#linkedin-profiles-and-organizations-have-separate-credentials)).
  On MySQL and MariaDB it checks first that the new key fits InnoDB's
  3072-byte limit, and refuses, changing nothing, if it does not. It can be
  run again after a failure, and commits its data changes in chunks rather
  than as one transaction.

- `2025_01_01_000009_add_data_access_expiry_to_social_tokens` adds two
  nullable timestamps to `social_tokens`, `data_access_expires_at` and
  `data_access_warned_at` (see
  [Meta data access](#meta-data-access-and-tokens-that-never-expire)). It can
  be run again after a failure. **Run it before, or together with, the code
  deploy:** the Meta connectors write these columns and
  `social-tokens:dispatch-renewals` reads them. Until it has run:
  - every Facebook and Instagram (Facebook Login) connection throws a
    `QueryException` (unknown column `data_access_expires_at`);
  - the renewal of a Meta user credential fails whenever Meta reports the
    deadline, as it usually does, and is retried;
  - each dispatcher run still dispatches all the renewals, then reports the
    failed data access check ("Could not check the data access deadlines")
    and exits with a failure code. On SQLite the check does not fail: it
    silently finds nothing.

  The other providers, Instagram Login and Threads included, keep connecting
  and renewing.

- `2025_01_01_000010_clear_tokens_of_revoked_credentials` erases the tokens
  that credentials revoked before 3.0 still hold (see
  [Revoked credentials keep no tokens](#revoked-credentials-keep-no-tokens)).
  It changes no schema and cannot be rolled back.

- `2025_01_01_000011_add_disconnected_at_to_social_accounts` adds a nullable
  timestamp, `disconnected_at`, to `social_accounts` (see
  [Disconnected accounts stay disconnected](#disconnected-accounts-stay-disconnected)).
  It can be run again after a failure. **Run it before, or together with, the
  code deploy:** until it has run, `disconnect()` throws a `QueryException`
  (unknown column `disconnected_at`) and changes nothing.

The two 000006 migrations are named to run before
`2025_01_01_000007_scope_identity_to_owner`, which needs them when you come
from 1.x; 000008 runs after it. On a 2.x install, which already ran 000007,
they simply run on their own.

### Transient renewal failures are no longer reported

`RenewCredential` throws `Pr4w\SocialTokens\Exceptions\TransientRenewalException`
for a transient failure (network, provider 5xx or 429, rate limit, lock
contention, an uncatalogued error). It extends `RuntimeException` with the same
`Transient renewal failure: ...` message, so `catch (RuntimeException)` blocks
and message-based filters keep working. It implements
`Illuminate\Contracts\Debug\ShouldntReport`, so your exception handler (and
Sentry, Bugsnag, Flare...) no longer receives one event per credential per
attempt during an outage. `failed_jobs` rows are unchanged.

What you get instead: one `warning` log per credential when it runs out of
retries (`[social-tokens] Renewal failed after retries; retrying later`, with
`provider`, `token_id`, `reason`, `retry_at`), plus `last_error` on the
credential. If you relied on error-tracker events to notice provider outages,
alert on that log line, or forward the exception yourself, for example:

```php
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Exceptions\TransientRenewalException;

Event::listen(JobFailed::class, function (JobFailed $event) {
    if ($event->exception instanceof TransientRenewalException) {
        Log::error($event->exception->getMessage(), $event->exception->context());
    }
});
```

On the `sync` queue connection, `social-tokens:dispatch-renewals` no longer
stops at the first failed renewal. It exits with a failure code only for an
unexpected error, after walking every due credential. On a real queue, a job
the queue rejects (Redis, SQS or the cache lock store down) still ends the run,
as in 2.x: it is reported once, the command prints `Could not queue a renewal
job` and exits with a failure code.

### Queued listeners re-read the models

Every package event (`AccountConnected`, `AccountNeedsReconnect`,
`AccountRevoked`, `CredentialExpiringSoon`, `CredentialNeedsReconnect`,
`CredentialRenewed`, `CredentialRevoked`) now uses `SerializesModels`, like
Laravel's own events. Synchronous listeners and `Event::fake()` assertions see
no difference. A listener that implements `ShouldQueue` gets
`$event->account` / `$event->token` fetched again from the database when it
runs, with the relations that were loaded at dispatch (`ownable`,
`connectedBy`), instead of a serialised copy that also carried your owner
model and the token ciphertext into the queue.

What to check:

- **Rows deleted right after the event.** If your app deletes a
  `SocialAccount` or `SocialToken` soon after the event fires (for example
  after `disconnect()`, which fires `AccountRevoked`), a queued listener that
  runs later fails with `ModelNotFoundException` and lands in `failed_jobs`.
  `$deleteWhenMissingModels` on a listener class is not honoured by Laravel
  for queued listeners. Delete the rows from that listener itself, or later,
  or keep the data the listener needs in your own event.
- **Events fired inside your own transaction.** If your OAuth callback wraps
  `StoreConnection::handle()` in `DB::transaction()`, a queued listener can
  run before the commit and not find the new account. Implement
  `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` on the listener (or set
  `public $afterCommit = true;`).
- **Payloads queued by 2.x** still run after the upgrade: a model serialised
  whole is restored as it was.

`SocialAccount::connectedBy()` is now a relation named `connectedBy`, after
its method; it was named `connected_by`. If your code reads it by the old name
(`$account->getRelation('connected_by')`, `relationLoaded('connected_by')`,
`setRelation('connected_by', ...)`), use `connectedBy`. The same goes for the
snake-case property: `$account->connected_by` returned the connecting model
after `associate()` or `with('connectedBy')` in 2.x, and now returns null
without an error (or throws `MissingAttributeException` under
`Model::preventAccessingMissingAttributes()`). Search your code and views for
`->connected_by` and read `$account->connectedBy` instead. `$account->connectedBy`,
`with('connectedBy')` and `toArray()` (key `connected_by`) need no change;
eager loading now actually avoids the extra query per row.

### Meta calls carry `appsecret_proof`

The `facebook` connector now signs the Graph calls it makes with a user or Page
token (`/me`, `/me/accounts`) with `appsecret_proof`, so you can turn on
**Require App Secret** in your Meta app. Nothing to do for the package to keep
working: the option defaults to on, even in a published config that does not
have it, and Meta accepts a valid proof whether or not the setting is on.

1. If you keep a published `config/social-tokens.php`, add the option to the
   `facebook` and `instagram` entries to be able to switch it:
   `'appsecret_proof' => env('META_APPSECRET_PROOF', true),`.
2. If your database still holds Facebook Page or user tokens issued by
   **another** Meta app than the one in `services.facebook` (you switched
   apps), their proof cannot match: `social-tokens:check-static` then logs
   one critical "facebook rejected the app's OAuth client during
   check-static" per run ("Invalid appsecret_proof", with the number of
   credentials) instead of checking them. Set `META_APPSECRET_PROOF=false`
   until those users have reconnected.
3. To turn on Require App Secret, first sign your own Graph calls made with
   these tokens (publishing, insights):
   `app(SocialTokens::class)->connector('facebook')->appSecretProof($token)`
   (or `connector('instagram')`) gives the value to send as
   `appsecret_proof`. Then deploy, and turn the setting on in the Meta app's
   advanced settings.

### Meta data access and tokens that never expire

The package now reads Meta's `debug_token` lifetime of a Facebook Login user
token (the credential behind Instagram accounts) at connect and after each
renewal. Nothing is required; what changes:

- **A token that never expires stops renewing.** A credential whose token
  Meta reports with `expires_at: 0` was extended every week in 2.x (an
  unknown expiry is checked back after one lead time), with a "Renewal
  returned no expiry" warning each time. At its next renewal, within a week
  of the upgrade, it becomes static: `renew_at` null, no more weekly
  `CredentialRenewed`, and `social-tokens:check-static` asks Meta about it
  every day instead. If you set `check_static_schedule` to `null`, schedule
  `check-static` yourself, or nothing notices such a token dying before a
  publish fails.
- **Data access is announced.** `social-tokens:dispatch-renewals` fires
  `CredentialDataAccessExpiring` once, seven days before Meta stops sharing
  the user's data with your app (90 days after the user was last active in
  it). Listen for it and ask the user to reconnect: only a new login moves the
  date. The credential is not flagged. A reconnect (`extend: true`, the
  default) stores the new date, or leaves it null (unknown) when
  `debug_token` does not answer, until the next renewal reads it; a re-sync
  with `extend: false` keeps the stored date then. Treat null as "unknown",
  not as "expired".
- **One more Graph call per renewal** of a Meta user credential
  (`debug_token`, with the app access token).

If you keep a published `config/social-tokens.php`, add the block to be able to
change the warning (it is on, with a 7-day lead, when the block is missing):

```php
'data_access_warning' => [
    'enabled' => env('SOCIAL_TOKENS_DATA_ACCESS_WARNING', true),
    'lead_days' => 7,
],
```

To learn the deadlines now rather than at each credential's next renewal or
reconnect, run once in `php artisan tinker` (one `debug_token` call per Meta
user credential behind Instagram accounts). Do run it if a credential stored
before 1.2 has no `renew_at` (a token that never expires, stored as static):
nothing renews it, so only a reconnect would read its deadline. A deadline
that is close or already past is announced at the next dispatcher run:

```php
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

$facebook = app(SocialTokens::class)->connector('facebook');

SocialToken::where('provider', 'facebook')->where('status', 'active')
    ->whereHas('accounts', fn ($account) => $account->where('provider', 'instagram')) // not the Page tokens
    ->eachById(function (SocialToken $token) use ($facebook) {
        $data = $facebook->debugToken($token->access_token);

        if (! is_array($data)) {
            return; // Meta did not answer: the next renewal reads it
        }

        $lifetime = MetaGraphConnector::tokenLifetime($data);
        $token->data_access_expires_at = $lifetime['data_access_expires_at'] ?? $token->data_access_expires_at;

        if ($token->expires_at === null && $lifetime['never_expires']) {
            $token->renew_at = null; // never expires: static
        }

        $token->save();
    });
```

### Instagram without a Facebook Page

3.0 adds the Instagram API with Instagram Login, under the connector key
`instagram_login` (see the README, "Instagram without a Facebook Page").
Nothing changes for Instagram accounts connected through a Facebook Page
(`instagram`). To offer it:

1. If you keep a published `config/social-tokens.php`, add the entry:

   ```php
   'instagram_login' => [
       'driver' => \Pr4w\SocialTokens\Connectors\InstagramLoginConnector::class,
       'graph_version' => env('META_GRAPH_VERSION', \Pr4w\SocialTokens\Connectors\MetaGraphConnector::DEFAULT_GRAPH_VERSION),
   ],
   ```

2. Add the Instagram app's id and secret to `config/services.php` as
   `instagram_login` (or add `'credentials' => 'instagram'` to the entry
   above if your Socialite driver reads `services.instagram`).
3. Connect with `StoreConnection::handle('instagram_login', $user)`. The
   accounts have `provider = 'instagram_login'`: include it wherever you
   select Instagram accounts, and publish to them through
   `graph.instagram.com`.

### Meta deauthorize and data deletion callbacks (optional)

3.0 can answer Meta's Deauthorize and Data Deletion Request callbacks (and
Threads' Uninstall and Delete). Nothing changes until you turn them on; to do
so:

1. If you keep a published `config/social-tokens.php`, add the block (without
   it the routes stay off):

   ```php
   'callbacks' => [
       'enabled' => env('SOCIAL_TOKENS_CALLBACKS', false),
       'prefix' => 'social-tokens/meta',
       'middleware' => [],
       'deletion_status_url' => env('SOCIAL_TOKENS_DELETION_STATUS_URL'),
   ],
   ```

2. Set `SOCIAL_TOKENS_CALLBACKS=true`, then `php artisan route:cache` again if
   you cache routes.
3. Listen for `Pr4w\SocialTokens\Events\ExternalUserDataDeleted` and delete or
   anonymise the accounts it lists (`$event->accountIds`, kept revoked and
   without a credential) and whatever else your app keeps about the user: the
   package deletes the tokens only. The event fires, and Meta gets its answer,
   even when one of your `AccountRevoked` listeners throws: its exception is
   reported, not returned to Meta.
4. In the Meta App Dashboard, replace the callback URLs you had (or a Data
   Deletion Instructions URL) with
   `https://your-app/social-tokens/meta/facebook/deauthorize` and
   `.../facebook/delete` (`threads`, `instagram_login` for those apps). If an
   existing route of yours already handles them, keep it and call
   `revokeExternalUser()` / `purgeExternalUser()` from it instead. A listener
   exception is rethrown by both; pass `purgeExternalUser()` a closure as
   third argument to receive the accounts once the tokens are deleted anyway.

### Check your published config

No change is needed for a config that kept the shipped forms. Values in other
forms are now normalised instead of failing or being misread:

- `queue.backoff` may be one value (`60`), a list, or a comma-separated string.
  A single value used to stop every dispatcher run with a `TypeError`.
- `queue.tries` set to `null`, an empty string or a non-numeric value now means
  4 attempts. 2.x read it as `0`, which Laravel takes as unlimited retries: for
  example `'tries' => env('SOCIAL_TOKENS_TRIES')` with the variable unset.
  Write `0` to keep unlimited retries.
- Boolean settings holding a string are read as booleans. If
  `log_unknown_errors` or `check_renewable` holds a string such as `'false'`,
  `'no'` or `'off'`, either written in the file or coming from `env()` (which
  converts only `true`, `false`, `null` and `empty`, and returns any other
  value as a string: `SOCIAL_TOKENS_CHECK_RENEWABLE=off` gives `'off'`), 2.x
  treated it as true: uncatalogued errors were logged, renewable credentials
  were checked. 3.0 reads it as false. Write `true` (or
  `SOCIAL_TOKENS_CHECK_RENEWABLE=true`) to keep the 2.x behaviour.
- `http.timeout` and `http.connect_timeout` keep fractional seconds (2.x cut
  `2.5` to `2`).
- `check_static_breaker.min_checked` below 1 counts as 1.
- A connector `driver` that names a missing class, or a class that does not
  implement `ProviderConnector`, now counts as no connector, like
  `driver => null`: the dispatcher skips its credentials, `disconnect()` and
  `revoke()` revoke locally without calling the provider, and
  `validAccessTokenFor()` throws a transient `NeedsReconnectException`. 2.x
  threw `InvalidArgumentException` from all of these. Connecting still throws.
  The package logs `The connector driver for provider [...] does not name a
  ProviderConnector class` once per process: search your log for it after
  deploying.
- `dispatch_schedule` and `check_static_schedule` accept a cron expression
  (`'*/10 * * * *'`, `'@hourly'`) besides a frequency method without
  arguments. Any other value, including a scheduler macro, no longer throws:
  it logs an error naming the setting and keeps the default (every 15 minutes,
  daily). If you used a macro, set the key to `null` and schedule the command
  yourself.
- A method that only picks the days (`weekdays`, `weekends`, `mondays` to
  `sundays`) is no longer accepted either: 2.x ran the command every minute of
  those days, and 3.0 logs the same error and keeps the default. Write a cron
  expression instead, such as `'*/15 * * * 1-5'` for every 15 minutes on
  weekdays. The same goes for `daysOfMonth` without its days.
- The `log_unknown_errors` comment in a published copy says the "raw payload"
  is logged. It is not, and has not been since 1.2: the context holds error
  codes, the HTTP status and the response's keys, never the body. Correct the
  comment in your copy so nobody adds the body back.

### Revocation is recorded before the provider call

`revoke()`, and `disconnect()` when it removes a credential's last connected
account, now revoke the credential and its accounts locally first, firing
`CredentialRevoked` and `AccountRevoked`, and only then ask the provider to
invalidate the token.

- If your app caught an exception from `revoke()` or `disconnect()` to tell the
  user that the provider-side revocation failed, it no longer gets one: the
  failure is logged as a warning, `[social-tokens] {provider} token revocation
  failed`, and the credential is revoked locally all the same. Alert on that
  log line if you need to know.
- Your revocation listeners now run before the provider call. An exception one
  of them throws still reaches the caller, but only after the provider call:
  the revocation has happened by then, so do not report the disconnect as
  failed or undo anything on the strength of it.

### Revoked credentials keep no tokens

Revoking a credential (`revoke()`, `disconnect()` when it removes the
credential's last connected account, the deauthorize callbacks) now erases
its `access_token`, `refresh_token`, `expires_at`, `refresh_expires_at` and
`renew_at` in the same write that sets `status` to `revoked`. The provider's
revocation endpoint still gets the tokens, from a copy taken before.

1. **Run the migrations.**
   `2025_01_01_000010_clear_tokens_of_revoked_credentials` erases the same
   columns on every credential that is already `revoked`. It cannot be rolled
   back: if you need those tokens for anything (you should not, nothing in the
   package reads them), export them first.
2. **Listeners.** In a `CredentialRevoked` listener, synchronous or queued,
   `$event->token->access_token` and `refresh_token` are now null. If one
   calls the provider with them (say Meta's `DELETE /{user-id}/permissions`,
   which the package leaves to you because it removes the user's whole grant),
   move that call into a connector of your own: extend the connector, override
   `revoke(SocialToken $token)`, read the token there (it holds the values
   from before the revocation), and register it as the provider's `driver`.
   Or read the token yourself before calling `revoke()`.
3. **Google reconnects.** A reconnect without a new refresh token used to fall
   back on the one a revoked credential still held. Keep asking Google for
   one on every connect (`access_type=offline` and `prompt=consent`, as the
   README shows); otherwise the reconnected credential cannot be renewed.
4. **One more `CredentialRevoked` for Facebook.** `disconnect()` of the last
   Page (or Instagram account) of a Facebook user, under one owner, now also
   revokes the Meta user credential (`provider` `facebook`,
   `provider_holder_id` the Facebook user id): a listener that notifies the
   user on `CredentialRevoked` gets one event for it. Disconnecting the last
   Instagram account no longer revokes that credential while one of the user's
   Pages is still connected under that owner.

A provider answer that does not confirm a Google or TikTok revocation is now
logged: `[social-tokens] {provider} did not confirm the token revocation`.

### Store connections through the connector

`StoreConnection` now asks the connector which action stores a connection
(`Contracts\FansOutConnections`), and passes every option to it.

1. **`longLived: false` reaches Facebook and Instagram.** 2.x ignored it for
   those two providers and always exchanged the user token for a long-lived
   one. If one generic callback passes `longLived: false` for every provider,
   stop passing it for `facebook` and `instagram`, unless the token you hand
   over is already long-lived (you exchanged it yourself): Page tokens minted
   from a short-lived user token stop working within hours, and are stored as
   never expiring.
2. **`StoreAccountFromSocialite` refuses fan-out providers.** Called with
   `facebook` or `instagram`, it now throws `InvalidArgumentException` and
   writes nothing. Call `StoreConnection` (or `StoreFacebookPages` /
   `StoreInstagramAccounts`) instead. If you did call it for those providers,
   it stored the Facebook user as an account, which nothing can post with.
   Find those rows (a Page's or an Instagram account's id is never its
   Facebook user's id) and delete them, or let your app decide:

   ```php
   SocialAccount::query()->whereIn('provider', ['facebook', 'instagram'])
       ->whereColumn('provider_user_id', 'provider_holder_id')->get();
   ```

3. **If you construct `StoreConnection` yourself**, its constructor now takes
   the `ConnectorRegistry`, the container and `StoreAccountFromSocialite`.
   Resolve it from the container instead.
4. **A connector whose connections fan out** (one of your own, or a subclass of
   `FacebookConnector` / `InstagramConnector` meant to store one account per
   connection) implements `FansOutConnections::connectionAction()`, returning
   the class of an action implementing `Contracts\StoresConnections`. A
   subclass of the Meta connectors inherits theirs; override it to change it.

### Disconnected accounts stay disconnected

`disconnect()` now records when the user removed an account
(`social_accounts.disconnected_at`), and a fan-out reconnect of the same login
(`StoreConnection` for Facebook and Instagram, `StoreFacebookPages`,
`StoreInstagramAccounts`, `StoreLinkedInOrganizations`) leaves that account
out: it stays revoked, is not returned, and fires no `AccountConnected`. 2.x
made it active again at the next reconnect. A 1:1 account (TikTok, Google, X,
Threads, Instagram Login, a LinkedIn profile) is still restored when the user
reconnects it, and so is an account revoked any other way (a deauthorization
callback, `revoke()`, `markRevoked()`).

1. **Run `2025_01_01_000011_add_disconnected_at_to_social_accounts` before, or
   together with, the code deploy.** Until it has run, `disconnect()` throws a
   `QueryException` (unknown column `disconnected_at`) and changes nothing.
   Connections keep working.
2. **Accounts disconnected under 2.x have no date**, so a reconnect still
   brings them back. If your app only ever revoked accounts through
   `disconnect()` (not `revoke()`, nor the deauthorize callbacks), mark them
   once:

   ```php
   SocialAccount::query()->where('status', 'revoked')->whereNull('disconnected_at')
       ->update(['disconnected_at' => now()]);
   ```

   Otherwise pick the rows your own records say the user removed.
3. **Decide which flows restore.** Pass `restoreDisconnected: true` where the
   user means to bring removed targets back (a "Connect Facebook" button
   after removing every Page, which otherwise returns an empty collection),
   and leave it off where they refresh a login or add a Page. To bring back
   one account only, forget its disconnection before the reconnect:
   `$account->clearDisconnected()->save()`.

### Tear down the owners you delete

The owner columns carry no foreign key: deleting an owner (a workspace, a
team) leaves its accounts active and its credentials renewed, and each of
them still counts as a grant that keeps other owners' `revoke()` and
`disconnect()` of the same TikTok or Google user local. 3.0 adds
`SocialTokens::forgetOwner($owner)`, which revokes and deletes what the owner
holds without touching other owners' rows.

1. Call it before the owner is deleted, once your own rows that point at its
   accounts are dealt with (the accounts are deleted):

   ```php
   protected static function booted(): void
   {
       // forceDeleting for a model that uses SoftDeletes: a restored
       // workspace keeps its connections.
       static::deleting(function (Workspace $workspace): void {
           app(SocialTokens::class)->forgetOwner($workspace);
       });
   }
   ```

   A block closure that returns nothing, not an arrow function: Eloquent
   stops calling the `deleting` listeners at the first one that returns
   something other than null, and `forgetOwner()` returns the deleted
   accounts, so the workspace's later `deleting` listeners (an observer's
   included) would be skipped without an error.

   `CredentialRevoked` and `AccountRevoked` fire before the rows are deleted:
   a queued listener of either fails with `ModelNotFoundException` and lands
   in `failed_jobs` (see
   [Queued listeners re-read the models](#queued-listeners-re-read-the-models)).
   Listen synchronously, or keep what the listener needs in an event of your
   own.

2. Tear down the owners you deleted before. `forgetOwner()` only needs the
   owner's morph class and key, so an unsaved instance carrying the old id
   will do:

   ```php
   $type = (new Workspace)->getMorphClass();
   $gone = fn ($query) => $query->where('ownable_type', $type)
       ->whereNotIn('ownable_id', Workspace::query()->select('id'))
       ->distinct()->pluck('ownable_id');

   $gone(SocialAccount::query())->merge($gone(SocialToken::query()))->unique()
       ->each(fn ($id) => app(SocialTokens::class)->forgetOwner((new Workspace)->forceFill(['id' => $id])));
   ```

   Add `withTrashed()` to the subquery if soft-deleted workspaces should keep
   their connections.

### Owner-less rows you attached by hand

2.0's notes said to attach an owner-less row to an owner with
`$account->ownable()->associate($owner)`. Saved, that moved the account but
not its credential, which stayed owner-less: the owner's next reconnect stored
a second credential and left the first behind, and the Meta user token behind
a Facebook Page attached that way was no longer renewed. 3.0 adds
`SocialTokens::adopt($account, $owner)` (see
[README, Owners](README.md#owners)). Given an account that already belongs to
the owner, it moves the owner-less credentials it depends on, so this
finishes the accounts attached before. It pages with `eachById()`: each
adopted account leaves the query, and `each()`, which pages by offset, would
skip some once there are more than a thousand.

```php
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;

SocialAccount::query()
    ->whereNotNull('ownable_type')
    ->whereHas('credential', fn ($credential) => $credential->whereNull('ownable_type'))
    ->with('ownable')
    ->eachById(function (SocialAccount $account) {
        if ($account->ownable !== null) { // the owner still exists
            app(SocialTokens::class)->adopt($account, $account->ownable);
        }
    });
```

A credential still used by an owner-less account, or one the owner already
holds a copy of (a reconnect since), stays as it is; a credential nothing uses
any more is pruned (see the next section).

### Unused credentials are unscheduled, then pruned

Two changes keep `social_tokens` to the credentials something uses.

- `social-tokens:dispatch-renewals` sets `renew_at` to null on a credential
  whose renewal is due but that no active account depends on, instead of
  reading it again on every run. A reconnect schedules it again, and so does
  a re-sync of LinkedIn organizations with the stored member token.
- The new `social-tokens:prune` command, scheduled daily, deletes the
  credentials that are revoked, flagged `needs_reconnect`, or used by no
  connected account, once they have not changed for `prune_after_days` (30).
  Accounts are never deleted: one whose credential goes keeps its row, with
  `social_token_id` null.

1. **Choose a retention, or turn it off.** Both keys default when your
   published config lacks them; add them to set them:

   ```php
   'prune_schedule' => 'daily',   // null: never prune
   'prune_after_days' => 30,      // at least 1
   ```

   The first run deletes every credential that already qualifies, for
   instance all those revoked more than 30 days ago. Keep `prune_schedule` at
   `null` if you report on revoked credentials or point at their ids from your
   own tables; otherwise copy what you need first.
2. **Read the credential null-safely.** `$account->credential` is null once
   the credential is pruned (as after a data deletion request): use
   `$account->credential?->last_error`, or `effectiveStatus()`, which already
   reads a missing credential as `needs_reconnect`. Such an account no
   longer keeps its holder's other credentials in use: a Facebook Page whose
   Page token was pruned stops keeping the Meta user token renewed, and
   `disconnect()` of a LinkedIn profile revokes the member's personal
   credential even while organizations whose own credential was pruned stay
   `active`. Ask the user to reconnect such an account; nothing else to
   change.
3. **Reactivating an account by hand** (`UPDATE social_accounts SET status =
   'active'`, as the 1.2 notes describe): set its credential's `renew_at` to
   now as well. Otherwise the dispatcher may have unscheduled it, and it is
   renewed only once its token expires and `validAccessTokenFor()` is asked
   for it.

### Rotating `APP_KEY` (optional)

If you rotated `APP_KEY` and still list the old key in `APP_PREVIOUS_KEYS`
because some tokens were never written again (Google refresh tokens, Facebook
Page tokens), make sure every process runs with the new key (restart your
queue workers), run `php artisan social-tokens:reencrypt`, then drop the old
key once it exits successfully. When it fails because credentials changed
while it ran, run it again. See the README, "Rotating APP_KEY".

### Review your `reportRejected()` calls

2.x's README told you to pass `terminal: true` for any Meta 190/102 and for an
OAuth `invalid_grant` / `invalid_token`. Both flag healthy credentials, so
change the `terminal:` argument where you report a publish rejection:

1. Keep `terminal: true` only for an error that proves the credential dead: a
   Meta (Facebook, Instagram, Threads) code 190 or 102 **with** an
   `error_subcode` of 458, 459, 460, 463, 464, 467 or 492, or, for LinkedIn
   without refresh tokens, `REVOKED_ACCESS_TOKEN` or `EXPIRED_ACCESS_TOKEN`.
2. Pass `terminal: false` for a Meta 190/102 **without** a subcode, for a
   LinkedIn `REVOKED_ACCESS_TOKEN` with refresh tokens, and for any other
   ambiguous 401 from Meta, Threads or LinkedIn.
3. Stop reporting a 401 `invalid_token` from Google, TikTok or X, or a
   LinkedIn `EXPIRED_ACCESS_TOKEN` with refresh tokens: call
   `validAccessTokenFor($account)` again, which renews the expired access
   token, and retry the publish. A refresh token that really died is flagged
   by the renewal itself.

`reportRejected(terminal: false)` itself changed too. It flags only when the
provider's answer is definitive (a Meta session subcode, LinkedIn's
`revoked`). A bare 190/102 now records one strike against the token and the
call returns `false` where 2.x returned `true`. Only
`social-tokens:check-static` confirms the strike, flagging the credential if
Meta still rejects the token. That run checks Facebook Page tokens always,
and renewable credentials (the Meta user token behind Instagram, Threads)
only with `SOCIAL_TOKENS_CHECK_RENEWABLE=true`, so turn it on if you relied
on these reports to cut off renewable credentials. If you set
`check_static_schedule` to `null`, set it back (`'daily'` is the default) or
schedule `social-tokens:check-static` yourself: with no run, a bare 190/102
report never flags the credential, where 2.x flagged it at once, and a static
Page token is never renewed, so nothing else catches it. Calling
`reportRejected()` again does not confirm the strike: retries seconds apart
would see the same Meta incident.

Credentials 2.x flagged because of an `invalid_token` report on Google, TikTok
or X may still have a working refresh token. To give them a chance before
asking those users to reconnect, reactivate them and let the dispatcher renew
them at once (a refresh token that really died is flagged again, with a new
`CredentialNeedsReconnect`). Match `last_error` against the reason your app
reported:

```php
SocialToken::query()
    ->whereIn('provider', ['google', 'tiktok', 'x'])
    ->where('status', 'needs_reconnect')
    ->where('last_error', 'like', '%invalid_token%')
    ->update(['status' => 'active', 'last_error' => null, 'renew_at' => now()]);
```

### Checks and rejection reports flag fewer credentials

Nothing to change in your code. Some credentials 2.x flagged `needs_reconnect`
(firing `CredentialNeedsReconnect`) now stay active:

- `social-tokens:check-static` applies its breaker to each provider: when more
  than `max_terminal_ratio` (20%) of at least `min_checked` (10) credentials of
  one provider are rejected in a run, none of that provider's credentials is
  flagged and the run logs "[social-tokens] check-static skipped {provider}:
  too many of its credentials rejected at once" (critical, with `provider`,
  `checked`, `terminal`). The other providers are still flagged, and the
  command exits with a failure code. If you alert on `check-static aborted`,
  alert on this message too. The run-wide breaker is unchanged.
- `check-static` no longer checks a credential whose accounts are all flagged
  or revoked (a Facebook Page the user no longer manages, flagged at their last
  connect). It used to flag that Page's credential too, with a second
  `CredentialNeedsReconnect` for a Page your app had already been told about.
- A reconnection or a successful renewal clears the credential's
  `check-static` strike (`failed_checks`). In 2.x the new token inherited it,
  and was flagged on its first unconfirmed rejection. Rows may still hold a
  strike inherited that way; to clear them, run once
  `SocialToken::query()->where('failed_checks', '>', 0)->update(['failed_checks' => 0]);`.
  The cost is small: a token that really is dead takes one more daily run to
  be flagged.
- Facebook Page credentials now get `last_renewed_at` (the token's issue
  date) at each connection; rows stored by 2.x keep `null` until the user
  reconnects.
- LinkedIn token introspection flags a credential only when LinkedIn answers
  `status: revoked`, or `expired` with refresh tokens off. A bare
  `{"active": false}`, which LinkedIn also sends for a token issued to another
  LinkedIn app than the configured client, is logged as
  `Uncatalogued credential check error` and never flagged. If every LinkedIn
  credential logs it, set `client_id` / `client_secret` on the `linkedin`
  connector (or `services.linkedin`) to the app that issued the member tokens.
  2.x flagged those credentials with the reason `LinkedIn token inactive.`;
  once the client is right, you can reactivate the ones whose users have not
  reconnected yet (no event fires, and their account rows need no change):
  `SocialToken::query()->where('provider', 'linkedin')->where('status', 'needs_reconnect')->where('last_error', 'LinkedIn token inactive.')->update(['status' => 'active', 'last_error' => null]);`.
  With `check_renewable` on, the next `check-static` run checks them again
  and flags the ones LinkedIn reports revoked.
- A Meta answer about the app's own credentials (a wrong or rotated app
  secret, a wrong app id, a refused `appsecret_proof`) no longer counts as a
  transient renewal error. Like `invalid_client` on the other providers, it
  logs the critical "facebook rejected the app's OAuth client: check its
  client_id/client_secret" and pauses that provider's renewals for 15
  minutes. Route that log line to whoever holds the Meta app secrets. After
  rotating the secret, restart your queue workers
  (`php artisan queue:restart`) so they read it.

### Renewals, transactions and the cache store

The renewal lock and the re-read under it changed, so a credential is no longer
refreshed twice or renewed over a reconnect. Check three things:

1. **Your cache store is shared.** The renewal lock lives in the default cache
   store. 2.x's README listed `file` as fine; it is not when web requests,
   queue workers or the scheduler run on more than one server, since each host
   then takes its own lock and both can refresh the same TikTok or X token.
   On such a setup set the default store (`CACHE_STORE`, or `CACHE_DRIVER` if
   your `config/cache.php` still reads it) to `redis`, `memcached`, `database`
   or `dynamodb`, on a server every host reaches, and restart the workers
   (`php artisan queue:restart`). A single server can keep `file`. The `array`
   store locks nothing across processes, even on one server (a queue worker
   and a web request each keep their own): if you set `CACHE_STORE=array`
   outside tests to turn caching off, switch to one of the stores above.
2. **You ask for tokens outside your own transactions.** Under the lock,
   `renewCredential()` now re-reads the credential with `SELECT ... FOR UPDATE`.
   Inside your `DB::transaction()`:
   - that row stays locked until the transaction ends, so other renewals,
     reconnects and status changes of that credential wait for it. Another
     renewal waits as long as the database lets it: MySQL's
     `innodb_lock_wait_timeout` (50 seconds by default); on PostgreSQL 30
     seconds, or until your transaction ends if that renewal itself runs inside
     a transaction and no `lock_timeout` is set. A wait of more than 30 seconds
     ends in a transient failure instead of a renewal;
   - a rollback discards the token the renewal stored (for TikTok and X, the
     provider has already replaced the old refresh token, so the credential
     dies at its next renewal);
   - a lock wait timeout or a deadlock on that read throws its `QueryException`
     (a `DeadlockException` from a nested `DB::transaction()`), where outside a
     transaction it is a transient failure: the database may have rolled your
     transaction back already, so `validAccessTokenFor()` does not turn it into
     a transient `NeedsReconnectException` you would catch and carry on from.
     Let your `DB::transaction()` roll back, or retry it
     (`DB::transaction($callback, 3)` retries a deadlock).

   Call `validAccessTokenFor()` before opening the transaction. To find the
   places, look for the new warning `[social-tokens] Renewing a single-use
   refresh token inside a database transaction` in your logs. Tests that use
   `RefreshDatabase` or `DatabaseTransactions` run inside a transaction too, so
   a test that renews a TikTok or X credential logs it as well; there it does
   no harm.
3. **If you call `renewCredential()` or `applyRenewal()` yourself.**
   - After a Terminal result, flag the credential only while it holds the
     refused token: `$token->markNeedsReconnectOnce($result->reason, $token->access_token)`
     instead of `markNeedsReconnect($result->reason)`. `renewCredential()`
     reloads the `$token` you pass under the lock, so `$token->access_token`
     is the token the provider refused, and a credential the user reconnected
     meanwhile is left alone.
   - `applyRenewal()` now writes only while the stored credential still holds
     the access and refresh tokens the instance was loaded with (the same
     tokens re-encrypted under a new `APP_KEY` still count). Load the
     credential right before you refresh it, not earlier. Use
     `tryApplyRenewal()` to know whether the renewal was stored; when it was
     not, the instance holds the stored row. A credential deleted meanwhile
     throws `ModelNotFoundException`.

There is nothing to migrate.

### LinkedIn scopes are split

`SocialToken::normaliseScopes()` now splits an element holding several
scopes on commas and whitespace. LinkedIn lists the granted scopes
comma-separated and Socialite's LinkedIn drivers split them on spaces, so a
LinkedIn profile stored through `StoreConnection` or `StoreAccountFromSocialite`
got one element holding them all (`['email,openid,profile,w_member_social']`),
on the account and on its credential: `hasScope('w_member_social')` answered
`false`. New connections are stored one scope per element. Rows stored
before keep the joined element until the member reconnects; to fix them now,
run once:

```php
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

foreach ([SocialAccount::class, SocialToken::class] as $model) {
    $model::query()->where('provider', 'linkedin')->whereNotNull('scopes')->eachById(function ($row) {
        if (($scopes = SocialToken::normaliseScopes($row->scopes)) !== null) {
            $row->forceFill(['scopes' => $scopes])->saveQuietly();
        }
    });
}
```

If your own code splits a scope string before handing it to the package
(`explode(',', $token['scope'])` for `StoreLinkedInOrganizations`), it can
keep doing so, or pass the string as a single element.

### LinkedIn credentials without a refresh token

Nothing to change unless you set `LINKEDIN_REFRESH_ENABLED=true`. With it, a
LinkedIn credential that holds no refresh token is now renewed as if refresh
were off: when its window opens (5 days before expiry)
`CredentialExpiringSoon` fires, it keeps posting, and it is flagged
`needs_reconnect` (reason `No refresh token (re-authorisation required).`)
only once its token has expired. 2.x flagged it at once, five days early,
with no warning. If you prompt members to reconnect from
`CredentialNeedsReconnect` only, listen for `CredentialExpiringSoon` too.
LinkedIn introspection (`check-static` with `check_renewable`,
`reportRejected(terminal: false)`) answering `expired` for such a credential
now flags it, as it does with refresh off.

To list the credentials concerned:

```php
SocialToken::query()->where('provider', 'linkedin')->whereNull('refresh_token')->where('status', 'active')->get();
```

The refresh token's own expiry (365 days) is not on the Socialite contract,
and core Socialite's LinkedIn drivers drop it. If you exchange the code
yourself, pass it: `refreshExpiresAt:` on `StoreConnection::handle()` and
`StoreAccountFromSocialite::handle()` (new), and on
`StoreLinkedInOrganizations::handle()` (the README example now does). Without
it, the first renewal records it.

### LinkedIn profiles and organizations have separate credentials

A LinkedIn member's personal profile (stored through `StoreConnection` /
`StoreAccountFromSocialite`) and their organizations (stored through
`StoreLinkedInOrganizations`) come from two authorizations with two tokens and
two scope sets. 2.x stored both on one `(linkedin, member id, owner)`
credential, so whichever flow ran last replaced the other's access token,
refresh token, expiry and scopes: after a member connected their profile,
every post as their organizations failed with a 403, while the organization
rows still listed `w_organization_social`. The organizations now post with a
credential of their own, with `purpose = 'organizations'`
(`StoreLinkedInOrganizations::PURPOSE`); the profile keeps the default one
(`purpose = ''`).

**What the migration does.** `2025_01_01_000008` splits every LinkedIn
credential that backs both the member's personal row and organization rows:
it copies the credential with purpose `organizations` (same tokens, expiry,
schedule, scopes and owner) and points the organization rows at the copy.
A credential that backs organization rows only takes the purpose in place.
Nothing else changes. Right after the upgrade, both credentials of a member
therefore hold the same token; they part as soon as the member goes through
either flow again.

**What to check in your code.**

1. **Credential lookups by holder.** A LinkedIn member can now have two
   credentials per owner. Name the one you mean:

   ```php
   // 2.x
   SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)->first();

   // 3.0: the organizations' credential...
   SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)
       ->forPurpose(StoreLinkedInOrganizations::PURPOSE)->first();
   // ...or the personal profile's
   SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)
       ->forPurpose()->first();
   ```

   Better still, go through the account: `$account->credential`.
2. **Credentials you write yourself.** Keys of `updateOrCreate()` /
   `firstOrCreate()` on `social_tokens` by `(provider, provider_holder_id,
   owner)` can now match either credential: add `'purpose' => ''` to them, or
   use `SocialToken::persistCredential()`, whose keys default to the default
   purpose.
3. **Reconnect prompts.** The two credentials renew, warn and get flagged on
   their own. A member who has both gets one `CredentialExpiringSoon` (and,
   once it expires, one `CredentialNeedsReconnect`) per credential, and must
   reconnect each through its own flow: send them to your organization
   exchange when `$event->token->purpose === StoreLinkedInOrganizations::PURPOSE`,
   and to the Socialite redirect otherwise. Reconnecting the profile no longer
   refreshes the organizations' token, nor the reverse.
4. **Disconnecting.** `disconnect()` on the personal row now revokes the
   profile's credential (it was kept alive by the organizations sharing it),
   and disconnecting the last organization revokes the organizations' one.
   `revoke($organization->credential)` revokes the organization rows only, no
   longer the personal row. To disconnect a member entirely, revoke both
   credentials. The provider-side revocation is skipped while the member's
   other credential is still active, as it is for another owner's.
5. **Scopes.** LinkedIn documents that authorising the same LinkedIn app with
   a different set of scopes invalidates the member's earlier access tokens.
   If both flows use one LinkedIn app, request the same scopes in both (the
   union), or use a separate app for each, or the first flow's token stops
   working when the member goes through the second.
6. **The unique key** on `social_tokens` is now
   `social_tokens_identity_purpose_unique`. Code or migrations of your own that
   name `social_tokens_owner_identity_unique` must use the new name.

**Rolling back.** The migration's `down()` refuses while a member has two
credentials for one owner, since 2.x keeps one and would pick either. To roll
back, first point each such member's organization rows back at the default
credential and delete the `organizations` one, then roll back.

### Re-syncing LinkedIn organizations

If you call `StoreLinkedInOrganizations::handle()` again with the stored
member token to pick up new Pages, leave `expiresAt` out (or pass the stored
`$credential->expires_at`): the token's expiry, schedule and issue date are
now kept, so a warning already sent is not sent again. Do not pass a new
`now()->addDays(60)` for a token you did not just receive: it moves the
expiry warning past the token's real end.

Read that token from the organizations' credential. A 2.x lookup by member id
and owner alone can now return the personal profile's credential (see "LinkedIn
profiles and organizations have separate credentials" above), whose token
cannot post as the organizations: passed here, it would be taken for a new
token and replace theirs, with no expiry (without a refresh token, that
credential would then never be warned about before it dies).

```php
// 2.x
$credential = SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)->first();

// 3.0
$credential = $organizationAccount->credential; // any of the member's organization rows, or:
$credential = SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)
    ->forPurpose(StoreLinkedInOrganizations::PURPOSE)->first();

app(StoreLinkedInOrganizations::class)->handle(
    accessToken: $credential->access_token,
    memberId: $memberId,
    owner: $owner,
);
```

2.x erased the expiry of a credential re-synced without `expiresAt`. To find
those rows (no expiry and no schedule, so only `check-static` notices them
once they die):

```php
SocialToken::query()->where('provider', 'linkedin')->where('status', 'active')
    ->whereNull('expires_at')->whereNull('renew_at')->get();
```

Their real expiry is lost. Ask those members to reconnect, or keep
`check_static_schedule` on (the default): `check-static` checks such a row as
a static credential, and flags it once LinkedIn reports its token expired or
revoked, with no warning beforehand.

### If you wrote a connector

- Mark every parameter of your connector that carries a token or a secret
  `#[\SensitiveParameter]` (PHP 8.2+), as the built-in connectors now do.
  The attribute on `ProviderConnector::exchangeForLongLived()` is not
  inherited by your implementation. Without it, an exception thrown while the
  method is on the stack records the token in its trace, where an error
  tracker can collect it.
- `revoke()` must not throw. The package now revokes the credential and its
  accounts locally first, then calls your connector's `revoke()` and logs
  anything that escapes, so a throwing connector no longer blocks a
  disconnect, but it is still a bug. Read the token inside the `try` as well:
  reading `$token->access_token` decrypts it, and throws after an `APP_KEY`
  change. Log a failure with `$this->logRevokeFailure($token, $e)`.
- If whether a credential can be renewed depends on what it holds, override
  `renewalStrategyFor(SocialToken $token)` (it defaults to
  `renewalStrategy()`). The package now asks it wherever it decides about a
  stored credential. If your own code decides with
  `$connector->renewalStrategy()` about a stored credential, ask
  `$token->renewalStrategyWith($connector)` instead.
- Your connector's `revoke()` now runs after `CredentialRevoked` and
  `AccountRevoked` have fired, on a copy of the credential taken before them,
  and the stored status is already `revoked`, with the tokens erased. Do not
  rely on the status being active there, and read the tokens from the
  instance you are given, never from the database. Log an answer that does
  not confirm the revocation with `$this->logUnconfirmedRevoke($token,
  $response)`. It runs even when one of those listeners threw; the
  listener's exception then reaches the caller of `revoke()` or
  `disconnect()` after your connector returns.
- If you extend `ThreadsConnector`: it now extends
  `Connectors\LongLivedTokenConnector`, which holds the refresh, the
  connect-time exchange and the `/me` check. Override `refreshUrl()`,
  `exchangeUrl()`, `meUrl()` or `exchangeParameters()` rather than the
  `REFRESH_URL`, `EXCHANGE_URL` and `ME_URL` constants alone (they are still
  read through `static::`). `refreshWithToken()` is protected.
- If you extend `FacebookConnector` or `InstagramConnector`: both now extend
  `Connectors\MetaGraphConnector`, which holds `refreshCredential()`,
  `renewalStrategy()`, `leadTime()`, `appAccessToken()` and `fetchMe()`, and
  the `fb_exchange_token` call as `exchangeUserToken()` (a `RenewalResult`).
  Build Graph URLs with `$this->graphUrl('me/accounts')`, which applies the
  block's `graph_version` or `MetaGraphConnector::DEFAULT_GRAPH_VERSION`.
- **The package no longer calls `FacebookConnector::extendUserToken()` or
  `grantedScopesByAccount()`**, at connect or at renewal. Both still exist,
  for your own code, but an override of either is silently skipped: move it
  to `exchangeUserToken()` (return a `RenewalResult`; the connect-time
  actions and `refreshCredential()` both call it), and to `debugToken()` or
  the static `grantedScopesFrom($data, $accountIds)` (the actions call it,
  and `tokenLifetime()`, on your configured connector class). Search your
  connector subclasses for `function extendUserToken` and
  `function grantedScopesByAccount`.
- `InstagramConnector` reads an `fb_exchange_token` reply as
  `FacebookConnector` does: a reply without a token fails with "Malformed
  token extension response." (it was "Malformed response, no
  access_token."). Update anything that matches the old text in
  `last_error`, a caught `RuntimeException` message or your logs.

## From 1.x to 2.0

2.0 scopes identity to the **owner** you pass when connecting. An account is
unique per *(provider, external id, owner)* and a credential per *(provider,
external holder, owner)*. In 1.x, when a second owner connected an account a first
owner already had, the single row and its credential silently moved to the
second owner. The first owner lost it, with no event.

### Coming from 1.0 or 1.1

1.2.0 was a minor release, yet it changed behaviour your code may rely on, and
its one-off steps were only in the changelog. Apply them with this guide. The
reasons are in [CHANGELOG.md](CHANGELOG.md), under 1.2.0.

**Before you deploy:**

- **Rotate your Meta and Threads app secrets** if your logs, `failed_jobs` or
  error tracker may hold `client_secret=`: up to 1.1, a connection failure put
  the request URL, query string included, in the renewal reason, and from
  there in exceptions and `last_error`. Redact the stored reasons once 3.0 is
  deployed (see the one-off data fixes below): 1.1 keeps writing them
  unredacted until then.
- **Every provider you connect needs a connector** (breaking).
  `StoreConnection` and `StoreAccountFromSocialite` throw
  `InvalidArgumentException` for a provider without one (a missing key, or
  `driver => null`), where 1.1 stored the account as static and it broke at
  expiry. Configure one (`'youtube' => ['driver' => GoogleConnector::class]`
  reads `services.youtube`), or stop routing that provider through the
  package.
- **To remove one account, call `disconnect($account)`**, not
  `revoke($account->credential)` as 1.1's README suggested: `revoke()` revokes
  the whole login, every Instagram account of the Facebook user, every
  organization of the LinkedIn member.
- **If you extend `SocialTokens`** and override `validAccessTokenFor()` or
  `renewCredential()`, add the `int $minValiditySeconds = 30` parameter.

**What changed under your code:**

- `markNeedsReconnect()` and `markRevoked()` no longer `save()` the model:
  save your other changes yourself. Both act only on a real change, so
  `CredentialNeedsReconnect`, `CredentialRevoked`, `AccountNeedsReconnect` and
  `AccountRevoked` fire once per change, and a revoked row is never flagged.
- `renewCredential()` throws `InvalidArgumentException` for a token never
  saved (it inserted it), and returns `terminalFailure('Credential is …')`
  without calling the provider for one that is not active.
  `validAccessTokenFor()` re-reads the account and its credential (one or two
  queries more), and throws `NeedsReconnectException` for an account deleted
  since it was loaded.
- `SocialToken::toArray()` / `toJson()`, and an account serialised with its
  credential, leave out `access_token` and `refresh_token`: read them as
  attributes, or `makeVisible()`.
- A provider outage no longer flags a refresh-token credential: an `active`
  credential with a `last_error` is still retrying, and only the status says
  it is broken. An app-side OAuth error (`invalid_client`…) is logged as
  critical instead of flagging every credential.
- `CredentialExpiringSoon` can come before a refresh token expires, and after
  a Meta or Threads renewal that did not extend the token. `renew_at` can be
  earlier than before, and the connect actions keep a stored refresh token.
- `scopes` can be `null` (unknown) where it was `[]` or `[""]` (use
  `scopesKnown()`); `profile` is merged, not replaced; `last_renewed_at` is the
  issue date of the current token; Meta reasons read
  `190/460 OAuthException: …` when Meta sends a subcode.
- `check-static` flags a bare Meta 190/102 on the second consecutive run, and
  no longer flags permission errors; a custom `ChecksCredential` connector
  returns `terminalFailure($reason, ['definitive' => true])` to flag at once.
  Each Facebook Page fires its own `CredentialNeedsReconnect`. The command
  runs in the background.
- A Facebook connection stores the Meta user credential, and the dispatcher
  renews it: expect `Credential*` events for a credential whose `accounts` is
  empty.
- A LinkedIn organization the member holds only through a non-posting role
  is flagged at the member's next connect (`connectors.linkedin.posting_roles`).

**One-off data fixes, where they apply** (in `php artisan tinker`, once 3.0
is deployed and before its first `social-tokens:prune`, which deletes
credentials flagged `needs_reconnect` for 30 days). They page with
`eachById()`, not `each()`: `each()` pages by offset, and skips rows once a
page's changes take rows out of the query.

- Redact the request URLs 1.0 and 1.1 left in `last_error` (their query
  strings can hold `client_secret=`). Run it after the deploy:
  `redactQueryStrings()` first exists in 1.2, and 1.1 keeps writing
  unredacted reasons until 3.0 is live.

  ```php
  use Pr4w\SocialTokens\Connectors\AbstractConnector;
  use Pr4w\SocialTokens\Models\SocialAccount;
  use Pr4w\SocialTokens\Models\SocialToken;

  foreach ([SocialToken::class, SocialAccount::class] as $model) {
      $model::query()->whereNotNull('last_error')->eachById(fn ($row) => $row
          ->forceFill(['last_error' => AbstractConnector::redactQueryStrings($row->last_error)])
          ->saveQuietly());
  }
  ```

- Meta user credentials stored without `expires_at` and `renew_at` (Meta
  sent no `expires_in`, or `StoreInstagramAccounts` ran with `extend: false`)
  are static: nothing renews them, and they die about 60 days after they were
  issued. Schedule them once. The first renewal reads the token's lifetime: a
  token Meta reports as never expiring goes back to static, watched by
  `check-static`, for the cost of one call.

  ```php
  SocialToken::where('provider', 'facebook')->whereNull('expires_at')->whereNull('renew_at')
      ->whereHas('accounts', fn ($q) => $q->where('provider', 'instagram')) // not the Page tokens
      ->update(['renew_at' => now()]);
  ```

- Credentials 1.1 flagged for an app-side error can be reactivated once the
  app's client id and secret are fixed; one whose refresh token is really dead
  is flagged again by its next renewal:

  ```php
  use Pr4w\SocialTokens\Enums\AccountStatus;

  SocialToken::query()->where('status', AccountStatus::NeedsReconnect->value)
      ->where(fn ($query) => $query->where('last_error', 'like', 'invalid_client:%')
          ->orWhere('last_error', 'like', 'unauthorized_client:%')
          ->orWhere('last_error', 'like', 'invalid_request:%'))
      ->eachById(function (SocialToken $token) {
          if (! preg_match('/refresh[\s_-]?token/i', (string) $token->last_error)) {
              $token->forceFill(['status' => AccountStatus::Active, 'last_error' => null, 'renew_at' => now()])->save();
          }
      });
  ```

- To warn about refresh tokens that expire before their next renewal now,
  rather than after it (a credential with `refresh_expires_at`):

  ```php
  $registry = app(\Pr4w\SocialTokens\Support\ConnectorRegistry::class);

  SocialToken::where('status', 'active')->whereNotNull('refresh_expires_at')->whereNotNull('renew_at')
      ->eachById(function (SocialToken $token) use ($registry) {
          if ($registry->has($token->provider)) {
              $warnAt = $token->refresh_expires_at->copy()->sub($registry->for($token->provider)->leadTime());
              $token->forceFill(['renew_at' => $token->renew_at->min($warnAt)])->save();
          }
      });
  ```

- Personal LinkedIn rows that 1.1 flagged as an organization the member no
  longer administers: reconnect the profile, or, once you checked the linked
  credential is active:

  ```sql
  UPDATE social_accounts SET status = 'active', last_error = NULL
  WHERE provider = 'linkedin' AND provider_user_id = provider_holder_id
    AND status = 'needs_reconnect'
    AND last_error = 'Organization no longer administered by the connected LinkedIn member.';
  ```

- Optionally, normalise the scopes stored before 1.2: split joined
  elements, and turn `[""]` into `null` (unknown). An empty list is left
  alone: Meta's `[]` can be a real "none".

  ```php
  foreach ([SocialAccount::class, SocialToken::class] as $model) {
      $model::whereNotNull('scopes')->eachById(function ($row) {
          if ($row->scopes !== [] && ($scopes = SocialToken::normaliseScopes($row->scopes)) !== $row->scopes) {
              $row->forceFill(['scopes' => $scopes])->saveQuietly();
          }
      });
  }
  ```

The `social_tokens.failed_checks` column 1.2 added comes with the other
migrations below.

### 1. Update the package, straight to 3.0

```bash
composer require pr4w/laravel-social-tokens:^3.0
```

Go straight to 3.0 and follow [From 2.x to 3.0](#from-2x-to-30) too. The
safeguards below exist only in 3.0's copy of
`2025_01_01_000007_scope_identity_to_owner`: 2.0.0's cannot be resumed after a
failure, fails on MySQL with uuid or ulid owners after dropping the old keys,
and runs as one long transaction on PostgreSQL. If 2.0.0's copy already failed
on your database, 3.0's finishes it (see "If it fails partway" below).

### 2. Run the migration, on a copy of production first

```bash
php artisan migrate
```

If you published the package migrations, publish them again first (see
[From 2.x to 3.0](#from-2x-to-30)), and replace any copy of 2.0's
`2025_01_01_000007_scope_identity_to_owner.php` you have not run yet with
3.0's. The migration:

- adds `ownable_type` / `ownable_id` to `social_tokens`;
- gives every credential the owner of the accounts it backs. A Meta user
  credential with no account pointing at it takes the owner of the Facebook Pages
  that name it as their holder. A credential backing no owned account, or with
  no account pointing at it and no `provider_holder_id`, stays owner-less;
- splits a credential that backs the accounts of several owners (possible in
  1.x after a partial reconnect) into one copy per owner, and repoints each
  owner's accounts to their copy. The copies keep the same encrypted tokens;
- replaces the unique keys:
  - `social_accounts`: `(provider, provider_user_id)` becomes
    `(provider, provider_user_id, ownable_type, ownable_id)`;
  - `social_tokens`: `(provider, provider_holder_id)` becomes
    `(provider, provider_holder_id, ownable_type, ownable_id)`.

It can be rolled back only while no two owners share an external account. Once
they do, `down()` refuses and says why.

**Checks first.** Before changing anything, the migration checks two things,
and if either fails it says why and changes nothing:

- **MySQL/MariaDB:** that the new unique keys fit InnoDB's 3072-byte limit.
  With 3.0's `2025_01_01_000006_shorten_provider_columns`, which runs first,
  they fit with bigint, uuid and ulid owner ids alike.
- **Owner key type:** that the new `social_tokens.ownable_id` can hold every
  owner id of your accounts. It takes the type of
  `social_accounts.ownable_id`: integers stay integers, a uuid column (native
  or `char(36)`) gives uuid, a `char(26)` column ulid. Any other string column
  (a `varchar` you changed yourself) follows `Schema::morphUsingUuids()` /
  `Schema::morphUsingUlids()`, and is refused while morph keys default to
  integers (except on SQLite, which enforces no type).

**`migrate --pretend`** reads no columns or rows (every query comes back
empty), so it cannot run these checks, nor the 50-character check of
`000006_shorten_provider_columns`. It lists the statements for a schema the
package created: the owner columns in your default morph key type (the real run
takes the type of `social_accounts.ownable_id`), and `provider` shortened on
both tables (the real run skips a column already at most 50 characters). Only
a real run on a copy of production exercises the checks.

**Index names on a prefixed install:** with a table prefix and
`prefix_indexes`, the new keys carry the prefix
(`acme_social_accounts_owner_identity_unique`), like the indexes Laravel names
itself. Adjust anything that refers to them by name.

**If it fails partway** (a lost connection, a deploy timeout), fix the cause and
run `php artisan migrate` again: every step is guarded and resumes where it
stopped. The backfill commits in chunks of 100 credentials, and a credential is
split in one transaction or not at all.

That includes resuming a failed 2.0.0 run. On MySQL/MariaDB, a 2.0.0 copy that
stopped partway left `social_tokens.ownable_type` / `ownable_id` added and the
old credential key dropped: update to 3.0 and run `php artisan migrate` again.
2.0.0 gave those columns your default morph key type, not the type of
`social_accounts.ownable_id`, so with uuid or ulid owners declared per column
they were bigint and its backfill failed with "Data truncated". Columns of
another type than the accounts' that are still empty (the usual case: that
backfill stopped on the first owned credential) are dropped and made again with
the right type. Columns that already hold owners are kept if every owner id
fits them. If not, the migration refuses, changing nothing, and names the
`ALTER TABLE` that gives `social_tokens.ownable_id` the type of
`social_accounts.ownable_id`: run it, check the owners already stored there,
then migrate again.

**PostgreSQL:** the migration does not run as one transaction, so the running
app is never locked out of `social_tokens` for the whole backfill. Each schema
step commits on its own and takes its lock only briefly. The flip side: a
failure no longer rolls everything back, so run it again as above.

**Owner-less rows:** databases treat NULLs as distinct in a unique index, so rows
without an owner are no longer deduplicated by the database, only by the
package's upserts. Two simultaneous owner-less connections of the same account
could create a duplicate. On PostgreSQL 15+ you can recreate the indexes with
`NULLS NOT DISTINCT` if that matters to you.

### 3. Scope your lookups to the owner

`(provider, provider_user_id)` no longer identifies a single row. Every query
that looks an account or a credential up by its external id must name the owner:

```php
// before
SocialAccount::where('provider', 'facebook')->where('provider_user_id', $pageId)->first();

// after
SocialAccount::ownedBy($owner)->where('provider', 'facebook')->where('provider_user_id', $pageId)->first();
SocialToken::ownedBy($owner)->where('provider', 'tiktok')->where('provider_holder_id', $openId)->forPurpose()->first();
SocialAccount::ownedBy(null); // owner-less rows
```

Look especially for `->first()`, `->sole()` and `firstWhere()` on those columns,
and for unique validation rules (`Rule::unique('social_accounts', …)`). In 3.0
a credential lookup also names its purpose (`forPurpose()`): a LinkedIn member
has one credential for the personal profile and one for the organizations (see
[From 2.x to 3.0](#linkedin-profiles-and-organizations-have-separate-credentials)).

### 4. Behaviour changes to know

- **Each owner renews its own credential.** Two owners of the same TikTok,
  Google or LinkedIn account mean two grants, two refresh tokens and two
  renewals. Some providers cap the refresh tokens per user and app (Google: 100).
- **No implicit adoption.** In 1.x, connecting with an owner an account stored
  without one attached that row to the owner. Now it creates a separate row.
  To give an owner-less row an owner, call
  `app(SocialTokens::class)->adopt($account, $owner)` before that owner
  reconnects the account. It moves the account and the credentials it
  depends on, in one transaction, and refuses an account the owner already
  has: disconnect that owner-less row instead (`disconnect($account)`). Do not
  use `$account->ownable()->associate($owner)`, which 2.0's notes suggested:
  it moves the account only, and the owner's next reconnect stores a second
  credential next to the owner-less one.
- **Reconciliation stays per external user.** When a Facebook user no longer
  manages a Page, or a LinkedIn member no longer administers an organization,
  every row of that user is flagged, whatever the owner. Meta and LinkedIn grant
  access per user, not per connection.
- **Provider-side revocation is skipped while another owner holds a grant.**
  `revoke()` and `disconnect()` still revoke locally. They call the provider
  (TikTok, Google) only once no other owner has an active credential for the
  same external user, because a provider revoke can end that user's whole
  consent for your app.
- **`check-static` makes one call per credential**, so there are more calls when
  several owners share Pages.

2.0 changes nothing else: events, the renewal flow and `validAccessTokenFor()`
work as in 1.2 (see [Coming from 1.0 or 1.1](#coming-from-10-or-11) for what
1.2 changed, and [From 2.x to 3.0](#from-2x-to-30) for 3.0).
