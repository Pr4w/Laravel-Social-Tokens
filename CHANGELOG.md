# Changelog

All notable changes to `pr4w/laravel-social-tokens`. This project adheres to
[Semantic Versioning](https://semver.org).

## [1.1.0]

### Fixed
- Scheduled renewal never ran ahead of expiry. The double-check under the renewal
  lock treated any unexpired token as "already renewed", so the job fired at
  `renew_at` (days before expiry), did nothing, and was re-dispatched on every
  scheduler tick until the token actually expired. Tokens that cannot be
  extended once expired (Threads, the Meta user credential behind Instagram)
  then died and went to `needs_reconnect` after ~60 days; refresh-token
  providers (TikTok, Google, LinkedIn) only renewed late. The check now looks at
  the renewal window: a credential is skipped only when it is valid *and* its
  `renew_at` is in the future (or null, for static credentials).
- LinkedIn `refresh_token_client_mismatch` (a refresh token issued to another
  LinkedIn app, e.g. after switching apps) is now terminal instead of being
  retried as an unknown error.
- Meta errors are classified by code, not by type. Meta sends rate limits (codes
  4, 17, 32, 613, 80001–80014) as `OAuthException` in HTTP 400/403, and every
  `OAuthException` used to be terminal, so a rate limit during renewal flagged
  the credential `needs_reconnect`. Now: invalid token (190, 102, session
  subcodes) and lost permissions (10, 200–299) are terminal; rate limits,
  temporary errors and `is_transient: true` are transient; anything else is an
  unknown (transient, logged) failure whose context carries `code`,
  `error_subcode` and `fbtrace_id`.
- Facebook Pages and LinkedIn organizations are now listed in full.
  `FacebookConnector::fetchPages()` read only the first page of `/me/accounts`
  (25 Pages by default) and `LinkedInConnector::fetchOrganizations()` only the
  first 100 organizations; since `StoreFacebookPages`, `StoreInstagramAccounts`
  and `StoreLinkedInOrganizations` flag every account missing from that list, a
  user with more Pages had the rest flagged `needs_reconnect` on every connect.
  Both now paginate to the end (safety cap: 20 result pages), and any failure
  along the way — including the cap — returns a failure instead of a partial
  list, so the actions throw before reconciling and flag nothing.
- Provider requests had no explicit timeout (Laravel's default: 30s, no connect
  timeout) while the renewal lock lived 30s, so a slow provider could outlive
  the lock and let a second process refresh the same credential — fatal with
  TikTok's single-use refresh tokens. Every connector request now goes through
  `AbstractConnector::http()` (15s timeout, 5s connect timeout, configurable
  under `social-tokens.http`), and the lock lives 60s.
- A renewal that came back without an expiry set `renew_at` to null and kept the
  stale `expires_at`, silently turning the credential static: it was never
  renewed again. The expiry is now recorded as unknown, the next check is
  scheduled one lead time out, and a warning is logged (when
  `log_unknown_errors` is on).
- Credential `scopes` are updated from the scope list TikTok, Google and LinkedIn
  echo on refresh (they were ignored). Account-level `scopes` are unchanged.
- The renewal dispatcher skips credentials no account uses any more (left
  behind when a reconnect created a new credential) instead of renewing them for
  nothing.

### Added
- `SocialToken::isDueForRenewal()`, the instance counterpart of the
  `dueForRenewal` scope.
- `SocialAccount::effectiveStatus()`, `isUsable()` and the `usable()` /
  `unusable()` scopes, which combine an account's status with its credential's.
  A credential in `needs_reconnect` leaves its accounts' `status` column at
  `active`, so a UI reading that column showed dead accounts as healthy. Read
  the effective status instead (see "Account status" in the README).
- `NeedsReconnectException::$transient` and `NeedsReconnectException::transient()`.
  `validAccessTokenFor()` used to throw the same exception for a dead connection
  and for a failure that clears on its own (network, provider 5xx, lock
  contention), so callers could only tell them apart by parsing the message and
  risked asking users to reconnect over a network blip. Transient failures now
  throw with `$transient = true`. Non-breaking: the class and the `for()`
  constructor are unchanged, and existing `catch` blocks still catch both cases.
- `CredentialExpiringSoon` event (`$token`, `$expiresAt`, `$reason`).
- `social-tokens:check-static` command, scheduled daily (`check_static_schedule`,
  `null` disables): asks the provider whether each static credential still works
  and flags the dead ones. Facebook page tokens never expire but die on a
  password change, app removal or lost admin role, and nothing noticed until a
  post failed. Connectors opt in through the new `Contracts\ChecksCredential`;
  `FacebookConnector` implements it.

### Changed
- **Behaviour change:** a credential that cannot be renewed unattended is no
  longer cut off when its renewal window opens. LinkedIn without refresh tokens
  (`ReauthOnly`) used to go `needs_reconnect` five days before expiry, so
  `validAccessTokenFor()` refused a token that still worked; the same happened
  once a refresh token outlived its own lifetime. The job now fires
  `CredentialExpiringSoon` once, sets `renew_at` to the expiry, and flags the
  credential only if it actually expires. If you notified users from
  `CredentialNeedsReconnect` to get them to reconnect in time, listen to
  `CredentialExpiringSoon` for that now.
- The renewal job does nothing when the credential is no longer due (renewed
  synchronously or already warned since it was dispatched).
- Corrected the claim that flagging a credential "fans out" to its accounts'
  rows: it never did. Status is resolved at read time instead.

## [1.0.2]

### Fixed
- `StoreInstagramAccounts` no longer creates an orphaned Meta credential when the
  Facebook user has no linked Instagram Business account (a page-only connection).

## [1.0.1]

### Added
- `SocialTokens::revoke(SocialToken)` — tells the provider to invalidate a
  credential, then marks it and every account it backs revoked.
- `SocialToken::markRevoked()` and the `CredentialRevoked` event.
- End-to-end integration tests (connect → renew → serve; revoke cascade).

### Fixed
- The revoke path was orphaned after 1.0: a revoked credential stayed usable and
  kept being renewed. Revoking now cascades through the credential's status.

### Changed
- `FacebookConnector::renewalStrategy()` is `ExtendLongLived` (the Meta credential
  is extended in place, not rotated) — cosmetic, no behaviour change.
- Refreshed stale config comments (scheduling/logging now describe credentials).

## [1.0.0]

The credential model. Tokens moved out of `social_accounts` into a new
`social_tokens` table: one **credential** backs many **accounts**, and renewal
happens once per credential.

### Added
- `social_tokens` table + `SocialToken` model; `SocialAccount::credential()`.
- `ProviderConnector::refreshCredential(SocialToken)` and `credentialProvider()`.
- `RenewCredential` job (per credential); the dispatcher scans `social_tokens`.
- `CredentialRenewed` / `CredentialNeedsReconnect` events.
- A backfill migration that groups existing accounts into credentials.

### Changed (breaking)
- Renewal is credential-based: `refreshCredential()` replaces `renew()`;
  `revoke()` takes a `SocialToken`.
- `RenewAccountToken` → `RenewCredential`; `TokenRenewed` → `CredentialRenewed`.
- `social_accounts` token columns dropped; read the posting token via
  `$account->credential` or `validAccessTokenFor($account)`.

See the README's "Upgrading to 1.0" for the migration and API details.

## [0.6.0]
- PHPStan (Larastan) at level 8, Laravel Pint, and a CI quality job. Fixed the
  findings, including null-guards in `SocialTokens` and a driver-type check in
  `ConnectorRegistry`.

## [0.5.0]
- Exhaustive Pest + Testbench suite (fully network-free via `Http::fake()`),
  `composer test` scripts, and a GitHub Actions test matrix.

## [0.4.0]
- LinkedIn organization fan-out (`StoreLinkedInOrganizations`).

## [0.3.0]
- Per-account scopes with granular resolution via `debug_token`, and
  `hasScope()` / `hasScopes()` / `missingScopes()` helpers.

## [0.2.0]
- **Breaking:** removed scope prescribing from connectors — scopes are the app's
  decision, supplied at redirect.
- Added Instagram-account fan-out (`StoreInstagramAccounts`).

## [0.1.0]
- Initial release: persist, renew and manage OAuth tokens on top of Socialite,
  with per-provider connectors (TikTok, Google, Instagram, Facebook, Threads,
  LinkedIn), a single `StoreConnection` entry point, scheduled renewal, and the
  needs-reconnect lifecycle.
