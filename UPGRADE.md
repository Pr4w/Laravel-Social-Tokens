# Upgrading

## From 2.x to 3.0

Coming from 1.x? Follow [From 1.x to 2.0](#from-1x-to-20) as well: one
`php artisan migrate` runs both versions' migrations, in the right order.

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

Both are named to run before `2025_01_01_000007_scope_identity_to_owner`, which
needs them when you come from 1.x. On a 2.x install, which already ran 000007,
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

### If you wrote a connector

- Mark every parameter of your connector that carries a token or a secret
  `#[\SensitiveParameter]` (PHP 8.2+), as the built-in connectors now do.
  The attribute on `ProviderConnector::exchangeForLongLived()` is not
  inherited by your implementation. Without it, an exception thrown while the
  method is on the stack records the token in its trace, where an error
  tracker can collect it.

## From 1.x to 2.0

2.0 scopes identity to the **owner** you pass when connecting. An account is
unique per *(provider, external id, owner)* and a credential per *(provider,
external holder, owner)*. In 1.x, when a second owner connected an account a first
owner already had, the single row and its credential silently moved to the
second owner. The first owner lost it, with no event.

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
SocialToken::ownedBy($owner)->where('provider', 'linkedin')->where('provider_holder_id', $memberId)->first();
SocialAccount::ownedBy(null); // owner-less rows
```

Look especially for `->first()`, `->sole()` and `firstWhere()` on those columns,
and for unique validation rules (`Rule::unique('social_accounts', …)`).

### 4. Behaviour changes to know

- **Each owner renews its own credential.** Two owners of the same TikTok,
  Google or LinkedIn account mean two grants, two refresh tokens and two
  renewals. Some providers cap the refresh tokens per user and app (Google: 100).
- **No implicit adoption.** In 1.x, connecting with an owner an account stored
  without one attached that row to the owner. Now it creates a separate row.
  Attach an owner-less row yourself with `$account->ownable()->associate($owner)`.
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

Events, the renewal flow and `validAccessTokenFor()` are unchanged.
