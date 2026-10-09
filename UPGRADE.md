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
and runs as one long transaction on PostgreSQL.

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

**Index names on a prefixed install:** with a table prefix and
`prefix_indexes`, the new keys carry the prefix
(`acme_social_accounts_owner_identity_unique`), like the indexes Laravel names
itself. Adjust anything that refers to them by name.

**If it fails partway** (a lost connection, a deploy timeout), fix the cause and
run `php artisan migrate` again: every step is guarded and resumes where it
stopped. The backfill commits in chunks of 100 credentials, and a credential is
split in one transaction or not at all.

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
