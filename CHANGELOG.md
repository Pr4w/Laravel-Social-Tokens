# Changelog

All notable changes to `pr4w/laravel-social-tokens`. This project adheres to
[Semantic Versioning](https://semver.org).

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
