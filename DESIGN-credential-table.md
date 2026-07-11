# Design: shared credential table (v1.0)

Status: in progress on `feature/credential-table`.

## Problem

One credential (a user / member token) backs many account rows. Today that token
is duplicated per row and refreshed independently — the same Meta user token gets
extended N times per cycle, once per page and once per IG account.

## Model

A new **`social_tokens`** table holds the renewable credential. `social_accounts`
belongs to one credential and stops owning renewal.

```
social_tokens (the credential — the renewal unit)
  id, provider, provider_holder_id
  access_token, refresh_token           (encrypted)
  expires_at, refresh_expires_at, renew_at
  status, last_renewed_at, last_error, scopes
  unique(provider, provider_holder_id)

social_accounts (the postable identity)
  social_token_id  -> social_tokens.id  (nullOnDelete)
  provider, provider_user_id, provider_holder_id
  access_token      (ONLY a per-account derived token — Facebook page token;
                     null for Instagram/LinkedIn, which post with the credential)
  ... profile fields, scopes (per-account), status ...
```

### Decision: `social_tokens.provider` is the *refresher* key

The credential's `provider` names the connector that refreshes it, which is not
always the account's provider:

| Account provider | Credential provider | Why |
|---|---|---|
| facebook | facebook | fb_exchange_token extends the user token |
| instagram | facebook | Instagram runs on Facebook Login; same user token |
| threads | threads | separate Meta token (th_* grants) |
| tiktok | tiktok | 1:1 |
| google | google | 1:1 |
| linkedin | linkedin | member token backs the orgs |

Each connector declares this via a new `credentialProvider(): string` method
(default: its own key; Instagram overrides to `facebook`). So a Meta user is
exactly one `social_tokens` row (`provider = facebook`) backing all their
Facebook pages **and** Instagram accounts.

### Decision: `provider_holder_id` is always set

It identifies the credential owner and gives `unique(provider, holder)`:
- Meta → the Facebook user id
- LinkedIn → the member id
- TikTok / Google (1:1) → the account's own external id (its `provider_user_id`)

So 1:1 providers get one credential per account (no shared token, but uniform).

## Renewal (two-phase, on the credential)

1. Dispatcher scans `social_tokens.renew_at`; one job per **credential**; lock per
   credential.
2. **Phase A — refresh the credential:** `registry->for($token->provider)
   ->refreshCredential($token)` (fb_exchange_token / refresh_token grant / …).
   Applies the new token to the `social_tokens` row once.
3. **Phase B — derive per-account tokens:** for each linked account,
   `registry->for($account->provider)->deriveAccountToken($account, $token)`.
   Facebook re-derives the page token; everyone else is a no-op (the account
   posts with the credential's token).

### Connector interface change

```
refreshCredential(SocialToken): RenewalResult      // was renew(SocialAccount)
deriveAccountToken(SocialAccount, SocialToken): ?string   // default null
credentialProvider(): string                        // default = own key
```

`RenewalResult` is reused unchanged (it already models new token + expiry +
rotated refresh token).

## Posting API (unchanged signature)

`SocialTokens::validAccessTokenFor(SocialAccount): string` still takes an account.
Internally it now:
- reads the account's credential; renews the **credential** if due;
- returns the account's page token (Facebook) or the credential token (others).

`renew()` becomes `renewCredential(SocialToken)`.

## Backfill migration

Group existing `social_accounts` into credentials:
- Key: `(credentialProvider(account.provider), holder)` where holder =
  `provider_holder_id` if set, else `provider_user_id` (1:1 providers).
- The credential's tokens come from the existing rows:
  - Meta: `refresh_token` (the stored user token) → credential `access_token`;
    the page token stays on the Facebook account row.
  - Instagram: the account's `access_token` (already the user token) →
    credential; account keeps no separate token.
  - TikTok / Google / LinkedIn: the account's tokens move to the credential.
- Point every account at its credential; clear the renewal columns that moved.

## Phases

1. **Foundation (this checkpoint):** `social_tokens` migration, FK migration,
   `SocialToken` model, `SocialAccount` relationship. Additive — suite stays green.
2. Connector interface: `credentialProvider` / `refreshCredential` /
   `deriveAccountToken` across all six.
3. Renewal core: `SocialTokens`, the job, the dispatch command.
4. Actions: create/attach the credential, then the account rows.
5. Backfill migration.
6. Test suite rewrite + README.
