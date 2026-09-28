# Upgrading

## From 1.x to 2.0

2.0 scopes identity to the **owner** you pass when connecting. An account is
unique per *(provider, external id, owner)* and a credential per *(provider,
external holder, owner)*. In 1.x, when a second owner connected an account a first
owner already had, the single row and its credential silently moved to the
second owner. The first owner lost it, with no event.

### 1. Update the package

```bash
composer require pr4w/laravel-social-tokens:^2.0
```

### 2. Run the migration, on a copy of production first

```bash
php artisan migrate
```

If you published the package migrations, publish or copy
`2025_01_01_000007_scope_identity_to_owner.php` first. The migration:

- adds `ownable_type` / `ownable_id` to `social_tokens`;
- gives every credential the owner of the accounts it backs. A Meta user
  credential with no account pointing at it takes the owner of the Facebook Pages
  that name it as their holder;
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

**MySQL:** the new unique keys fit InnoDB's 3072-byte index limit with the
default bigint morph ids, but **not** with uuid/ulid morph ids. If your owners use
uuid/ulid keys, shorten `provider` (e.g. `string(50)`) before migrating.

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
