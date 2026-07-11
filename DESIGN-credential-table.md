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

social_accounts (the postable identity — holds NO tokens anymore)
  social_token_id  -> social_tokens.id  (the token it posts with; nullOnDelete)
  provider, provider_user_id, provider_holder_id
  profile fields, scopes (per-account), status, ownable/connectedBy
```

Every token string lives in `social_tokens`. A row is either **renewable**
(`renew_at` set — scanned and refreshed) or **static** (`renew_at` null — stored
and left alone). The Facebook **page** token is a *static* row: minted once at
connect from a long-lived user token, never auto-refreshed (its concern is kept
separate from credential renewal). If a static page token ever fails, the account
goes `needs_reconnect` reactively.

| Token | provider | holder | renew_at |
|---|---|---|---|
| Meta user credential (backs IG) | facebook | fb user id | set |
| Facebook page token (static) | facebook | page id | null |
| LinkedIn member credential | linkedin | member id | set |
| TikTok / Google (1:1) | tiktok / google | account's own id | set |

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

## Renewal (single-phase, on the credential)

1. Dispatcher scans `social_tokens.dueForRenewal()` (renewable rows only — static
   page tokens have `renew_at` null and are never picked up).
2. One job per **credential**; lock per credential.
3. Refresh it: `registry->for($token->provider)->refreshCredential($token)`
   (fb_exchange_token / refresh_token grant / th_refresh_token / …). Applies the
   new token to the `social_tokens` row once. Every account sharing it is now live.

No per-account derivation step: Facebook page tokens are static, so refreshing the
Meta user credential (for Instagram) doesn't touch them. That keeps renewal purely
about credentials.

### Connector interface change

```
credentialProvider(): string                    // default = own key; Instagram -> 'facebook'
refreshCredential(SocialToken): RenewalResult    // was renew(SocialAccount)
```

`deriveAccountToken` is not needed (the Facebook decision removed it).
`RenewalResult` is reused unchanged.

## Posting API (unchanged signature)

`SocialTokens::validAccessTokenFor(SocialAccount): string` still takes an account.
Internally it now reads the account's `social_token` and returns its
`access_token`, renewing the token first if it is renewable and due. A static
Facebook page token (`renew_at` null) is simply returned as-is.

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

1. **Foundation (done):** `social_tokens` migration, FK migration, `SocialToken`
   model, `SocialAccount` relationship. Additive — suite stayed green.
2. Connector interface: `credentialProvider` + `refreshCredential` across all six.
3. Renewal core: `SocialTokens`, the per-credential job, the dispatch command.
4. Actions: create/attach the credential, then the account rows.
5. Backfill migration + drop the now-unused token columns from `social_accounts`.
6. Test suite rewrite + README + v1.0.0.
