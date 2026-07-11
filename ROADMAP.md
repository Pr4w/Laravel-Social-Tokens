# Roadmap

## Shipped

- **Per-account scopes and scope checks** (v0.3) — `debug_token` granular
  resolution for Meta; `hasScope()`/`hasScopes()`/`missingScopes()` helpers.
- **LinkedIn organization fan-out** (v0.4) — `StoreLinkedInOrganizations`.
- **Shared credential table** (v1.0) — `social_tokens` holds the renewable
  credential; `social_accounts` point at the credential they post with. Renewal
  happens once per credential. See the README's "How it's stored".

## Ideas

Nothing committed. Candidates if the need arises:

- Instagram-Login path (`graph.instagram.com` / `ig_refresh_token`) as an
  alternative to the Facebook-Login path.
- A `php artisan social-tokens:prune` command for orphaned static credentials.
