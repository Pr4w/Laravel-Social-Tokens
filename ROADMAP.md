# Roadmap

Directions planned beyond the current release. Nothing here is committed API yet;
it captures intent so it doesn't get lost.

## Near term

### Per-account scopes and scope checks — v0.3.0

Record the scopes actually granted to each account and let callers check them.

- Model helpers: `grantedScopes()`, `hasScope()`, `hasScopes()`, `missingScopes()`
  — provider-agnostic, off the stored `scopes` column.
- Meta granular permissions: a user can grant a scope for some Pages / Instagram
  accounts and not others. The token-level scope list is therefore inaccurate per
  account. `FacebookConnector` resolves the real per-account scopes via
  `debug_token`, and the fan-out actions store them per row.

Use case: decide whether an account has everything it needs to publish before
saving it, and warn on the ones that fall short.

## Longer term

### Shared credential table — v1.0 candidate

Across Facebook Pages, Instagram accounts and LinkedIn organizations, one
credential (the user / member token) backs many account rows. Today that token is
duplicated per row and refreshed independently — the same token gets extended N
times per cycle.

The structural fix is a `social_tokens` table (the credential) that
`social_accounts` belongs to: refresh the credential **once**, and every account
that shares it sees the fresh token. Per-account locking and renewal move up to
the credential.

This is an architectural change, not a patch: schema migration, moving the
renewal/lock logic onto the token, a data backfill, and reconciling it with
rotating 1:1 providers (e.g. TikTok) that would simply have one token row each.
Targeted at a 1.0 line.
