# Upgrading

## From 2.x to 3.0

Coming from 1.x? Follow [From 1.x to 2.0](#from-1x-to-20) as well: one
`php artisan migrate` runs both versions' migrations, in the right order. From
1.0 or 1.1, start with [Coming from 1.0 or 1.1](#coming-from-10-or-11): 1.2's
changes.

What changed, and why, is in [CHANGELOG.md](CHANGELOG.md); this guide says
what to do about it, in this order:

1. [Update the package](#update-the-package).
2. [Run the new migrations](#run-the-new-migrations), before or together with
   the code deploy.
3. [Check your published config](#check-your-published-config).
4. [Change your code](#change-your-code) where it uses what changed.
5. [Fix up your data](#fix-up-your-data), before the first
   `social-tokens:prune`.
6. Read [what behaves differently](#what-behaves-differently): nothing to
   change, but your logs, events and rows will show it.
7. Turn on [what is new](#whats-new-optional) if you want it.

### Update the package

```bash
composer require pr4w/laravel-social-tokens:^3.0
```

The requirements are those of 2.x: PHP 8.2+ (8.3+ on Laravel 13), Laravel 12
or 13, and Laravel Socialite 5.5+.

### Run the new migrations

```bash
php artisan migrate
```

**Run them before, or together with, the code deploy**: in the deploy script,
before the 3.0 code serves requests and before the queue workers restart on
it. The 3.0 code reads and writes the columns they add, and fails on a 2.x
schema:

- until `000008` has run, every connection throws a `QueryException`
  (unknown column `purpose`);
- until `000009` has run, Facebook and Instagram (Facebook Login) connections
  throw one too (unknown column `data_access_expires_at`); the renewal of a
  Meta user credential fails whenever Meta reports the deadline, as it
  usually does, and is retried; and each dispatcher run still dispatches all
  the renewals, then reports the failed data access check ("Could not check
  the data access deadlines") and exits with a failure code (on SQLite the
  check does not fail: it silently finds nothing);
- until `000011` has run, `disconnect()` throws a `QueryException` (unknown
  column `disconnected_at`) and changes nothing.

**Before you migrate:**

- **Connector keys must be at most 50 characters**, since `provider` becomes
  `varchar(50)`. The migration checks both tables first and refuses, changing
  nothing, while a row holds a longer key. To find them:

  ```php
  foreach ([\Pr4w\SocialTokens\Models\SocialAccount::class, \Pr4w\SocialTokens\Models\SocialToken::class] as $model) {
      dump($model::query()->distinct()->pluck('provider')->filter(fn ($key) => mb_strlen($key) > 50)->all());
  }
  ```

  Rename that connector's key in `config/social-tokens.php`, update its rows
  (`UPDATE social_accounts SET provider = 'new-key' WHERE provider = 'old-key'`,
  and the same on `social_tokens`), then migrate.
- **Published copies.** The package loads its migrations itself, so they run
  whether or not you published them, and a published file with the same name
  runs instead of the package's copy. If you keep published copies, run
  `php artisan vendor:publish --tag=social-tokens-migrations` to add the new
  files; existing files are left alone.
- **Large tables.** Run the migrations on a copy of production first: some of
  them lock a table while they run (below). `php artisan migrate --pretend`
  lists the statements. It reads no columns or rows (every query comes back
  empty), so it skips the checks and the LinkedIn split, and shows `provider`
  shortened on both tables (a real run skips a column already at most 50
  characters).

They run in file name order. The two 000006 migrations are named to run
before `2025_01_01_000007_scope_identity_to_owner`, which needs them when you
come from 1.x; on a 2.x install, which already ran 000007, they simply run on
their own. Each can be run again after a failure: fix the cause and run
`php artisan migrate` again, it resumes where it stopped.

1. **`2025_01_01_000006_index_social_token_id_on_social_accounts`** indexes
   `social_accounts.social_token_id`, which every lookup of a credential's
   accounts uses (the dispatcher, `revoke()`, `disconnect()`).
   - MySQL / MariaDB: adds nothing, the foreign key already has an index.
   - PostgreSQL: builds the index, blocking writes to `social_accounts` (not
     reads) while it runs.
   - SQLite: builds the index.
   - Rolled back, it drops the index it created.
2. **`2025_01_01_000006_shorten_provider_columns`** makes `provider`
   `varchar(50)` on `social_accounts` and `social_tokens`, so that the
   owner-scoped unique keys fit InnoDB's 3072-byte limit with uuid and ulid
   owner ids too.
   - MySQL / MariaDB: shrinking a column copies the table: writes to each
     table wait while it is copied.
   - PostgreSQL: rewrites both tables and their indexes, under a lock that
     blocks reads and writes until the migration commits.
   - SQLite (which declares no length) and SQL Server (no key-length cap, and
     it cannot shrink an indexed column in place): left as is.
   - Rolling it back keeps `varchar(50)`, which 2.x reads fine (widening it
     again could push the owner keys of a uuid install back over MySQL's
     limit).
3. **`2025_01_01_000007_scope_identity_to_owner`**: already run on 2.x. From
   1.x, see [From 1.x to 2.0](#from-1x-to-20).
4. **`2025_01_01_000008_add_purpose_to_social_tokens`** adds
   `social_tokens.purpose` (`varchar(32)`, not null, default `''`), adds the
   unique key on `(provider, provider_holder_id, purpose, ownable_type,
   ownable_id)` (`social_tokens_identity_purpose_unique`, prefixed like
   Laravel's own index names when `prefix_indexes` is on), then drops the one
   on `(provider, provider_holder_id, ownable_type, ownable_id)`
   (`social_tokens_owner_identity_unique`), and moves the LinkedIn
   organizations to credentials of their own (see
   [LinkedIn profiles and organizations](#linkedin-profiles-and-organizations-have-separate-credentials)).
   It is not one transaction: each schema step commits on its own, and the
   split commits per 100 credentials, a credential whole or not at all.
   - MySQL / MariaDB: checks first that the new key fits InnoDB's 3072-byte
     limit, and refuses, changing nothing, if it does not (with
     `000006_shorten_provider_columns` it fits). The column and the key are
     added in place: the table stays readable and writable.
   - PostgreSQL: adding the column is instant; building the key blocks
     writes to `social_tokens` (not reads) while it runs.
   - SQLite: adds the column and swaps the keys.
   - Rolling it back refuses while a member has two credentials for one owner
     (see [LinkedIn profiles and organizations](#linkedin-profiles-and-organizations-have-separate-credentials)).
5. **`2025_01_01_000009_add_data_access_expiry_to_social_tokens`** adds two
   nullable timestamps to `social_tokens`, `data_access_expires_at` and
   `data_access_warned_at` (see
   [Meta data access](#meta-data-access-and-tokens-that-never-expire)).
   Instant on MySQL 8, MariaDB and PostgreSQL. Rolled back, it drops them.
6. **`2025_01_01_000010_clear_tokens_of_revoked_credentials`** erases the
   tokens that credentials revoked before 3.0 still hold, in one `UPDATE` (see
   [Revoked credentials keep no tokens](#revoked-credentials-keep-no-tokens)).
   It changes no schema and keeps `updated_at`. **It cannot be rolled back**:
   if you need those tokens for anything (nothing in the package reads them),
   export them first.
7. **`2025_01_01_000011_add_disconnected_at_to_social_accounts`** adds a
   nullable timestamp, `disconnected_at`, to `social_accounts` (see
   [Disconnected accounts stay disconnected](#disconnected-accounts-stay-disconnected)).
   Instant on MySQL 8, MariaDB and PostgreSQL. Rolled back, it drops it.

### Check your published config

Nothing was renamed or removed. Without a published `config/social-tokens.php`
there is nothing to do here.

**New keys.** Each takes its default when your published file lacks it, except
the connectors: a published `connectors` array replaces the package's whole,
so add the new connectors you want yourself.

```php
// Connect X (Twitter), and Instagram without a Facebook Page:
'connectors' => [
    // ...your existing entries
    'x' => [
        'driver' => \Pr4w\SocialTokens\Connectors\XConnector::class,
    ],
    'instagram_login' => [
        'driver' => \Pr4w\SocialTokens\Connectors\InstagramLoginConnector::class,
        'graph_version' => env('META_GRAPH_VERSION', \Pr4w\SocialTokens\Connectors\MetaGraphConnector::DEFAULT_GRAPH_VERSION),
    ],
],

// In the existing facebook and instagram entries (on when missing), to be able
// to turn appsecret_proof off (see "Meta calls carry appsecret_proof"):
'appsecret_proof' => env('META_APPSECRET_PROOF', true),

// Pruning (see "Unused credentials are unscheduled, then pruned"):
'prune_schedule' => 'daily',   // null: never prune
'prune_after_days' => 30,      // at least 1

// The Meta data access warning (see "Meta data access and tokens that never expire"):
'data_access_warning' => [
    'enabled' => env('SOCIAL_TOKENS_DATA_ACCESS_WARNING', true),
    'lead_days' => 7,
],

// Meta's deauthorize and data deletion callbacks, off unless enabled
// (see "Meta deauthorize and data deletion callbacks"):
'callbacks' => [
    'enabled' => env('SOCIAL_TOKENS_CALLBACKS', false),
    'prefix' => 'social-tokens/meta',
    'middleware' => [],
    'deletion_status_url' => env('SOCIAL_TOKENS_DELETION_STATUS_URL'),
],
```

**Values read differently.** No change is needed for a config that kept the
shipped forms. Values in other forms are now normalised instead of failing or
being misread:

- `queue.tries` set to `null`, an empty string or a non-numeric value now means
  4 attempts. 2.x read it as `0`, which Laravel takes as unlimited retries: for
  example `'tries' => env('SOCIAL_TOKENS_TRIES')` with the variable unset.
  Write `0` to keep unlimited retries.
- Boolean settings holding a string are read as booleans. If
  `log_unknown_errors`, `check_renewable` or the `linkedin` connector's
  `refresh_enabled` holds a string such as `'false'`, `'no'` or `'off'`,
  either written in the file or coming from `env()` (which converts only
  `true`, `false`, `null` and `empty`, and returns any other value as a
  string: `SOCIAL_TOKENS_CHECK_RENEWABLE=off` gives `'off'`), 2.x treated it
  as true: uncatalogued errors were logged, renewable credentials were
  checked, LinkedIn member credentials holding a refresh token were renewed
  with it. 3.0 reads it as false. Write `true` (or
  `SOCIAL_TOKENS_CHECK_RENEWABLE=true`, `LINKEDIN_REFRESH_ENABLED=true`) to
  keep the 2.x behaviour.
- A connector `driver` that names a missing class, or a class that does not
  implement `ProviderConnector`, now counts as no connector, like
  `driver => null`: the dispatcher skips its credentials, `disconnect()` and
  `revoke()` revoke locally without calling the provider, and
  `validAccessTokenFor()` throws a transient `NeedsReconnectException`. 2.x
  threw `InvalidArgumentException` from all of these. Connecting still throws.
  The package logs `The connector driver for provider [...] does not name a
  ProviderConnector class` once per process: search your log for it after
  deploying.
- `dispatch_schedule`, `check_static_schedule` and the new `prune_schedule`
  accept a cron expression (`'*/10 * * * *'`, `'@hourly'`) besides a frequency
  method without arguments. Any other value, including a scheduler macro, no
  longer throws: it logs an error naming the setting and keeps the default
  (every 15 minutes, daily, daily). If you used a macro, set the key to `null`
  and schedule the command yourself.
- A method that only picks the days (`weekdays`, `weekends`, `mondays` to
  `sundays`) is no longer accepted either: 2.x ran the command every minute of
  those days, and 3.0 logs the same error and keeps the default. Write a cron
  expression instead, such as `'*/15 * * * 1-5'` for every 15 minutes on
  weekdays. The same goes for `daysOfMonth` without its days.
- `queue.backoff` may be one value (`60`), a list, or a comma-separated string.
  A single value used to stop every dispatcher run with a `TypeError`.
- `http.timeout` and `http.connect_timeout` keep fractional seconds (2.x cut
  `2.5` to `2`).
- `check_static_breaker.min_checked` below 1 counts as 1.

The `log_unknown_errors` comment in a published copy says the "raw payload"
is logged. It is not, and has not been since 1.2: the context holds error
codes, the HTTP status and the response's keys, never the body. Correct the
comment in your copy so nobody adds the body back.

### Change your code

Each section starts with who it concerns; skip the ones that do not apply.

#### Review your `reportRejected()` calls

For every app that reports publish rejections. 2.x's README told you to pass
`terminal: true` for any Meta 190/102 and for an OAuth `invalid_grant` /
`invalid_token`. Both flag healthy credentials, so change the `terminal:`
argument where you report a publish rejection:

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
would see the same Meta incident. A report whose `rejectedToken` the
credential no longer holds (the user reconnected meanwhile) returns `false`
without asking the provider.

Credentials 2.x flagged because of such reports can be given another chance:
see [Fix up your data](#credentials-flagged-by-invalid_token-reports).

#### Queued listeners re-read the models

For apps with a `ShouldQueue` listener of a package event, or code reading
`connected_by`. Every package event (`AccountConnected`,
`AccountNeedsReconnect`, `AccountRevoked`, `CredentialExpiringSoon`,
`CredentialNeedsReconnect`, `CredentialRenewed`, `CredentialRevoked`, and the
new `CredentialDataAccessExpiring`) now uses `SerializesModels`, like
Laravel's own events. Synchronous listeners and `Event::fake()` assertions see
no difference. A listener that implements `ShouldQueue` gets
`$event->account` / `$event->token` fetched again from the database when it
runs, with the relations that were loaded at dispatch (`ownable`,
`connectedBy`), instead of a serialised copy that also carried your owner
model and the token ciphertext into the queue.

What to check:

- **Rows deleted right after the event.** If your app deletes a
  `SocialAccount` or `SocialToken` soon after the event fires (for example
  after `disconnect()`, which fires `AccountRevoked`, or with
  `forgetOwner()`), a queued listener that runs later fails with
  `ModelNotFoundException` and lands in `failed_jobs`.
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

#### Revoked credentials keep no tokens

For apps that read a revoked credential's tokens, or listen for
`CredentialRevoked`. Revoking a credential (`revoke()`, `disconnect()` when it
removes the credential's last connected account, the deauthorize callbacks,
`forgetOwner()`) now erases its `access_token`, `refresh_token`, `expires_at`,
`refresh_expires_at` and `renew_at` in the same write that sets `status` to
`revoked`. The provider's revocation endpoint still gets the tokens, from a
copy taken before. Migration `000010` erases them on the credentials already
revoked.

1. **Listeners.** In a `CredentialRevoked` listener, synchronous or queued,
   `$event->token->access_token` and `refresh_token` are now null. If one
   calls the provider with them (say Meta's `DELETE /{user-id}/permissions`,
   which the package leaves to you because it removes the user's whole grant),
   move that call into a connector of your own: extend the connector, override
   `revoke(SocialToken $token)`, read the token there (it holds the values
   from before the revocation), and register it as the provider's `driver`.
   Or read the token yourself before calling `revoke()`.
2. **Google reconnects.** A reconnect without a new refresh token used to fall
   back on the one a revoked credential still held. Keep asking Google for
   one on every connect (`access_type=offline` and `prompt=consent`, as the
   README shows); otherwise the reconnected credential cannot be renewed.
3. **One more `CredentialRevoked` for Facebook.** `disconnect()` of the last
   Page (or Instagram account) of a Facebook user, under one owner, now also
   revokes the Meta user credential (`provider` `facebook`,
   `provider_holder_id` the Facebook user id): a listener that notifies the
   user on `CredentialRevoked` gets one event for it. Disconnecting the last
   Instagram account no longer revokes that credential while one of the user's
   Pages is still connected under that owner, **including the Page an
   `instagram` connection stored with each Instagram account** (a `facebook`
   account, returned by `StoreConnection` with the Instagram ones). An app
   that shows Instagram accounts only, and relied on 2.x revoking the token
   with the last of them, must disconnect those Pages as well (the README,
   "Account status", shows how), or revoke the credential
   (`revoke($instagram->credential)`); otherwise the token stays active and
   renewed.

#### Revocation is recorded before the provider call

For apps that catch exceptions from `revoke()` or `disconnect()`. Both now
revoke the credential and its accounts locally first, firing
`CredentialRevoked` and `AccountRevoked`, and only then ask the provider to
invalidate the token.

- If your app caught an exception from `revoke()` or `disconnect()` to tell the
  user that the provider-side revocation failed, it no longer gets one: the
  failure is logged as a warning, `[social-tokens] {provider} token revocation
  failed`, and the credential is revoked locally all the same. Alert on that
  log line if you need to know. A provider answer that does not confirm a
  Google, TikTok or X revocation is logged too: `[social-tokens] {provider}
  did not confirm the token revocation`.
- Your revocation listeners now run before the provider call. An exception one
  of them throws still reaches the caller, but only after the provider call:
  the revocation has happened by then, so do not report the disconnect as
  failed or undo anything on the strength of it.

#### Store connections through the connector

For apps calling the `Store*` actions with options, extending them, or calling
`StoreAccountFromSocialite` for Meta. `StoreConnection` now asks the connector
which action stores a connection (`Contracts\FansOutConnections`), and passes
every option to it.

1. **`longLived: false` reaches Facebook and Instagram.** 2.x ignored it for
   those two providers and always exchanged the user token for a long-lived
   one. If one generic callback passes `longLived: false` for every provider,
   stop passing it for `facebook` and `instagram`, unless the token you hand
   over is already long-lived (you exchanged it yourself): Page tokens minted
   from a short-lived user token stop working within hours, and are stored as
   never expiring.
2. **`StoreAccountFromSocialite` refuses fan-out providers.** Called with
   `facebook` or `instagram`, or any key whose connector extends
   `FacebookConnector` or `InstagramConnector`, it now throws
   `InvalidArgumentException` and writes nothing. Call `StoreConnection` (or
   `StoreFacebookPages` / `StoreInstagramAccounts`) instead. Rows it stored
   for them under 2.x are useless: see [Fix up your data](#rows-storeaccountfromsocialite-stored-for-facebook-or-instagram).
   A subclass you wrote to store one account per connection (Instagram
   Login, as 2.x's `InstagramConnector` docblock suggested) is the exception:
   see [Instagram Login through a subclass of `InstagramConnector`](#instagram-login-through-a-subclass-of-instagramconnector).
3. **If you construct `StoreConnection` yourself**, its constructor now takes
   the `ConnectorRegistry`, the container and `StoreAccountFromSocialite`.
   Resolve it from the container instead.
4. **A connector whose connections fan out** (one of your own) implements
   `FansOutConnections::connectionAction()`, returning the class of an
   action implementing `Contracts\StoresConnections`. A subclass of
   `FacebookConnector` or `InstagramConnector` inherits theirs, the Page
   fan-out; one that returns `StoreAccountFromSocialite::class` stores one
   account per connection instead, through `StoreConnection` and
   `StoreAccountFromSocialite` alike.
5. **If you extend a `Store*` action** (bound in the container in place of
   the package's, to add checks, auditing or fields):
   - an override of `handle()` must declare the parameters 3.0 added at the
     end, or PHP stops with "Declaration of ...::handle(...) must be
     compatible with ...": `bool $restoreDisconnected = false` on
     `StoreFacebookPages`, `StoreInstagramAccounts` and
     `StoreLinkedInOrganizations`, `?CarbonInterface $refreshExpiresAt = null`
     on `StoreAccountFromSocialite`. Mark a parameter that carries a token
     `#[\SensitiveParameter]`, as the package's are.
   - `StoreConnection` no longer calls `StoreFacebookPages::handle()` nor
     `StoreInstagramAccounts::handle()`: it calls their `storeConnection()`
     (`Contracts\StoresConnections`), so an override of `handle()` alone
     runs only where your code calls it, and silently not for
     `StoreConnection`. Override `storeConnection()` too, or move the
     customisation to an `AccountConnected` listener, which every path fires.
     `StoreAccountFromSocialite::storeConnection()` still goes through its
     `handle()`.

#### Instagram Login through a subclass of `InstagramConnector`

For apps that followed 2.x's `InstagramConnector` docblock ("if you use the
newer Instagram API with Instagram Login ... swap the request below"): a
subclass of `InstagramConnector` (or `FacebookConnector`) whose connections
store one account each through `StoreAccountFromSocialite`, usually with
`credentialProvider()` returning `'instagram'`. In 3.0 both connectors
implement `FansOutConnections`, which the subclass inherits:
`StoreAccountFromSocialite` throws `InvalidArgumentException` ("Connections
of [instagram] fan out to several accounts"), and `StoreConnection` sends the
connection to the Facebook Page fan-out on `graph.facebook.com`, which refuses
an Instagram Login token. Pick one:

1. **Move to `InstagramLoginConnector`**, 3.0's connector for that API
   (`graph.instagram.com`: `ig_exchange_token` at connect, `ig_refresh_token`
   a week before expiry, `/me` checks), and drop your subclass. Register it
   under the key your rows are stored under, so they need no change:

   ```php
   'connectors' => [
       'instagram' => [ // the key your subclass was registered under
           'driver' => \Pr4w\SocialTokens\Connectors\InstagramLoginConnector::class,
           'credentials' => 'instagram', // the services entry with the Instagram app's id and secret
           'graph_version' => env('META_GRAPH_VERSION', \Pr4w\SocialTokens\Connectors\MetaGraphConnector::DEFAULT_GRAPH_VERSION),
       ],
   ],
   ```

   It stores and renews its credentials under its own key, as a subclass
   whose `credentialProvider()` returned it did. To use the
   `instagram_login` key instead (the Instagram accounts reached through a
   Page keep `instagram`), register it there, point your Socialite driver,
   OAuth routes and `StoreConnection` calls at it, and move the rows your
   subclass stored, once, in the same deploy: left under `instagram`, its
   credentials would be renewed by `InstagramConnector` on
   `graph.facebook.com`, and flagged.

   ```php
   SocialAccount::query()->where('provider', 'instagram')
       ->whereHas('credential', fn ($credential) => $credential->where('provider', 'instagram'))
       ->update(['provider' => 'instagram_login']);
   SocialToken::query()->where('provider', 'instagram')->update(['provider' => 'instagram_login']);
   ```

   Accounts reached through a Page post with a `facebook` credential and
   keep `instagram`.
2. **Keep your subclass**, and have it name `StoreAccountFromSocialite` as
   its connection action: `StoreConnection` and `StoreAccountFromSocialite`
   then store one account per connection, as 2.x did.

   ```php
   public function connectionAction(): string
   {
       return \Pr4w\SocialTokens\Actions\StoreAccountFromSocialite::class;
   }
   ```

   Check its overrides against [If you wrote a connector](#if-you-wrote-a-connector):
   `InstagramConnector` now extends `MetaGraphConnector`, whose
   `refreshCredential()` and `exchangeForLongLived()` call
   `graph.facebook.com` unless you override them.

#### Disconnected accounts stay disconnected

For apps offering a disconnect button on Facebook Pages, Instagram accounts
or LinkedIn organizations. `disconnect()` now records when the user removed an
account (`social_accounts.disconnected_at`), and a fan-out reconnect of the
same login (`StoreConnection` for Facebook and Instagram,
`StoreFacebookPages`, `StoreInstagramAccounts`, `StoreLinkedInOrganizations`)
leaves that account out: it stays revoked, is not returned, and fires no
`AccountConnected`. 2.x made it active again at the next reconnect. A 1:1
account (TikTok, Google, X, Threads, Instagram Login, a LinkedIn profile) is
still restored when the user reconnects it, and so is an account revoked any
other way (a deauthorization callback, `revoke()`, `markRevoked()`).

Decide which flows restore. Pass `restoreDisconnected: true` where the user
means to bring removed targets back (a "Connect Facebook" button after
removing every Page, which otherwise returns an empty collection), and leave
it off where they refresh a login or add a Page. To bring back one account
only, forget its disconnection before the reconnect:
`$account->clearDisconnected()->save()`.

Accounts disconnected under 2.x have no date, so a reconnect still brings them
back: see [Fix up your data](#accounts-disconnected-under-2x).

#### LinkedIn profiles and organizations have separate credentials

For apps posting as LinkedIn organizations. A LinkedIn member's personal
profile (stored through `StoreConnection` / `StoreAccountFromSocialite`) and
their organizations (stored through `StoreLinkedInOrganizations`) come from
two authorizations with two tokens and two scope sets. 2.x stored both on one
`(linkedin, member id, owner)` credential, so whichever flow ran last replaced
the other's access token, refresh token, expiry and scopes: after a member
connected their profile, every post as their organizations failed with a 403,
while the organization rows still listed `w_organization_social`. The
organizations now post with a credential of their own, with
`purpose = 'organizations'` (`StoreLinkedInOrganizations::PURPOSE`); the
profile keeps the default one (`purpose = ''`).

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

#### Re-syncing LinkedIn organizations

For apps that call `StoreLinkedInOrganizations::handle()` again with the
stored member token, to pick up new Pages. Leave `expiresAt` out (or pass the
stored `$credential->expires_at`): the token's expiry, schedule and issue date
are now kept, so a warning already sent is not sent again. Do not pass a new
`now()->addDays(60)` for a token you did not just receive: it moves the
expiry warning past the token's real end.

Read that token from the organizations' credential. A 2.x lookup by member id
and owner alone can now return the personal profile's credential (see
[above](#linkedin-profiles-and-organizations-have-separate-credentials)),
whose token cannot post as the organizations: passed here, it would be taken
for a new token and replace theirs, with no expiry (without a refresh token,
that credential would then never be warned about before it dies).

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

2.x erased the expiry of a credential re-synced without `expiresAt`: see
[Fix up your data](#linkedin-credentials-a-re-sync-left-without-an-expiry).

#### Renewals, transactions and the cache store

For every app with more than one server, and apps that ask for tokens inside a
database transaction. The renewal lock and the re-read under it changed, so a
credential is no longer refreshed twice or renewed over a reconnect. Check
three things:

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
     refused token: `$token->markNeedsReconnectIfStillHeld($result->reason)`
     instead of `markNeedsReconnect($result->reason)`. `renewCredential()`
     reloads the `$token` you pass under the lock, so it holds the token the
     provider refused, and a credential the user reconnected meanwhile is left
     alone. A credential whose tokens do not decrypt with the process's keys
     is a Transient result (`SocialToken::UNDECRYPTABLE`, a critical log, no
     provider call): do not read `$token->access_token` for it, that throws
     `DecryptException`.
   - `applyRenewal()` now writes only while the stored credential still holds
     the access and refresh tokens the instance was loaded with (the same
     tokens re-encrypted under a new `APP_KEY` still count). Load the
     credential right before you refresh it, not earlier. Use
     `tryApplyRenewal()` to know whether the renewal was stored; when it was
     not, the instance holds the stored row. A credential deleted meanwhile
     throws `ModelNotFoundException`.

#### Unused credentials are unscheduled, then pruned

For every app: the pruning is on by default. Two changes keep `social_tokens`
to the credentials something uses.

- `social-tokens:dispatch-renewals` sets `renew_at` to null on a credential
  whose renewal is due but that no active account depends on, instead of
  reading it again on every run. A reconnect schedules it again, and so does
  a re-sync with the stored token (LinkedIn organizations, or Facebook Pages
  and Instagram accounts with `extend: false`), or `adopt()`.
- The new `social-tokens:prune` command, scheduled daily, deletes the
  credentials that are revoked, flagged `needs_reconnect`, or used by no
  connected account, once they have not changed for `prune_after_days` (30).
  Accounts are never deleted: one whose credential goes keeps its row, with
  `social_token_id` null.

1. **Choose a retention, or turn it off** (`prune_schedule`, `prune_after_days`,
   see [Check your published config](#check-your-published-config)). The
   first run, at midnight after the deploy by default, deletes every
   credential that already qualifies, for instance all those revoked or
   flagged more than 30 days ago. Keep `prune_schedule` at `null` if you
   report on revoked credentials or point at their ids from your own tables;
   otherwise copy what you need first, and run the
   [data fix-ups](#fix-up-your-data) before it.
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
   now as well. Otherwise the dispatcher may have unscheduled it, and nothing
   renews it ahead of its expiry. A credential with a refresh token (Google,
   TikTok, X, LinkedIn with refresh tokens) is then renewed only once its
   token has expired and `validAccessTokenFor()` is asked for it. One whose
   token is extended while it is still valid (the Meta user token behind
   Instagram, Threads, Instagram Login) is lost: once expired it cannot be
   extended, so the first publish flags it `needs_reconnect` and the user
   must reconnect; a LinkedIn credential without a refresh token is flagged
   the same way, with no warning ahead of it.

#### Tear down the owners you delete

For apps that delete owners (workspaces, teams). The owner columns carry no
foreign key: deleting an owner leaves its accounts active and its credentials
renewed, and each of them still counts as a grant that keeps other owners'
`revoke()` and `disconnect()` of the same TikTok or Google user local. 3.0
adds `SocialTokens::forgetOwner($owner)`, which revokes and deletes what the
owner holds without touching other owners' rows. Call it before the owner is
deleted, once your own rows that point at its accounts are dealt with (the
accounts are deleted):

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

A block closure that returns nothing, not an arrow function: Eloquent stops
calling the `deleting` listeners at the first one that returns something other
than null, and `forgetOwner()` returns the deleted accounts, so the
workspace's later `deleting` listeners (an observer's included) would be
skipped without an error.

`CredentialRevoked` and `AccountRevoked` fire before the rows are deleted: a
queued listener of either fails with `ModelNotFoundException` and lands in
`failed_jobs` (see
[Queued listeners re-read the models](#queued-listeners-re-read-the-models)).
Listen synchronously, or keep what the listener needs in an event of your own.

The owners you deleted before are torn down in
[Fix up your data](#owners-you-deleted-before).

#### Transient renewal failures are no longer reported

For apps that watch provider outages in their error tracker.
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

#### Meta calls carry `appsecret_proof`

For apps on Facebook or Instagram. The `facebook` connector now signs the Graph
calls it makes with a user or Page token (`/me`, `/me/accounts`) with
`appsecret_proof`, so you can turn on **Require App Secret** in your Meta app.
Nothing to do for the package to keep working: the option defaults to on,
even in a published config that does not have it, and Meta accepts a valid
proof whether or not the setting is on.

1. If your database still holds Facebook Page or user tokens issued by
   **another** Meta app than the one in `services.facebook` (you switched
   apps), their proof cannot match: `social-tokens:check-static` then logs
   one critical "facebook rejected the app's OAuth client during
   check-static" per run ("Invalid appsecret_proof", with the number of
   credentials) instead of checking them. Set `META_APPSECRET_PROOF=false`
   until those users have reconnected (in a published config, add the
   `appsecret_proof` option first, see
   [Check your published config](#check-your-published-config)).
2. To turn on Require App Secret, first sign your own Graph calls made with
   these tokens (publishing, insights):
   `app(SocialTokens::class)->connector('facebook')->appSecretProof($token)`
   (or `connector('instagram')`) gives the value to send as
   `appsecret_proof`. Then deploy, and turn the setting on in the Meta app's
   advanced settings.

#### If you wrote a connector

For apps with a connector of their own, or a subclass of a built-in one.

- **A subclass of `GoogleConnector` or `TikTokConnector` that overrides
  `mapError(array $body)` no longer loads**: PHP stops with "Declaration of
  ...::mapError(array $body) must be compatible with
  OAuth2Connector::mapError(array $body, int $status)". The two connectors now
  extend `OAuth2Connector`, as `LinkedInConnector` and `XConnector` do, which
  maps every token-endpoint error. Move your mapping to
  `classifyError(string $error, string $description, int $status): ?string`,
  returning `self::ERROR_TERMINAL`, `self::ERROR_CLIENT`,
  `self::ERROR_TRANSIENT`, or `parent::classifyError(...)` for the rest.
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
- Your connector's `revoke()` now runs after `CredentialRevoked` and
  `AccountRevoked` have fired, on a copy of the credential taken before them,
  and the stored status is already `revoked`, with the tokens erased. Do not
  rely on the status being active there, and read the tokens from the
  instance you are given, never from the database. Log an answer that does
  not confirm the revocation with `$this->logUnconfirmedRevoke($token,
  $response)`. It runs even when one of those listeners threw; the
  listener's exception then reaches the caller of `revoke()` or
  `disconnect()` after your connector returns.
- If whether a credential can be renewed depends on what it holds, override
  `renewalStrategyFor(SocialToken $token)` (it defaults to
  `renewalStrategy()`). The package now asks it wherever it decides about a
  stored credential. If your own code decides with
  `$connector->renewalStrategy()` about a stored credential, ask
  `$token->renewalStrategyWith($connector)` instead.
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
- A connector whose connections yield several accounts implements
  `Contracts\FansOutConnections` (see
  [Store connections through the connector](#store-connections-through-the-connector)).
- `InstagramConnector` reads an `fb_exchange_token` reply as
  `FacebookConnector` does: a reply without a token fails with "Malformed
  token extension response." (it was "Malformed response, no
  access_token."). Update anything that matches the old text in
  `last_error`, a caught `RuntimeException` message or your logs.

### Fix up your data

One-off fixes, each for the apps it names. Run them in `php artisan tinker`
once 3.0 is deployed and migrated, and **before the first
`social-tokens:prune`**: it runs daily (at midnight by default) and deletes
the credentials revoked or flagged `needs_reconnect` that have not changed for
30 days, among them the ones the first fixes reactivate. To take your time,
set `prune_schedule` to `null` until you are done. The snippets page with
`eachById()`, not `each()`: `each()` pages by offset, and skips rows once a
page's changes take rows out of the query.

```php
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
```

#### Credentials flagged by `invalid_token` reports

If your app followed 2.x's README and reported `invalid_token` with
`terminal: true` (see
[Review your `reportRejected()` calls](#review-your-reportrejected-calls)),
credentials 2.x flagged because of it on Google, TikTok or X may still have a
working refresh token. To give them a chance before asking those users to
reconnect, reactivate them and let the dispatcher renew them at once (a
refresh token that really died is flagged again, with a new
`CredentialNeedsReconnect`). Match `last_error` against the reason your app
reported:

```php
SocialToken::query()
    ->whereIn('provider', ['google', 'tiktok', 'x'])
    ->where('status', 'needs_reconnect')
    ->where('last_error', 'like', '%invalid_token%')
    ->update(['status' => 'active', 'last_error' => null, 'renew_at' => now()]);
```

#### LinkedIn credentials flagged "LinkedIn token inactive."

2.x flagged every LinkedIn credential whose token LinkedIn's introspection
answered with a bare `{"active": false}`, which it also sends for a token
issued to another LinkedIn app than the configured client (see
[Checks and rejection reports flag fewer credentials](#checks-and-rejection-reports-flag-fewer-credentials)).
Once `client_id` / `client_secret` on the `linkedin` connector (or
`services.linkedin`) name the app that issued the member tokens, reactivate
the ones whose users have not reconnected yet (no event fires, and their
account rows need no change):

```php
SocialToken::query()->where('provider', 'linkedin')->where('status', 'needs_reconnect')
    ->where('last_error', 'LinkedIn token inactive.')
    ->update(['status' => 'active', 'last_error' => null]);
```

With `check_renewable` on, the next `check-static` run checks them again and
flags the ones LinkedIn reports revoked.

#### Strikes inherited by new tokens

In 2.x a reconnected or renewed token inherited the previous token's
`check-static` strike (`failed_checks`), and was flagged on its first
unconfirmed rejection. 3.0 clears it on every new token; rows may still hold
one inherited that way. To clear them (a token that really is dead then takes
one more daily run to be flagged):

```php
SocialToken::query()->where('failed_checks', '>', 0)->update(['failed_checks' => 0]);
```

#### Accounts disconnected under 2.x

If you offered a disconnect button: accounts disconnected under 2.x have no
`disconnected_at`, so a reconnect still brings them back. If your app only
ever revoked accounts through `disconnect()` (not `revoke()`, nor the
deauthorize callbacks), mark them once:

```php
SocialAccount::query()->where('status', 'revoked')->whereNull('disconnected_at')
    ->update(['disconnected_at' => now()]);
```

Otherwise pick the rows your own records say the user removed.

#### LinkedIn scopes are split

For LinkedIn profiles. `SocialToken::normaliseScopes()` now splits an element
holding several scopes on commas and whitespace. LinkedIn lists the granted
scopes comma-separated and Socialite's LinkedIn drivers split them on spaces,
so a LinkedIn profile stored through `StoreConnection` or
`StoreAccountFromSocialite` got one element holding them all
(`['email,openid,profile,w_member_social']`), on the account and on its
credential: `hasScope('w_member_social')` answered `false`. New connections
are stored one scope per element. Rows stored before keep the joined element
until the member reconnects; to fix them now:

```php
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

#### LinkedIn credentials a re-sync left without an expiry

If you re-synced LinkedIn organizations without `expiresAt` (see
[Re-syncing LinkedIn organizations](#re-syncing-linkedin-organizations)): 2.x
erased the expiry of those credentials. To find them (no expiry and no
schedule, so only `check-static` notices them once they die):

```php
SocialToken::query()->where('provider', 'linkedin')->where('status', 'active')
    ->whereNull('expires_at')->whereNull('renew_at')->get();
```

Their real expiry is lost. Ask those members to reconnect, or keep
`check_static_schedule` on (the default): `check-static` checks such a row as
a static credential, and flags it once LinkedIn reports its token expired or
revoked, with no warning beforehand.

#### Rows `StoreAccountFromSocialite` stored for Facebook or Instagram

If you called `StoreAccountFromSocialite` with `facebook` or `instagram`, which
3.0 refuses (see
[Store connections through the connector](#store-connections-through-the-connector)),
it stored the Facebook user as an account, which nothing can post with. A
Page's or an Instagram account's id is never its Facebook user's id, so this
finds them; delete them, or let your app decide:

```php
SocialAccount::query()->whereIn('provider', ['facebook', 'instagram'])
    ->whereColumn('provider_user_id', 'provider_holder_id')->get();
```

#### Owner-less rows you attached by hand

If you followed 2.0's notes: they said to attach an owner-less row to an
owner with `$account->ownable()->associate($owner)`. Saved, that moved the
account but not its credential, which stayed owner-less: the owner's next
reconnect stored a second credential and left the first behind, and the Meta
user token behind a Facebook Page attached that way was no longer renewed.
3.0 adds `SocialTokens::adopt($account, $owner)` (see
[README, Owners](README.md#owners)). Given an account that already belongs to
the owner, it moves the owner-less credentials it depends on, so this
finishes the accounts attached before:

```php
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

The dispatcher has most likely unscheduled the Meta user token behind such a
Page before you run this (no account of its owner kept it in use, see
[Unused credentials are unscheduled, then pruned](#unused-credentials-are-unscheduled-then-pruned)):
`adopt()` schedules a credential it moves again when it has an expiry and no
`renew_at`, so the next dispatcher run renews it, or flags it if its token
expired meanwhile. A credential still used by an owner-less account, or one
the owner already holds a copy of (a reconnect since), stays as it is; a
credential nothing uses any more is pruned.

#### Owners you deleted before

If you deleted owners before 3.0, their rows are still there, active and
renewed (see [Tear down the owners you delete](#tear-down-the-owners-you-delete)).
`forgetOwner()` only needs the owner's morph class and key, so an unsaved
instance carrying the old id will do:

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

#### Meta data access deadlines (optional)

The package learns a Meta user credential's data access deadline (see
[Meta data access](#meta-data-access-and-tokens-that-never-expire)) at its
next renewal or reconnect. To learn them all now, run this (one `debug_token`
call per Meta user credential behind Instagram accounts). Do run it if a
credential stored before 1.2 has no `renew_at` (a token that never expires,
stored as static): nothing renews it, so only a reconnect would read its
deadline. A deadline that is close or already past is announced at the next
dispatcher run:

```php
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;

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

### What behaves differently

Nothing to change in your code for these, but your logs, events and rows will
show them.

#### Checks and rejection reports flag fewer credentials

Some credentials 2.x flagged `needs_reconnect` (firing
`CredentialNeedsReconnect`) now stay active:

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
  `check-static` strike (`failed_checks`); see
  [Strikes inherited by new tokens](#strikes-inherited-by-new-tokens) for rows
  that still hold one.
- LinkedIn token introspection flags a credential only when LinkedIn answers
  `status: revoked`, or `expired` with refresh tokens off. A bare
  `{"active": false}`, which LinkedIn also sends for a token issued to another
  LinkedIn app than the configured client, is logged as
  `Uncatalogued credential check error` and never flagged. If every LinkedIn
  credential logs it, set `client_id` / `client_secret` on the `linkedin`
  connector (or `services.linkedin`) to the app that issued the member tokens,
  then reactivate the ones 2.x flagged (see
  [Fix up your data](#linkedin-credentials-flagged-linkedin-token-inactive)).
- A Meta answer about the app's own credentials (a wrong or rotated app
  secret, a wrong app id, a refused `appsecret_proof`) no longer counts as a
  transient renewal error. Like `invalid_client` on the other providers, it
  logs the critical "facebook rejected the app's OAuth client: check its
  client_id/client_secret" and pauses that provider's renewals for 15
  minutes. Route that log line to whoever holds the Meta app secrets. After
  rotating the secret, restart your queue workers
  (`php artisan queue:restart`) so they read it.

#### Meta data access and tokens that never expire

The package now reads Meta's `debug_token` lifetime of a Facebook Login user
token (the credential behind Instagram accounts) at connect and after each
renewal:

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
  not as "expired". Tune it, or turn it off, in `data_access_warning`.
- **One more Graph call per renewal** of a Meta user credential
  (`debug_token`, with the app access token).

#### LinkedIn credentials without a refresh token

Only with `LINKEDIN_REFRESH_ENABLED=true`. A LinkedIn credential that holds no
refresh token is now renewed as if refresh were off: when its window opens (5
days before expiry) `CredentialExpiringSoon` fires, it keeps posting, and it
is flagged `needs_reconnect` (reason `No refresh token (re-authorisation
required).`) only once its token has expired. 2.x flagged it at once, five
days early, with no warning. If you prompt members to reconnect from
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

#### Smaller changes

- **The dispatcher on the `sync` queue** no longer stops at the first failed
  renewal. It exits with a failure code only for an unexpected error, after
  walking every due credential. On a real queue, a job the queue rejects
  (Redis, SQS or the cache lock store down) still ends the run, as in 2.x: it
  is reported once, the command prints `Could not queue a renewal job` and
  exits with a failure code.
- **Facebook Page credentials** get `last_renewed_at` (the token's issue
  date) at each connection; rows stored by 2.x keep `null` until the user
  reconnects.
- **Uncatalogued renewal errors** no longer include the RFC 6749
  `temporarily_unavailable` and `server_error`: they are known transient
  failures, still retried.

### What's new (optional)

#### X (Twitter)

3.0 connects X through OAuth 2.0, under the connector key `x` (see the README,
"X (Twitter)"). In a published config, add the `x` entry (see
[Check your published config](#check-your-published-config)); then add X's
OAuth 2.0 client id and secret to `config/services.php` as `x`, request
`offline.access`, and connect with `StoreConnection::handle('x', $user)`.

#### Instagram without a Facebook Page

3.0 adds the Instagram API with Instagram Login, under the connector key
`instagram_login` (see the README, "Instagram without a Facebook Page").
Nothing changes for Instagram accounts connected through a Facebook Page
(`instagram`). To offer it:

1. In a published config, add the `instagram_login` entry (see
   [Check your published config](#check-your-published-config)).
2. Add the Instagram app's id and secret to `config/services.php` as
   `instagram_login` (or add `'credentials' => 'instagram'` to the entry
   above if your Socialite driver reads `services.instagram`).
3. Connect with `StoreConnection::handle('instagram_login', $user)`. The
   accounts have `provider = 'instagram_login'`: include it wherever you
   select Instagram accounts, and publish to them through
   `graph.instagram.com`.

#### Meta deauthorize and data deletion callbacks (optional)

3.0 can answer Meta's Deauthorize and Data Deletion Request callbacks (and
Threads' Uninstall and Delete). Nothing changes until you turn them on; to do
so:

1. In a published config, add the `callbacks` block (see
   [Check your published config](#check-your-published-config)); without it
   the routes stay off.
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

#### Rotating `APP_KEY` (optional)

If you rotated `APP_KEY` and still list the old key in `APP_PREVIOUS_KEYS`
because some tokens were never written again (Google refresh tokens, Facebook
Page tokens), make sure every process runs with the new key (restart your
queue workers), run `php artisan social-tokens:reencrypt`, then drop the old
key once it exits successfully. When it fails because credentials changed
while it ran, run it again. See the README, "Rotating APP_KEY".

A token no key decrypts any more is no longer stuck. A reconnect writes over
it (2.x threw `DecryptException`, the reconnect included). Its renewals and
publishes fail as transient and raise a `Log::critical`, but nothing flags it
on its own: it is usually a process with the wrong `APP_KEY`, and the user
cannot fix that. When the key is truly lost, run
`php artisan social-tokens:reencrypt --flag-undecryptable` to flag those
credentials `needs_reconnect` (`CredentialNeedsReconnect`, `last_error`
`SocialToken::UNDECRYPTABLE`), so their users are asked to reconnect.

#### Testing helpers

Model factories (`SocialAccount::factory()`, `SocialToken::factory()`), a
`SocialTokens::fake()` that answers the providers' renewal, revocation and
check calls, and a `Testing\FakeConnector`: see the README, "Testing your
app". The factories autoload from the package, and the facade is registered
as the `SocialTokens` alias.

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

If you keep published copies of the package migrations, delete your copy of
2.0's `2025_01_01_000007_scope_identity_to_owner.php` if you have not run it
yet, then run `php artisan vendor:publish --tag=social-tokens-migrations`:
a published file runs instead of the package's own copy, and publishing leaves
existing files alone. Run the 3.0 migrations as
[From 2.x to 3.0](#run-the-new-migrations) describes. This one:

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
  X keeps a single grant per app and X user: a second owner connecting the
  same X account ends the first one's tokens, and that credential is flagged
  at its next renewal.
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
  (TikTok, Google, X) only once no other owner has an active credential for
  the same external user, because a provider revoke can end that user's whole
  consent for your app.
- **`check-static` makes one call per credential**, so there are more calls when
  several owners share Pages.

2.0 changes nothing else: events, the renewal flow and `validAccessTokenFor()`
work as in 1.2 (see [Coming from 1.0 or 1.1](#coming-from-10-or-11) for what
1.2 changed, and [From 2.x to 3.0](#from-2x-to-30) for 3.0).
