# Changelog

All notable changes to `pr4w/laravel-social-tokens`. This project adheres to
[Semantic Versioning](https://semver.org).

## [Unreleased]

### Added
- **Bluesky** (AT Protocol OAuth), connector key `bluesky`. The package ships
  the `bluesky` Socialite driver (`Socialite\BlueskyProvider`): no Socialite
  provider handles AT Protocol's pushed authorization requests, DPoP and
  per-account authorization servers. `StoreConnection` stores the account
  like any other; see [Bluesky](README.md#bluesky).
  - Tokens are DPoP-bound: each connection's key pair is stored encrypted on
    its credential (new column `dpop_key`, hidden, re-encrypted by
    `social-tokens:reencrypt`, erased on revocation), with the account's
    authorization server endpoints and PDS (new column `metadata`). Run
    `php artisan migrate`.
  - `SocialTokens::authorizedRequestFor($account)`: a request with a valid
    token, DPoP-signed for a DPoP-bound credential, a Bearer one otherwise.
  - `Support\DPoP`: proofs, server nonces (cached per origin, one retry on
    `use_dpop_nonce`) and a Guzzle middleware.
  - Connector hooks, both optional: `keepAlive()`, how often the dispatcher
    renews an idle credential whose access tokens are renewed on demand
    (Bluesky's live minutes), and `credentialAttributesFrom()`, what a
    connection stores on its credential besides the tokens.

## [3.2.1]

### Fixed
- **LinkedIn organizations could not be listed.** `fetchOrganizations()` asked
  organizationAcls for `organization` beside its `organization~` decoration,
  and LinkedIn refuses that projection (400 `ILLEGAL_ARGUMENT`, "Can't merge
  incompatible specs 'organization'"), so `StoreLinkedInOrganizations` threw
  "Could not list LinkedIn organizations (HTTP 400)" and stored nothing. It
  now sends LinkedIn's documented form, `elements*(*,organization~(...))`:
  `*` still brings the organization URN, read when LinkedIn throttles the
  decoration. `LinkedInConnector::ORGANIZATION_PROJECTION` holds it, and a
  test now checks the request sent, not only the response handled.

## [3.2.0]

### Added
- **LinkedIn single authorization mode, on by default**
  (`connectors.linkedin.single_authorization`, `LINKEDIN_SINGLE_AUTHORIZATION`,
  on in a published config without the option too). An app whose one
  LinkedIn app (usually the Community Management one) asks for the scopes of
  the personal profile and of the organizations at once gets one token for
  both; 3.0 stored it on two credentials, the profile's and the
  organizations' (purpose `organizations`), each renewed on its own with the
  same refresh token. In this mode `StoreLinkedInOrganizations` stores the
  member token on the member's default credential, shared with the profile,
  so it is renewed once and one reconnect brings every account back.
  - `StoreLinkedInOrganizations::handle(member: [...])` also stores the
    member's personal profile (name, nickname, email, avatar) on that
    credential, from the same call, and returns it first. Refused
    (`InvalidArgumentException`) outside the mode.
  - A member's separate organizations credential is folded into their default
    one the next time they connect, through either flow
    (`Actions\MergeLinkedInCredentials`): the fresher token is kept (active
    first, then the later expiry), the organizations move over, and the
    separate credential is deleted. `php artisan
    social-tokens:merge-linkedin-credentials` does it for every member, and
    refuses while the mode is off.
  - The `2025_01_01_000008` migration no longer splits the credential a
    member's profile and organizations share while the mode is on.
- `LinkedInConnector::singleAuthorization()`.

**(breaking for apps with two LinkedIn apps)** If your profile and your
organizations come from two LinkedIn apps, set
`LINKEDIN_SINGLE_AUTHORIZATION=false` before deploying: otherwise each
member's next connection folds their two credentials into one, and the
organizations can end up posting with the profile's token
([UPGRADE](UPGRADE.md#from-30-or-31-to-32)).

## [3.1.0]

### Changed
- **LinkedIn refresh tokens are used by default.**
  `refresh_enabled` (`LINKEDIN_REFRESH_ENABLED`) now defaults to true, in the
  config and in a published config without the option: a LinkedIn credential
  that holds a refresh token is renewed with it, 5 days before its access
  token expires, instead of waiting for the member to reconnect. A credential
  without a refresh token is renewed as before: `CredentialExpiringSoon`
  ahead of its expiry, flagged once expired. Set
  `LINKEDIN_REFRESH_ENABLED=false` to keep the 3.0 behaviour; a config
  published with 3.0 keeps `false` until you change it.

### Documentation
- Connecting X with Socialite's `twitter-oauth-2` driver: the connector key,
  the credentials entry and the scopes it needs.

## [3.0.1]

Two defaults of 3.0.0 could change a working app without anyone asking for
it. Both are now off until the app turns them on.

### Changed
- **Pruning is off by default.** 3.0.0 scheduled `social-tokens:prune` daily,
  so the first midnight after an upgrade deleted every credential revoked or
  flagged `needs_reconnect` for 30 days, whether or not the app had decided
  it wanted that. `prune_schedule` now defaults to
  `env('SOCIAL_TOKENS_PRUNE_SCHEDULE')`, unset: nothing is scheduled until
  the app sets it (`SOCIAL_TOKENS_PRUNE_SCHEDULE=daily`, or any form
  `dispatch_schedule` takes). A value that cannot be read logs an error and
  leaves pruning off. The command can still be run by hand.
- **`appsecret_proof` is off by default.** 3.0.0 signed Meta Graph calls by
  default, and Meta refuses a proof made for a token another Meta app
  issued: an app that had switched Meta apps saw `check-static` stop checking
  its Pages. `META_APPSECRET_PROOF` now defaults to false, in the config and
  in a published config without the option; set it to true before turning on
  "Require App Secret" in the Meta app
  ([UPGRADE](UPGRADE.md#meta-calls-can-carry-appsecret_proof)).

**From 3.0.0.** A config published with 3.0.0 keeps the old values: set
`'prune_schedule' => env('SOCIAL_TOKENS_PRUNE_SCHEDULE')` and
`'appsecret_proof' => env('META_APPSECRET_PROOF', false)` (in the `facebook`
and `instagram` connectors) to take the new defaults.

## [3.0.0]

3.0.0 adds X (Twitter), Instagram accounts without a Facebook Page, and
answers to Meta's deauthorize and data deletion callbacks. Most of the rest
closes ways a working credential was lost, a dead one kept, or a token leaked.

**Breaking.** Entries marked **(breaking)** can need a change in your code,
config or data: [UPGRADE.md, From 2.x to 3.0](UPGRADE.md#from-2x-to-30) takes
you through them in order. Run the migrations as part of the deploy, before
the 3.0 code serves requests or runs jobs: it reads and writes the columns
they add.

### Security
- **No package frame records a token in an exception trace any more.** PHP
  records call arguments in a trace unless `zend.exception_ignore_args` is On
  (it is Off without a php.ini, as in the official Docker images), and error
  trackers such as Sentry and Flare collect them. When `StoreFacebookPages`,
  `StoreInstagramAccounts` or `StoreLinkedInOrganizations` threw (a Graph or
  LinkedIn 5xx while listing Pages or organizations), the trace held the
  extended 60-day Meta user token, or the LinkedIn member and refresh tokens.
  Every parameter that carries a token or a secret is now
  `#[\SensitiveParameter]`, the Socialite user passed to `StoreConnection` and
  `StoreAccountFromSocialite` included: traces show a `SensitiveParameterValue`
  in its place. The attribute only covers the package's own frames, so the
  `Store*` actions also stopped handing plaintext tokens to Eloquent: when
  storing a connection failed at the database (connection lost, deadlock, disk
  error), the frames of `updateOrCreate()` and the query methods below it held
  the user, Page and refresh tokens. The tokens are now encrypted before the
  write starts (`SocialToken::persistCredential()`, see Added), so those frames
  only see ciphertext. Frames the package does not control (your own code
  handling a token, another package) can still record one:
  `zend.exception_ignore_args=On` in production is the complete fix.
- **(breaking)** **Queued listeners no longer copy whole models into the
  queue.** The seven events lacked `SerializesModels`, so a `ShouldQueue`
  listener had the event PHP-serialised with every attribute and loaded
  relation: the credential's token ciphertext, and on `AccountConnected` the
  owner and `connectedBy` models (your User's password hash,
  `remember_token`, email). It sat in Redis or the jobs table, in Horizon, and
  in `failed_jobs` for good. Every event now uses `SerializesModels`: the
  payload holds the class and key, and a queued listener receives the account
  or credential as it is in the database when the listener runs, not a copy
  taken at dispatch. It fails with `ModelNotFoundException` if the row was
  deleted in between, or not yet committed when the event fired inside your
  own transaction. Payloads queued by 2.x still run
  ([UPGRADE](UPGRADE.md#queued-listeners-re-read-the-models)).
- **(breaking)** **Revoking a credential erases its tokens.** `revoke()`,
  `disconnect()` when it revokes a credential, and the deauthorize callbacks
  (`revokeExternalUser()`) changed the status only: the access and refresh
  tokens stayed in the row, decryptable with `APP_KEY`, and often still valid
  at the provider, since Facebook, Instagram, Threads and LinkedIn have no
  provider-side revocation and Google and TikTok skip it while another owner
  holds a grant. A Facebook Page token never expires. The write that marks a
  credential revoked now also sets `access_token`, `refresh_token`,
  `expires_at`, `refresh_expires_at` and `renew_at` to null, so they are null
  in a `CredentialRevoked` listener too; the provider is called with a copy
  taken before. Migration
  `2025_01_01_000010_clear_tokens_of_revoked_credentials` erases the tokens of
  the credentials revoked before (it can be run again, keeps `updated_at`, and
  cannot be rolled back). Code that read the token of a revoked credential (to
  call the provider itself, for example Meta's `DELETE /{user-id}/permissions`)
  must read it before revoking, or do it in a connector's `revoke()`, which
  still receives a copy holding the tokens. A reconnect no longer finds a
  revoked credential's refresh token to fall back on when the provider sends
  none: a Google reconnect without `prompt=consent` then stores no refresh
  token ([UPGRADE](UPGRADE.md#revoked-credentials-keep-no-tokens)).
- **(breaking)** **Disconnecting a user's last Facebook Page revokes the Meta
  user token behind it.** `disconnect()` followed only the Page's own
  credential, so the user credential (`provider` `facebook`,
  `provider_holder_id` the Facebook user) stayed `active`, holding a 60-day
  token that can mint a Page token for every Page the user manages, no longer
  renewed nor checked. `disconnect()` now also revokes, tokens erased, the
  credential the account's holder keeps under the same owner once no account
  of that holder and owner is connected: the last Facebook Page or Instagram
  account of a Facebook user revokes the Meta user credential, firing one more
  `CredentialRevoked`. Disconnecting a user's last Instagram account no longer
  revokes that credential while a Page of the same user and owner is
  connected; 2.x revoked it then, although the Pages kept it renewed. An
  `instagram` connection stores the Facebook Page linked to each Instagram
  account as a `facebook` account, so an app that shows Instagram accounts
  only must disconnect those Pages too for the token to be revoked
  ([UPGRADE](UPGRADE.md#revoked-credentials-keep-no-tokens)).

### Added
- **X (Twitter) support** through OAuth 2.0: `XConnector`, registered as `x`
  (Socialite's `x` driver, `services.x`). 2-hour access tokens renewed 30
  minutes ahead; single-use rotating refresh tokens (`offline.access` required);
  HTTP Basic client authentication on `api.x.com`; X's dead-refresh-token answer
  (`invalid_request` "Value passed for the token was invalid.") is terminal,
  while a request built wrong, a missing Authorization header or a suspended app
  is an app-client failure (retried, alerted, never flagged); best-effort revoke
  that logs a failure. OAuth 1.0a is not supported. A published config needs
  the `x` entry ([UPGRADE](UPGRADE.md#check-your-published-config)).
- **Instagram without a Facebook Page**, through the Instagram API with
  Instagram Login: `InstagramLoginConnector`, registered as `instagram_login`
  (its own credentials, `services.instagram_login`: the Instagram app id and
  secret). `StoreConnection` stores one account per connection, never the
  Facebook Page fan-out; the 1-hour token is exchanged at connect
  (`ig_exchange_token`), and the 60-day token is extended a week ahead
  (`ig_refresh_token`, once it is 24 hours old) and checked on
  `graph.instagram.com/me`. A creator or business account not linked to a
  Page could not be connected before: the `InstagramConnector` docblock said
  swapping its renewal request was enough, but `StoreConnection` sent every
  `InstagramConnector` to the Facebook Page fan-out and renewed it on
  `graph.facebook.com`, which refused the token.
- **Meta deauthorize and data deletion callbacks.** When a user removed the
  app, or asked for their data to be deleted, the package could not act on
  Meta's callback: it had no `signed_request` verifier, no handler, and no way
  to reach every row of one external user across owners (a user's Page
  credentials are keyed by the Page, and revoking the user credential did not
  reach them). Until a post failed or a renewal ran, their accounts stayed
  usable. Now:
  - `SocialTokens::revokeExternalUser($provider, $externalId)` revokes,
    locally and under every owner, the credentials the user holds, the
    accounts recorded under them (`provider_holder_id`: Pages and Instagram
    accounts), the credentials those accounts post with (the Pages' tokens)
    and every account posting with one of those. `facebook` and `instagram`
    reach the same rows. The provider is not called.
  - `SocialTokens::purgeExternalUser($provider, $externalId, $deleted = null)`
    deletes those credentials and revokes those accounts, which keep their
    rows without a credential. The `$deleted` closure receives the accounts as
    soon as the tokens are deleted, even when a listener throws.
  - `Actions\HandleDeauthorizeCallback` (Deauthorize, Threads' Uninstall) and
    `Actions\HandleDataDeletionCallback` (Data Deletion Request, Threads'
    Delete), which verify the `signed_request` with the connector's app
    secret; the second answers `{url, confirmation_code}` and fires
    `Events\ExternalUserDataDeleted` for the app to delete the rest, even
    when an `AccountRevoked` listener throws (its exception is reported).
    `Actions\ShowDataDeletionStatus` serves the status page, recognising only
    codes signed with the app key (`Support\DeletionConfirmation`).
  - Their routes, `POST {prefix}/{provider}/deauthorize`,
    `POST {prefix}/{provider}/delete` and
    `GET {prefix}/{provider}/deletion/{code}`, registered only with
    `callbacks.enabled` (`SOCIAL_TOKENS_CALLBACKS`, off by default), under
    `callbacks.prefix` (`social-tokens/meta`) with `callbacks.middleware`
    (none); `callbacks.deletion_status_url` points the answer at the app's
    own page (`{provider}` and `{code}` in it are replaced; without `{code}`,
    `?code=` is appended). `Support\CallbackRoutes::register()` registers
    them anywhere, `routes/web.php` included: the POST routes skip CSRF
    verification, since Meta sends no token.
  - `Support\MetaSignedRequest::parse($signedRequest, $secret)` (and
    `make()`), which reads nothing in the payload before its signature
    checks out, `Exceptions\InvalidSignedRequestException`, and the
    `Contracts\VerifiesSignedRequests` connector capability, implemented by
    the Facebook, Instagram, Instagram Login and Threads connectors.
- **(breaking)** **Meta "Require App Secret" support.** Graph calls made with
  a user or Page token (`/me` at connect and in `check-static`, `/me/accounts`
  and its `paging.next` pages) carry `appsecret_proof`, the HMAC-SHA256 of the
  token keyed with the Meta app secret. Turning the setting on used to break
  every Facebook and Instagram connection and leave every Page unchecked. It
  is sent by default, through the `appsecret_proof` option of the `facebook`
  and `instagram` connectors (`META_APPSECRET_PROOF`, on even when a published
  config lacks it), and `appSecretProof($token)` on both connectors signs your
  own Graph calls. Meta accepts a proof whether or not "Require App Secret" is
  on, but it only matches tokens issued to the configured app: tokens issued
  by another Meta app fail `social-tokens:check-static` with "Invalid
  appsecret_proof" until you set `META_APPSECRET_PROOF=false`
  ([UPGRADE](UPGRADE.md#meta-calls-can-carry-appsecret_proof)).
- **Meta's data access deadline is tracked and announced.** Meta stops
  sharing a user's data with the app 90 days after the user was last active
  (`data_access_expires_at`), although the token keeps working and renewing;
  only a new login moves it. Nothing in the package read it, so a credential
  could lose data access while it looked healthy, with no warning. It is now
  stored on the credential at connect and after each renewal; a reconnect
  whose `debug_token` call fails clears the old one (unknown) rather than keep
  a deadline the new login has moved. `social-tokens:dispatch-renewals` fires
  `CredentialDataAccessExpiring` once, seven days before it, for a credential
  an active account posts with (the Instagram accounts), after dispatching the
  run's renewals: a failure of that check never holds them back. It is a
  warning only: Meta does not say which calls a lapse blocks for an app with
  business permissions, so the credential is not flagged. With it:
  - `Events\CredentialDataAccessExpiring($token, $dataAccessExpiresAt)`, fired
    once per deadline, and the `data_access_warning` config block (`enabled`,
    `SOCIAL_TOKENS_DATA_ACCESS_WARNING`, default on; `lead_days`, default 7).
  - `social_tokens.data_access_expires_at` and `data_access_warned_at`
    (migration `2025_01_01_000009_add_data_access_expiry_to_social_tokens`),
    `SocialToken::isDataAccessExpired()`,
    `SocialToken::warnDataAccessExpiring()` and the
    `dataAccessExpiringBefore($date)` scope. Storing a new
    `data_access_expires_at` re-arms the warning.
- **(breaking)** `social-tokens:prune` deletes the credentials nothing will
  use again, once they have not changed for `prune_after_days` (30, at least
  1; `--days` overrides it): revoked ones, ones flagged `needs_reconnect`, and
  ones no connected account depends on, by `social_token_id` or as the holder
  of its accounts under the same owner (left behind by a reconnect, or by
  accounts the app deleted). Nothing removed a credential before, and a
  renewable one with a past `renew_at` was read again by every dispatcher
  run. The scheduler runs it daily (`prune_schedule`, any form
  `dispatch_schedule` takes; `null` disables it), `withoutOverlapping()` and
  `onOneServer()`. The query is `SocialToken::prunable($before)`. Accounts are
  never deleted: one whose credential is deleted keeps its row, without a
  credential (`social_token_id` null), so `$account->credential` can be null,
  as it already could after a data deletion request. Such an account keeps no
  other credential of its holder in use either (`inUse()`, `prunable()`,
  `disconnect()`): a Facebook Page whose Page token was pruned no longer keeps
  the Meta user token renewed, nor do LinkedIn organizations whose credential
  was pruned keep the member's personal one, and disconnecting the profile
  revokes it
  ([UPGRADE](UPGRADE.md#unused-credentials-are-unscheduled-then-pruned)).
- `social-tokens:reencrypt` re-encrypts every stored token with the current
  `APP_KEY`, decrypting with `APP_PREVIOUS_KEYS`, so a previous key can be
  retired. The `encrypted` cast only re-encrypts a value it writes, and a
  Google refresh token or a Facebook Page token is never written again: once
  the old key was dropped, those credentials no longer decrypted. Tokens
  already under the current key are left as they are, and `updated_at` is
  kept. It runs alongside renewals and reconnects: a row is written only while
  it holds the ciphertext that was read, a row changed meanwhile is read again
  and rewritten (a Google renewal leaves the refresh token under the old key),
  and a renewal in flight still stores its result over the rewritten tokens
  (for TikTok and X, the only copy of the rotated refresh token). It exits
  with a failure code when a row kept changing (run it again), and when a
  token decrypts with none of the keys, which it logs and leaves; with
  `--flag-undecryptable` it flags those credentials `needs_reconnect` instead,
  for keys that are truly lost.
- `SocialTokens::forgetOwner(Model $owner)` tears down what an owner holds,
  for when the owner itself is deleted: its credentials are revoked (tokens
  erased, the provider called unless another owner still holds a grant for
  the same external user), its accounts revoked, then both are deleted, and
  the deleted accounts returned. The owner columns carry no foreign key, so
  a deleted workspace used to leave its accounts active and its credentials
  renewed for good, each counting as a grant that kept every other owner's
  `revoke()` and `disconnect()` of the same TikTok or Google user from
  reaching the provider. `CredentialRevoked` and `AccountRevoked` fire before
  the rows are deleted, so a queued listener of either fails with
  `ModelNotFoundException`
  ([UPGRADE](UPGRADE.md#tear-down-the-owners-you-delete)).
- `SocialTokens::adopt(SocialAccount $account, Model $owner)` gives an
  owner-less account an owner, with the credentials it depends on (the one
  it posts with, and the Meta user token behind a Facebook Page) once no
  other owner-less account uses them, in one transaction. A LinkedIn
  member's profile and organizations post with separate credentials: each
  moves with its own accounts, to whichever owner adopts them. It refuses an
  account of another owner, and one the owner already has (a reconnect
  stored a row of its own). Given an account the owner already has, it
  moves its owner-less credentials, which finishes an adoption made with
  `associate()`. A credential it moves that has an expiry but no `renew_at`
  (the dispatcher unscheduled the Meta user token behind a Page attached by
  hand, which no account of its owner kept in use) is scheduled again
  ([UPGRADE](UPGRADE.md#owner-less-rows-you-attached-by-hand)).
- **Testing helpers for apps** (README, "Testing your app"):
  - Model factories: `SocialAccount::factory()` makes an active account with
    a credential that matches it as a connection stores them (provider,
    holder, owner; a Facebook Page on a never-expiring Page token, an
    Instagram account on the Meta user credential), shared by the accounts
    of one holder as at a connection (the organizations of one LinkedIn
    member, the Instagram accounts of one Facebook user), and
    `SocialToken::factory()` a credential alone, scheduled with its
    provider's lead time. States: `forProvider()`, `ownedBy()`,
    `connectedBy()`, `heldBy()`, `linkedInOrganization()`, `credential()`,
    `needsReconnect()`, `revoked()`, `disconnected()` on accounts;
    `forProvider()`, `ownedBy()`, `forPurpose()`, `dueForRenewal()`,
    `expired()`, `neverExpiring()`, `withoutRefreshToken()`,
    `needsReconnect()`, `revoked()` on credentials. They live in
    `Pr4w\SocialTokens\Database\Factories` and need no Faker.
  - `Pr4w\SocialTokens\Facades\SocialTokens`, registered as the
    `SocialTokens` alias. Its `fake()` swaps in `Testing\SocialTokensFake`:
    the real service against the test database, with the real connectors,
    whose renewals, provider-side revocations and `reportRejected()` checks
    are answered without a request (`renewWith()`, `checkWith()`) and
    recorded (`assertRenewed()`, `assertNotRenewed()`,
    `assertRevokedAtProvider()`, `assertNotRevokedAtProvider()`,
    `assertChecked()`, `assertNotChecked()`).
  - `Testing\FakeConnector`, a connector that makes no request, for a
    provider key of an app's tests: scripted renewals, connect-time
    exchange and revocation, and the same assertions.
- `social_accounts.disconnected_at` (migration
  `2025_01_01_000011_add_disconnected_at_to_social_accounts`, nullable, no
  index), set by `disconnect()` through `SocialAccount::markDisconnected()`
  (also on an account already revoked, without a second `AccountRevoked`).
  `SocialAccount::wasDisconnected()` tells, and
  `SocialAccount::clearDisconnected()` forgets it (unsaved), so that the next
  reconnect restores that account. A `restoreDisconnected` option on
  `StoreConnection::handle()`, `StoreFacebookPages::handle()`,
  `StoreInstagramAccounts::handle()`, `StoreLinkedInOrganizations::handle()`
  and `StoresConnections::storeConnection()` brings back every disconnected
  target of a connection: see "A reconnect no longer brings back an account
  the user disconnected" under Changed.
- `social_tokens.purpose`, `SocialToken::DEFAULT_PURPOSE` (`''`),
  `StoreLinkedInOrganizations::PURPOSE` (`'organizations'`) and the
  `SocialToken::forPurpose($purpose = '')` scope (migration
  `2025_01_01_000008_add_purpose_to_social_tokens`): see "A LinkedIn member's
  personal profile and organizations have separate credentials" under Changed.
  `persistCredential()` keys without a purpose name the default credential.
- `OAuth2Connector`, the base for OAuth 2.0 refresh-token providers. TikTok,
  Google, LinkedIn and X now share one refresh request, response parsing and
  error-classification flow; a provider declares its endpoint, parameters and
  error codes (`classifyError()`).
- `Connectors\MetaGraphConnector`, the base of `FacebookConnector` and
  `InstagramConnector` (the Facebook Login path). It holds the one
  `fb_exchange_token` call, `exchangeUserToken($userToken)` (a
  `RenewalResult`), the Graph version, `graphVersion()` with the
  `MetaGraphConnector::DEFAULT_GRAPH_VERSION` default (`v23.0`), the
  `appsecret_proof` signing, `debugToken($userToken)` (debug_token's `data`),
  the static `tokenLifetime($data)` (`never_expires`, `expires_at`,
  `data_access_expires_at`, in the app's timezone) and
  `withTokenLifetime($result)`. The two connectors carried a copy each of the
  call and of the version default, so a fix made to one missed the other.
  `FacebookConnector::grantedScopesFrom($data, $accountIds)` reads the
  per-account scopes from a `debug_token` reply, and `grantedScopesByAccount()`
  now uses it: the connect-time actions call `debugToken()` once and read the
  scopes and the lifetime from its reply. `FacebookConnector::extendUserToken()`
  keeps its array shape, for your own code: the package calls
  `exchangeUserToken()` (see Changed).
- `Connectors\LongLivedTokenConnector`, the base of `ThreadsConnector` and
  `InstagramLoginConnector`: a Meta API whose long-lived token extends itself
  with a `refresh_access_token` call, guarded by the 24-hour minimum age, with
  a connect-time exchange and a `/me` check. A product declares its URLs,
  grant names and label.
- `Contracts\FansOutConnections`, a connector capability: a connector whose
  connections yield several accounts names the action that stores them
  (`connectionAction()`). `FacebookConnector` names `StoreFacebookPages`,
  `InstagramConnector` names `StoreInstagramAccounts`. That action implements
  `Contracts\StoresConnections`: `storeConnection($provider, $user, $owner,
  $connectedBy, $longLived, $refreshExpiresAt, $restoreDisconnected)`,
  implemented by `StoreAccountFromSocialite`, `StoreFacebookPages` and
  `StoreInstagramAccounts`. `StoreConnection` hands a connection to that
  action with every option; `StoreAccountFromSocialite` refuses it.
- `SocialToken::persistCredential($keys, $values, $connector = null)`:
  `updateOrCreate()` for a token the provider has just issued, which encrypts
  it before the write so a failed write leaves no plaintext token in the
  exception trace (see Security). Unless `$values` says otherwise, the
  credential comes back active, with no `last_error`, no `check-static` strike
  and `last_renewed_at` set to now; given the connector, `renew_at` is
  scheduled for the credential as stored, with its own strategy. The `Store*`
  actions use it; use it too if you store credentials yourself.
- `SocialToken::tryApplyRenewal($result, $connector)`: `applyRenewal()` that
  returns whether it stored the renewal (see Fixed, "A reconnect during a
  renewal is no longer undone").
- `SocialToken::markNeedsReconnectIfStillHeld($reason)`: flags the credential
  only while the row still holds the access token the instance holds (by its
  ciphertext when it no longer decrypts), for a Terminal result of
  `renewCredential()`. `holdsAccessToken($token)` compares a token with the
  stored one, `canDecrypt($column)` and `tokensDecrypt()` say whether the
  tokens decrypt with `APP_KEY` or `APP_PREVIOUS_KEYS`, and
  `SocialToken::UNDECRYPTABLE` is the reason given for a credential that no
  longer decrypts (see Fixed).
- `AbstractConnector::renewalStrategyFor(SocialToken $token)`: the strategy for
  one credential, `renewalStrategy()` by default. `LinkedInConnector` returns
  `ReauthOnly` for a credential without a refresh token (see Fixed).
  `SocialToken::renewalStrategyWith($connector)` asks it of any connector, and
  `SocialToken::renewAtFor()` takes the credential's strategy as a fourth
  argument.
- `refreshExpiresAt:` on `StoreConnection::handle()` and
  `StoreAccountFromSocialite::handle()`: the refresh token's expiry, which
  core Socialite's drivers do not expose. The 1.2 entry saying
  `StoreAccountFromSocialite` reads LinkedIn's `refresh_token_expires_in` holds
  only for a driver that exposes the token response
  (`accessTokenResponseBody`, SocialiteProviders); core Socialite's `linkedin`
  and `linkedin-openid` drivers drop it, and so did the README's
  `StoreLinkedInOrganizations` example, which now passes it.
- `RenewalResult::success()` takes `neverExpires:` (the provider says the token
  does not expire: the credential is stored static) and `dataAccessExpiresAt:`;
  both are readable on the result.
- Revocation logging. `GoogleConnector`, `TikTokConnector` and `XConnector` log
  a warning when the provider answers a revocation without confirming it (an
  HTTP error, or an `error` in the body, which TikTok may send with a 200):
  `[social-tokens] {provider} did not confirm the token revocation`, with
  `provider`, `token_id`, `status` and the `error` code, never the body. 2.x
  ignored Google's and TikTok's answer. A connector of your own logs the same
  with `AbstractConnector::logUnconfirmedRevoke()`, and a failure with
  `logRevokeFailure()`; `ProviderConnector::revoke()` is documented as best
  effort that must not throw.
- `Support\Settings::flag()`: reads a boolean config value the way the
  package's own settings are read (`'false'`, `'off'`, `'no'`, `'0'` are false).
- `SocialTokens` reaches the providers through two protected methods,
  `refreshAtProvider()` (renewals) and `checkAtProvider()` (the check of
  `reportRejected()`), next to `revokeAtProvider()`, so that a subclass can
  answer in their place.
- Migration `2025_01_01_000006_index_social_token_id_on_social_accounts`: an
  index on `social_accounts.social_token_id`. PostgreSQL and SQLite do not index
  a foreign key column, so finding a credential's accounts (the renewal
  dispatcher, `revoke()`, `disconnect()`, deleting a credential, the 000007
  backfill) scanned the whole table. On MySQL the foreign key's own index is
  kept and nothing is added.

### Changed
- **(breaking)** The 3.0 code needs the 3.0 schema: run the migrations before,
  or together with, the code deploy. Until
  `2025_01_01_000008_add_purpose_to_social_tokens` has run, every connection
  throws a `QueryException` (unknown column `purpose`). Until
  `2025_01_01_000009_add_data_access_expiry_to_social_tokens` has run,
  Facebook and Instagram (Facebook Login) connections throw one too (unknown
  column `data_access_expires_at`), the renewal of a Meta user credential fails
  and is retried, and each dispatcher run still dispatches all renewals, then
  reports the failed data access check and exits with a failure code (on
  SQLite the check silently finds nothing). Until
  `2025_01_01_000011_add_disconnected_at_to_social_accounts` has run,
  `disconnect()` throws (unknown column `disconnected_at`), changing nothing
  ([UPGRADE](UPGRADE.md#run-the-new-migrations)).
- **(breaking)** `provider` is `varchar(50)` on `social_accounts` and
  `social_tokens` on MySQL, MariaDB and PostgreSQL (migration
  `2025_01_01_000006_shorten_provider_columns`). As `varchar(255)` it left no
  room in the owner-scoped unique keys for a uuid or ulid owner id: a fresh
  install on MySQL with uuid or ulid owner keys failed in migration 000007 with
  "Specified key was too long". A connector key must be at most 50 characters;
  the migration refuses, changing nothing, while a longer one is stored. SQLite
  declares no length and SQL Server has no key-length cap, so both are left as
  is. Rolling the migration back keeps `varchar(50)`, which 2.x reads fine. The
  README Install section now says to call `Schema::morphUsingUuids()` /
  `morphUsingUlids()`, or to declare the accounts' owner columns as uuid or
  ulid, before migrating.
- **(breaking)** **A LinkedIn member's personal profile and organizations have
  separate credentials.** The profile (Socialite: OpenID scopes and
  `w_member_social`) and the organizations (an app-side exchange with
  `w_organization_social`) are two authorizations, which 2.x stored on one
  shared credential: whichever flow ran last replaced the other's access
  token, refresh token, expiry and scopes. Posts as the organizations then
  failed with a 403 while their rows still listed `w_organization_social` in
  `hasScope()`, `usable()` still returned them, and LinkedIn introspection
  answered `active`, so nothing was flagged. A credential is now unique per
  `(provider, provider_holder_id, purpose, owner)`: `StoreLinkedInOrganizations`
  stores the member token on a credential with `purpose = 'organizations'`
  (`StoreLinkedInOrganizations::PURPOSE`), and the profile stored through
  `StoreConnection` / `StoreAccountFromSocialite` keeps the default one
  (`purpose = ''`). Migration `2025_01_01_000008_add_purpose_to_social_tokens`
  adds the column, replaces the unique key `social_tokens_owner_identity_unique`
  with `social_tokens_identity_purpose_unique`, and splits the credentials 2.x
  shared between the two flows. On MySQL and MariaDB it checks that the new key
  fits InnoDB's 3072-byte limit before changing anything; it can be run again
  after a failure, splits in chunks of 100 credentials, each in its own
  transaction, and its `down()` refuses while a member has two credentials for
  one owner. A lookup of a credential by `(provider, provider_holder_id,
  owner)` may now find two rows for a LinkedIn member: name the purpose
  (`forPurpose()`). The two credentials renew, warn
  (`CredentialExpiringSoon`), get flagged and are reconnected separately, and
  each is kept alive by the accounts posting with it, so the organizations'
  token is no longer renewed (nor warned about) for a member who only keeps
  the profile. Disconnecting the personal row revokes the profile's
  credential, and revoking an organization's credential no longer revokes the
  personal row
  ([UPGRADE](UPGRADE.md#linkedin-profiles-and-organizations-have-separate-credentials)).
- **(breaking)** **A reconnect no longer brings back an account the user
  disconnected.** The fan-out actions store every target the provider lists
  and wrote `active` over each row and its credential, so the Page, Instagram
  account or LinkedIn organization a user removed came back, usable, returned
  and announced by `AccountConnected`, at the next reconnect of the same
  login: to refresh the token, to add a Page, or the ones Meta's 90-day data
  access and LinkedIn's 60-day re-authorisation force. `disconnect()` now
  records when (`social_accounts.disconnected_at`, see Added), and the fan-out
  actions (`StoreFacebookPages`, `StoreInstagramAccounts`,
  `StoreLinkedInOrganizations`, and `StoreConnection` for Facebook and
  Instagram) leave such an account out: it stays revoked, with its revoked
  credential, is not returned, and fires no `AccountConnected`. Pass
  `restoreDisconnected: true` to bring it back. Reconnecting a 1:1 account
  (TikTok, Google, X, Threads, Instagram Login, a LinkedIn profile) still
  restores it, and so does any reconnect of an account revoked otherwise (a
  deauthorization, `revoke()`, `markRevoked()`). A connection that lists only
  disconnected targets returns an empty collection, and stores no shared
  credential unless the stored one still backs another account. Accounts
  disconnected under 2.x carry no date, so a reconnect still brings them back
  ([UPGRADE](UPGRADE.md#disconnected-accounts-stay-disconnected)).
- **(breaking)** `reportRejected(terminal: false)` flags only on a definitive
  answer from the provider (a Meta session subcode, LinkedIn's `revoked`), the
  standard `check-static` has applied since 1.2. A bare Meta 190/102, which
  Meta also sends to valid tokens during an incident, is now one strike
  against the token and the call returns `false`. Only
  `social-tokens:check-static` confirms it, flagging the credential if Meta
  still rejects the token (renewable credentials only with `check_renewable`),
  and reports never confirm each other: an app that set
  `check_static_schedule` to `null` must schedule or run the command itself,
  or such a report never flags. It used to flag at once, with no breaker, so
  every credential that published during such an incident was flagged. A
  report naming a token the credential no longer holds now returns `false`
  without asking the provider
  ([UPGRADE](UPGRADE.md#review-your-reportrejected-calls)).
- **(breaking)** A transient renewal failure throws
  `Exceptions\TransientRenewalException`, a `RuntimeException` with the same
  "Transient renewal failure: ..." message, instead of a plain
  `RuntimeException`. It implements `ShouldntReport`, so it no longer reaches
  your exception handler or error tracker: a provider outage used to send one
  event per credential per attempt and per cycle. Instead,
  `RenewCredential::failed()` logs one warning ("Renewal failed after retries;
  retrying later") when it backs a credential off: alert on it. Uncatalogued
  errors are still logged as they occur (`log_unknown_errors`)
  ([UPGRADE](UPGRADE.md#transient-renewal-failures-are-no-longer-reported)).
- **(breaking)** `StoreAccountFromSocialite` throws `InvalidArgumentException`,
  writing nothing, for a provider whose connections fan out (`facebook`,
  `instagram`, any connector implementing `FansOutConnections`, subclasses of
  `FacebookConnector` and `InstagramConnector` included), unless the
  connector names `StoreAccountFromSocialite` as its connection action. It
  used to store the Facebook user as a Page-less `facebook` account, or as an
  `instagram` account carrying the Facebook user's id, which nothing could post
  with and the next fan-out connection flagged as no longer managed, and to
  overwrite the shared Meta user credential with Socialite's short-lived token
  ([UPGRADE](UPGRADE.md#store-connections-through-the-connector)). A subclass
  of `InstagramConnector` that stored one account per connection, as 2.x's
  docblock suggested for Instagram Login, moves to `InstagramLoginConnector`,
  or returns `StoreAccountFromSocialite::class` from `connectionAction()`
  ([UPGRADE](UPGRADE.md#instagram-login-through-a-subclass-of-instagramconnector)).
- **(breaking)** `StoreConnection` picks the action from the connector
  (`FansOutConnections::connectionAction()`, see Added) instead of checking
  for `FacebookConnector` and `InstagramConnector`; a subclass of either
  inherits its action. Its constructor takes the `ConnectorRegistry`, the
  container and `StoreAccountFromSocialite`, no longer the two Meta actions:
  resolve it from the container. It enters `StoreFacebookPages` and
  `StoreInstagramAccounts` through `storeConnection()`, not `handle()`: a
  subclass bound in their place whose `handle()` adds checks or fields no
  longer runs them for `StoreConnection`. And `handle()` gained trailing
  parameters (`restoreDisconnected` on the three fan-out actions,
  `refreshExpiresAt` on `StoreAccountFromSocialite`): an override with the 2.x
  signature no longer loads
  ([UPGRADE](UPGRADE.md#store-connections-through-the-connector)).
- **(breaking)** `social-tokens:dispatch-renewals` unschedules a due credential
  no active account depends on (`renew_at` null) instead of leaving it due,
  where every run read it again through two correlated subqueries, for good. A
  reconnect schedules it again, and so do a re-sync with the stored token
  (LinkedIn organizations, Facebook Pages and Instagram accounts) and
  `adopt()`. An account made active by hand (an `UPDATE`) leaves its
  credential unscheduled: set the credential's `renew_at` to now as well.
  Otherwise a credential with a refresh token is renewed only once its token
  has expired, by `validAccessTokenFor()`, and one whose token must be
  extended while still valid (the Meta user token, Threads, Instagram Login)
  is flagged `needs_reconnect` by the first publish after its expiry
  ([UPGRADE](UPGRADE.md#unused-credentials-are-unscheduled-then-pruned)).
- **(breaking)** Config values are read and normalised in one place,
  `Support\Settings`. Numeric strings work for `queue.tries`, the HTTP timeouts
  and the breaker, and the HTTP timeouts keep fractional seconds
  (`'connect_timeout' => 2.5` used to be cut to 2). `queue.tries` set to
  `null`, an empty string or a non-numeric value means 4 attempts; 2.x read it
  as 0, unlimited: write `0` to keep unlimited retries
  ([UPGRADE](UPGRADE.md#check-your-published-config)).
- **(breaking)** The connectors share base classes. `FacebookConnector` and
  `InstagramConnector` extend `Connectors\MetaGraphConnector`,
  `ThreadsConnector` extends `Connectors\LongLivedTokenConnector`, and
  `TikTokConnector`, `GoogleConnector`, `LinkedInConnector` and `XConnector`
  extend `OAuth2Connector` (see Added); the methods they shared moved there.
  What a subclass of a built-in connector must change
  ([UPGRADE](UPGRADE.md#if-you-wrote-a-connector)):
  - The package no longer calls `FacebookConnector::extendUserToken()` or
    `grantedScopesByAccount()`, at connect or at renewal, so an override of
    either is skipped: override `exchangeUserToken()` (a `RenewalResult`), and
    `debugToken()` or the static `grantedScopesFrom()`, instead. The `Store*`
    actions call `grantedScopesFrom()` and `tokenLifetime()` on the configured
    connector class, so a subclass's own versions are used.
  - `InstagramConnector` reads an `fb_exchange_token` reply exactly as
    `FacebookConnector` does: a reply without a token is "Malformed token
    extension response." (it was "Malformed response, no access_token."), and
    an `expires_in` that is not a number means an unknown expiry. A
    `graph_version` set to an empty string takes the default version instead
    of building a URL without one.
  - `ThreadsConnector`'s requests and failure reasons are unchanged;
    `refreshWithToken()` is protected instead of private, and an `expires_in`
    that is not a number means an unknown expiry.
  - The one-argument `mapError()` of `GoogleConnector` and `TikTokConnector`
    is gone (see Removed).
- A Meta user credential's renewal makes a second call, `debug_token` on the
  new token, to read its lifetime (see Fixed). A failure of that call leaves
  the renewal as it was, the stored data access deadline included.
- The RFC 6749 `temporarily_unavailable` and `server_error` token-endpoint errors
  are known transient failures: still retried, no longer logged as uncatalogued.
- OAuth token-endpoint failures carry the HTTP `status` in their context, and
  terminal ones carry `error` / `error_description` too.
- `CHANGELOG.md` ships in the package (it was export-ignored), next to
  `README.md` and `UPGRADE.md`, which link to it.
- The models' `@property` blocks list every column (`id`, `created_at`,
  `updated_at`, and `connected_by_type` / `connected_by_id` on
  `SocialAccount`), and the package's own analysis checks every column name
  it writes or filters on (Larastan's `checkModelProperties`).
  `SocialToken::persistCredential()` declares `$keys` and `$values` as arrays
  keyed by the model's columns: with `checkModelProperties` on in your own
  PHPStan setup, pass it literal arrays or annotate yours accordingly. No
  runtime change.

### Fixed
- **(breaking)** **A reconnect during a renewal is no longer undone.** The
  renewal lock only kept renewals apart: a reconnect (any `Store*` action) that
  wrote the credential while its renewal waited on the provider was
  overwritten when the renewal succeeded, bringing back the old grant's tokens
  under the new grant's scopes (a TikTok reconnect that added `video.publish`
  was lost, and publishing failed on a scope `hasScope()` reported as granted).
  When the provider refused the old refresh token instead, the credential that
  had just been reconnected was flagged `needs_reconnect` and
  `CredentialNeedsReconnect` fired. The reconnect now wins either way
  ([UPGRADE](UPGRADE.md#renewals-transactions-and-the-cache-store)):
  - `applyRenewal()` writes only while the credential still holds the access
    and refresh tokens the instance was loaded with (compared in a
    transaction, under a row lock; the same tokens re-encrypted under a new
    `APP_KEY` still count), and not over a revoke, as before. Otherwise it
    writes nothing, fires no `CredentialRenewed`, and reloads the instance
    with the stored row (`tryApplyRenewal()` says which, see Added). A
    credential deleted meanwhile throws `ModelNotFoundException`; 2.x saved
    silently. Load the credential right before refreshing it.
  - After a terminal renewal failure, `RenewCredential` and
    `validAccessTokenFor()` flag the credential only while it still holds the
    refused token (`markNeedsReconnectOnce($reason, $token)`). When a reconnect
    replaced it meanwhile, nothing is flagged and `validAccessTokenFor()`
    hands out the reconnected token, or throws a transient
    `NeedsReconnectException` (retry) instead of a non-transient one.
    `renewCredential()` reloads the `$token` it is given under the lock, so
    `$token->access_token` is that token; flag it the same way if you call
    `renewCredential()` yourself, with
    `$token->markNeedsReconnectIfStillHeld($result->reason)` (see Added).
- **(breaking)** **A renewal requested inside a database transaction no longer
  spends a rotated refresh token.** The re-read under the renewal lock was a
  plain read. On MySQL (REPEATABLE READ), a caller inside a transaction read
  its snapshot, which still held the TikTok or X refresh token another process
  had just rotated; on any database, a renewal stored inside a transaction not
  yet committed was missed. The caller refreshed with the spent token, got
  `invalid_grant`, and flagged a working credential. `renewCredential()` now
  re-reads the credential with `SELECT ... FOR UPDATE` (SQLite has no row
  locks and reads as before), so it sees a renewal committed after the
  caller's transaction began and waits for one not committed yet. It waits as
  long as the database lets it: up to `innodb_lock_wait_timeout` on MySQL (50
  seconds by default); on PostgreSQL up to 30 seconds outside your transaction
  (the read sets `lock_timeout` for its own short transaction unless you set
  one), and inside it until the transaction holding the row ends, unless
  `lock_timeout` is set. A wait of more than 30 seconds is a transient failure
  ("Could not lock the credential for renewal."), since the 60-second renewal
  lock could lapse during the provider call. Outside your transaction, a lock
  wait timeout or a deadlock on that read (PostgreSQL's `55P03` lock timeout
  included) is a transient failure too. Inside it, that error is thrown
  instead (its `QueryException`, or a `DeadlockException` from a nested
  `DB::transaction()`), from `validAccessTokenFor()` as well: the database may
  have rolled your transaction back, and a transient result would let you
  commit the rest of it alone. There the row also stays locked until your
  transaction ends. Renewing a TikTok or X credential inside a database
  transaction logs a warning (`Renewing a single-use refresh token inside a
  database transaction`), since a rollback discards a refresh token the
  provider has already replaced
  ([UPGRADE](UPGRADE.md#renewals-transactions-and-the-cache-store)).
- **(breaking)** `revoke()` and `disconnect()` record the revocation even when
  the provider-side call fails. They called the provider first, unguarded, and
  the TikTok, Google and X connectors decrypted the token outside their `try`:
  a credential that no longer decrypted (an `APP_KEY` change without
  `APP_PREVIOUS_KEYS`), or a custom connector that threw, made `revoke()` throw
  `DecryptException` with the credential and every account still active, and
  `disconnect()` throw with the credential still active. The credential and
  its accounts are now revoked first, firing `CredentialRevoked` and
  `AccountRevoked`; the provider call follows, on the credential as it was
  before those events (a listener that wipes the token no longer affects it).
  A provider revocation that throws is no longer rethrown: it is logged as a
  warning (`[social-tokens] {provider} token revocation failed; the credential
  is revoked locally`, with `provider`, `token_id`, `exception` and a redacted
  `message`); the Google, TikTok and X connectors catch their own failures
  and log them the same way (`[social-tokens] {provider} token revocation
  failed`, `logRevokeFailure()`). A revocation listener that throws (a mail
  server down, a queued listener whose push fails) no longer skips the
  provider call either, which a retried `disconnect()` would never have made
  since the row was already revoked: `revoke()` and `disconnect()` make it,
  then rethrow the listener's exception
  ([UPGRADE](UPGRADE.md#revocation-is-recorded-before-the-provider-call),
  [If you wrote a connector](UPGRADE.md#if-you-wrote-a-connector)).
- **(breaking)** **A Meta user token that never expires is no longer extended
  every week.** Some Facebook Login configurations issue user tokens that
  `debug_token` reports with `expires_at: 0`. `fb_exchange_token` returns them
  without `expires_in`, so the credential behind the user's Instagram accounts
  was stored with an unknown expiry and checked back after one lead time,
  forever: a pointless `fb_exchange_token` call and a "Renewal returned no
  expiry" warning every seven days. The package already called `debug_token`
  at every Facebook and Instagram connection, for the scopes, and threw its
  lifetime away. It now reads it there and after each renewal: a token that
  never expires is stored static (`renew_at` null, no more weekly
  `CredentialRenewed`), and an expiry the exchange left out is taken from
  `debug_token`. When the exchange reports an expiry, that one wins.
  Credentials stored by 2.x become static at their next renewal, within a week
  of the upgrade. Only `social-tokens:check-static` watches such a credential
  from then on: an app that set `check_static_schedule` to `null` must
  schedule the command itself
  ([UPGRADE](UPGRADE.md#meta-data-access-and-tokens-that-never-expire)).
- **(breaking)** `StoreConnection::handle('facebook' | 'instagram', $user,
  longLived: false)` still called `fb_exchange_token`, and threw "Could not
  extend the Facebook user token" when Meta refused it: 2.x ignored
  `longLived` on those two providers and always extended. It now passes it to
  the fan-outs (their `extend`), skipping the exchange and storing the user
  token as given. Pass it only with a token that already is long-lived: Page
  tokens minted from a short-lived user token die with it, within hours,
  although they are stored as never expiring
  ([UPGRADE](UPGRADE.md#store-connections-through-the-connector)).
- **(breaking)** Boolean settings holding a string are read as booleans:
  `'log_unknown_errors' => 'false'` in a published config, or
  `SOCIAL_TOKENS_CHECK_RENEWABLE=off` (which `env()` returns as the string
  `'off'`), used to count as true. `'false'`, `'no'`, `'off'` and `'0'` are now
  false, in `log_unknown_errors`, `check_renewable` and the LinkedIn
  connector's `refresh_enabled`: `LINKEDIN_REFRESH_ENABLED=off` renewed every
  member credential holding a refresh token through LinkedIn's token
  endpoint, which flagged them days early once the app had lost its refresh
  tokens ([UPGRADE](UPGRADE.md#check-your-published-config)).
- **(breaking)** A connector `driver` naming a class that does not exist (a
  renamed custom connector) or does not implement `ProviderConnector` passed
  every "no connector" guard, which then threw `InvalidArgumentException`:
  `disconnect()` / `revoke()` failed after revoking the account,
  `validAccessTokenFor()` threw it instead of the documented transient
  `NeedsReconnectException`, and the dispatcher kept queueing jobs that failed.
  `ConnectorRegistry::has()` and `configured()` now mean "resolvable", so such
  a provider is handled like `driver => null`, with one error log per process.
  Connecting still throws
  ([UPGRADE](UPGRADE.md#check-your-published-config)).
- **(breaking)** An invalid `dispatch_schedule` or `check_static_schedule` (a
  cron expression, a misspelt method, a method that needs an argument such as
  `dailyAt`) threw while the host resolved its Schedule. Apps whose
  `routes/console.php` or `withSchedule()` schedule their own tasks resolve it
  in every artisan process, so `queue:work`, `migrate` and the whole scheduler
  died with it. Both settings now accept a cron expression as well as a
  frequency method without arguments; anything else, a scheduler macro
  included, logs an error and keeps the default schedule. A cron alias
  (`'@hourly'`) is stored as its five fields, which `schedule:list` needs. A
  method that is not a frequency, such as `runInBackground`, or that only
  picks the days, such as `weekdays` or `mondays`, no longer schedules the
  command every minute ([UPGRADE](UPGRADE.md#check-your-published-config)).
- **(breaking)** `SocialAccount::with('connectedBy')` loaded the relation under
  another name, `connected_by`, so reading `$account->connectedBy` queried
  again for every row, the model `associate()` had just set was not reused
  either, and a queued event's restore skipped it the same way. The relation
  is now named `connectedBy`, after its method. Code reading the loaded
  relation by its old name (`getRelation('connected_by')`,
  `relationLoaded('connected_by')`, `setRelation('connected_by', ...)`) must
  use `connectedBy`. So must code reading the snake-case property:
  `$account->connected_by`, which returned the model after `associate()` or
  `with('connectedBy')`, now returns null (or throws
  `MissingAttributeException` under
  `Model::preventAccessingMissingAttributes()`); read `$account->connectedBy`.
  `toArray()` still shows it as `connected_by`
  ([UPGRADE](UPGRADE.md#queued-listeners-re-read-the-models)).
- A credential whose tokens no longer decrypt (written under an `APP_KEY` that
  is gone from `APP_PREVIOUS_KEYS`) no longer gets stuck. Reconnecting it, the
  way out `social-tokens:reencrypt` and the README give, threw
  `DecryptException` and changed nothing: the connect paths read the stored
  tokens before writing over them (the refresh token a reconnect without one
  keeps, the member token a LinkedIn re-sync compares, and Eloquent's dirty
  check when `APP_PREVIOUS_KEYS` is empty). They now write the new tokens over
  it, and forget a refresh token nobody can read, with its expiry. Each of its
  renewals threw too, was reported, and was rescheduled 15 minutes later, for
  good, while the credential stayed `active`; `validAccessTokenFor()` and
  `reportRejected()` threw `DecryptException`. Now `renewCredential()` returns
  a Transient result with no provider call, `validAccessTokenFor()` throws a
  transient `NeedsReconnectException`, `reportRejected()` returns false, and a
  `Log::critical` (at most every 15 minutes) alerts the operator, all with the
  reason `SocialToken::UNDECRYPTABLE`. Nothing flags such a credential on its
  own: it is usually a process with the wrong `APP_KEY`, which must not get
  every user it touches asked to reconnect. For keys that are truly lost,
  `social-tokens:reencrypt --flag-undecryptable` flags them `needs_reconnect`.
- Migration `2025_01_01_000007_scope_identity_to_owner` (the 1.x upgrade):
  - It can be run again after failing partway. On MySQL and SQLite a failure (a
    lost connection, a timeout, a rejected key) left the owner columns added and
    the old unique keys dropped, and every later `migrate` stopped on a
    duplicate `ownable_type` column. Every step is now guarded, and a
    credential is split in one transaction or not at all.
  - **(breaking)** It no longer runs as a single transaction on PostgreSQL.
    That held an ACCESS EXCLUSIVE lock on `social_tokens`, blocking every token
    read of the running app, until the last credential was backfilled. Schema
    steps now commit one by one, and the backfill commits every 100
    credentials. A failure partway no longer rolls everything back: run
    `php artisan migrate` again, and it resumes where it stopped.
  - It checks before changing anything, and refuses with instructions: on
    MySQL/MariaDB, that the new unique keys fit InnoDB's 3072-byte limit; and
    that the credentials' owner column can hold every owner id of the accounts,
    so the backfill cannot fail halfway on a type mismatch. These checks read
    real columns and rows, so `migrate --pretend` skips them: it lists the
    statements, as 2.0.0 did, for a schema the package created, with the owner
    columns in the app's default morph key type.
  - It finishes a run of 2.0.0's copy that failed on MySQL/MariaDB with owners
    declared per column as uuid or ulid (`nullableUuidMorphs('ownable')` in a
    published accounts migration) and morph keys left to integers. 2.0.0 gave
    `social_tokens.ownable_id` the app's default type (bigint), its backfill
    stopped on the first owned credential with "Data truncated", and every
    later `migrate` failed the same way. Owner columns an earlier run left
    empty, with another type than the accounts', are now made again. Columns
    that already hold owners are kept when every owner id fits them; otherwise
    the migration refuses, changing nothing, and names the `ALTER TABLE` to run.
  - The credentials' owner column takes the key type of the accounts' own
    column: a uuid column (native, or `char(36)`) gives uuid, a `char(26)`
    column ulid, whatever the app's default morph key type. Owners declared
    per column (`nullableUuidMorphs('ownable')` in a published copy of the
    accounts migration) therefore work, and an app whose default changed since
    its accounts table was created no longer gets a column too short for its
    ids. Only another string column falls back to the default morph key type
    (any string type on SQLite, which enforces none).
  - A credential without a `provider_holder_id` that no account posts with
    stays owner-less. It used to take the owner of every holder-less account of
    its provider, plus a copy per extra owner. The package always sets a
    holder: only rows an app wrote itself were affected.
  - **(breaking)** Its unique keys are named the way Laravel names its own
    indexes: with the table prefix when `prefix_indexes` is on
    (`acme_social_accounts_owner_identity_unique`, not
    `social_accounts_owner_identity_unique`; two prefixed installs sharing a
    PostgreSQL schema or an SQLite file collided on the same name), and
    without the dot of a schema-qualified table name (invalid SQL on
    PostgreSQL and MySQL). Only 1.x installs migrating with 3.0 get the new
    names; keys 2.0.0 created keep theirs, and `down()` finds either by its
    columns.
- A published config with a single `queue.backoff` value (`'backoff' => 60`,
  a form every Laravel job accepts) made each `social-tokens:dispatch-renewals`
  run throw a `TypeError` on the first due credential, so no renewal was ever
  queued again. The backoff now takes one value, a list, or a comma-separated
  string.
- `social-tokens:check-static` crashed with `DivisionByZeroError` when
  `check_static_breaker.min_checked` was 0 (or negative) and the run checked
  no credential. A minimum below 1 now counts as 1.
- On a `sync` queue connection, one failed renewal ended the dispatcher run:
  the job runs inline and its exception escaped `social-tokens:dispatch-renewals`,
  so no credential after it was renewed in that run, and a credential failing
  for good blocked the others on most ticks. The dispatcher now walks every
  due credential: a transient failure is counted in its output, and any other
  exception is reported and makes the run exit with a failure code once all
  credentials were dispatched. On a real queue, a job the queue rejects (its
  backend or the cache lock store down) still ends the run, after a single
  report, since every credential would fail the same way.
- `social-tokens:check-static` applies its safety breaker to each provider as
  well as to the whole run. It divided one provider's rejections by every
  checked credential, so a provider-wide rejection on a smaller provider (every
  LinkedIn member token answering `active: false` after a LinkedIn app switch,
  among thousands of healthy Facebook Pages) stayed under 20% and flagged all of
  that provider's credentials, one `CredentialNeedsReconnect` each. That
  provider's credentials are now left alone, with no strike recorded, and a
  critical log names it (`check-static skipped {provider}`); the other
  providers' verdicts still apply, and the command exits with a failure code.
- `social-tokens:check-static` only checks credentials an active account still
  uses. A Facebook Page the user no longer manages has its account flagged at
  the next connect, but its page token stays active and Meta then rejects it:
  those rejections counted towards the breaker every day, so a few lost Pages
  could keep it tripped and genuinely dead Pages were never flagged; otherwise
  the lost Page got a redundant `CredentialNeedsReconnect`.
- A new token no longer inherits the previous token's `check-static` strike.
  Reconnecting a Threads or LinkedIn account (`StoreConnection`,
  `StoreAccountFromSocialite`, `StoreLinkedInOrganizations`) and every
  successful renewal kept `failed_checks`, so after one unconfirmed rejection
  of the old token, a single unconfirmed rejection of the new one flagged it
  `needs_reconnect` at once. Every write of a freshly issued token now goes
  through one place, which also resets the status, `last_error` and
  `last_renewed_at`.
- A Facebook Page credential records when its token was issued
  (`last_renewed_at`), as every other credential has since 1.2.
- LinkedIn token introspection (`check-static` with `check_renewable`,
  `reportRejected(terminal: false)`) flags a credential only when LinkedIn says
  the token was `revoked`, or `expired` when refresh tokens are off. LinkedIn
  also answers `{"active": false}` for a valid token issued to another LinkedIn
  app than the configured client (after switching apps, or with a separate
  Community Management app), and every LinkedIn credential was flagged on the
  first run ("LinkedIn token inactive."), then again after each reconnect. A
  bare `active: false`, or a token the answer says belongs to another client,
  is now an uncatalogued check error, logged with a hint and never flagged.
  With refresh tokens on, an expired access token is left to the renewal.
- Meta errors about the app's own credentials are app-client failures, as
  `invalid_client` has been on LinkedIn, TikTok and Google since 1.2: "Error
  validating client secret." (code 1), a wrong or missing app id (code 101),
  and an `appsecret_proof` Meta refuses (code 100), plus an OAuth-style
  `invalid_client` string. A wrong or rotated Meta app secret (a typo, or
  workers that kept the old one) was retried as an ordinary transient error,
  with no critical log and no 15-minute pause, until each Meta user token
  reached its expiry and was flagged; `check-static` logged every Page as an
  uncatalogued error. Now the critical log fires (`facebook rejected the app's
  OAuth client`), renewals pause, and `check-static` and
  `reportRejected(terminal: false)` alert instead of logging per credential.
  A plain code 1 ("An unknown error has occurred.") is still a transient
  error.
- LinkedIn scopes stored through `StoreConnection` or
  `StoreAccountFromSocialite` are one scope per element. LinkedIn lists them
  comma-separated and Socialite's LinkedIn drivers split on spaces, so the
  account and its credential held a single joined element
  (`['email,openid,profile,w_member_social']`): `hasScope('w_member_social')`
  was false and `missingScopes()` reported every scope.
  `SocialToken::normaliseScopes()` now splits each element on commas and
  whitespace, as renewals already did. Rows stored before keep the joined
  element until the member reconnects
  ([UPGRADE](UPGRADE.md#linkedin-scopes-are-split) has a one-off fix).
- With `refresh_enabled` on, a LinkedIn credential stored without a refresh
  token (from an app without programmatic refresh tokens, a manual exchange
  that left it out, a row from before the option was turned on) is no longer
  flagged five days before its token expires. The renewal job sent it to the
  refresh call, got "No refresh token" and flagged it at once, with no
  `CredentialExpiringSoon`: the early cut-off 1.1 removed for LinkedIn without
  refresh tokens. The strategy is now decided per credential
  (`renewalStrategyFor()`, see Added): such a credential is warned when its
  window opens, keeps posting, and is flagged once it has expired, by the job,
  `validAccessTokenFor()` and a renewal that ran out of retries alike. Its
  unknown expiry no longer schedules a check that would flag it, and LinkedIn
  introspection answering `expired` for it is a definitive verdict.
- Re-syncing LinkedIn organizations with the member token already stored no
  longer erases or moves that token's expiry. `StoreLinkedInOrganizations`
  wrote `expires_at`, `renew_at` and `last_renewed_at` from the call: without
  `expiresAt`, a credential without refresh tokens turned static, was never
  warned, and `validAccessTokenFor()` handed out its token after it died; with
  an invented one, the warning came after the real expiry. With the stored
  token, the issue date is kept, and so are the expiry and the schedule when
  no `expiresAt` is given, or the stored one (a warning already sent is not
  sent again).
- Re-syncing Facebook Pages or Instagram accounts with the Meta user token
  already stored (`StoreFacebookPages` / `StoreInstagramAccounts` with
  `extend: false`) no longer moves that token's renewal back, nor erases its
  expiry. The schedule was recomputed from the call: after a renewal Meta
  did not extend, which parks `renew_at` on the expiry and fires
  `CredentialExpiringSoon`, the re-sync put `renew_at` a week before the
  expiry, in the past, so the next dispatcher run made another
  `fb_exchange_token` call for nothing and warned the user again; when
  `debug_token` did not answer, the expiry became unknown. With the stored
  token, the issue date is kept, and so are the expiry and the schedule
  unless `debug_token` reports another expiry (an unscheduled credential is
  scheduled again).
- An OAuth error sent as an object (`{"error": {"code", "message"}}`) crashed
  the TikTok, Google and LinkedIn connectors with "Array to string conversion";
  it is now an unknown, retried failure.
- TikTok `unauthorized_client` is an app-client failure, as the 1.2 changelog
  already stated (it was retried as an unknown error).
- Google `admin_policy_enforced` (a Workspace admin blocked the app for the
  user) is terminal instead of being retried forever.
- The `log_unknown_errors` comment in the published config said uncatalogued
  errors are logged with the "raw payload". Since 1.2 the logged context is
  redacted (error codes, HTTP status, the response's keys) and never holds the
  response body, which may carry tokens; the comment now says so.
- UPGRADE.md's "From 1.x to 2.0", where the README sends every 1.x user,
  skipped 1.2: its breaking changes (`StoreConnection` refusing a provider
  without a connector, `markNeedsReconnect()` / `markRevoked()` no longer
  saving, `toArray()` hiding the tokens, `renewCredential()` refusing an
  unsaved token) and its one-off steps (rotating the Meta and Threads
  secrets 1.1 leaked into logs, scheduling static Meta user credentials,
  repairing flagged rows) were only in the changelog, which the package did
  not ship. A 1.1 app following the guide kept static Meta credentials that
  died about 60 days later. The guide now has a "Coming from 1.0 or 1.1"
  section, with redaction and reactivation snippets of its own, run once 3.0
  is deployed (1.1 has no `redactQueryStrings()`). Its snippets page with
  `eachById()`, not `each()`, which pages by offset and, past the first
  thousand rows, skips rows a page's own changes took out of the query. The
  scopes clean-up in the 1.2 changelog turned Meta's known-empty `[]` into
  `null` (unknown), against its own advice; the guide's leaves `[]` alone,
  and a row the old one changed gets its list back at the next reconnect.
- UPGRADE and README: attaching an owner-less row with
  `$account->ownable()->associate($owner)`, as 2.0's notes advised, saved
  nothing until `save()`, then moved the account without its credential: the
  owner's next reconnect stored a second credential and left the first one
  behind, a Facebook Page's Meta user token stopped being renewed, and an
  account the owner had already reconnected hit the owner-scoped unique key.
  The notes now point at `adopt()` (see Added).
- README: the OAuth routes used the connector key from the URL as the
  Socialite driver name, which broke two keys. `/oauth/instagram/redirect`
  threw "Driver [instagram] not supported" (Instagram through a Page logs in
  with the `facebook` driver), and `linkedin` resolved Socialite's legacy
  driver, which always asks for `r_liteprofile` and `r_emailaddress`, refused
  to LinkedIn apps created since 2023. The routes now map those keys to their
  driver (`linkedin-openid` for the profile, reading
  `services.linkedin-openid`), hand `StoreConnection` the connector key, and
  send each key back to its own callback, so that a Facebook login for
  Instagram is not stored as a Facebook one. The LinkedIn organizations
  paragraph no longer contradicts the profile's flow.
- README: the Google redirect now asks for offline access
  (`access_type=offline`, `prompt=consent`); without it Google issues no refresh
  token and every Google credential was flagged within the hour.
- README and the `reportRejected()` docblock: `terminal: true` is for an error
  that proves the credential dead (a Meta session subcode; LinkedIn
  `REVOKED_ACCESS_TOKEN` / `EXPIRED_ACCESS_TOKEN` without refresh tokens). They
  said any Meta 190/102 and any OAuth `invalid_grant`/`invalid_token`. On
  Google, TikTok, X or LinkedIn with refresh tokens, a 401 `invalid_token`
  usually means the access token expired while the refresh token still works,
  so following the README flagged a healthy credential; apps should call
  `validAccessTokenFor()` again instead. They also now say that
  `terminal: false` does nothing for a connector that cannot check a token
  (Google, TikTok, X), and that LinkedIn with refresh tokens should report a
  `REVOKED_ACCESS_TOKEN` with `terminal: false`, which introspects the token
  ([UPGRADE](UPGRADE.md#review-your-reportrejected-calls)).
- README: the renewal lock's cache store must be shared by every server that
  serves web requests, runs queue workers or runs the scheduler. The README
  listed `file` as suitable; a file lock only holds on one host, so a worker
  server and a web server could both refresh the same TikTok or X token. The
  `array` store only locks within one process: it is for tests.

### Removed
- **(breaking)** The one-argument `mapError(array $body)` of `GoogleConnector`
  and `TikTokConnector` (protected). The error mapping moved to
  `OAuth2Connector::mapError(array $body, int $status)`, which asks the
  provider's `classifyError($error, $description, $status)` what an error
  code means. A subclass that overrides the old one-argument method no longer
  loads (PHP refuses the incompatible signature): override `classifyError()`
  instead ([UPGRADE](UPGRADE.md#if-you-wrote-a-connector)).

## [2.0.0]

Identity is scoped to the owner. **Breaking:** see [UPGRADE.md](UPGRADE.md).

### Changed (breaking)
- An account is unique per `(provider, provider_user_id, owner)` and a
  credential per `(provider, provider_holder_id, owner)`. When a second owner
  connected a TikTok account, a Facebook Page, an Instagram account or a
  LinkedIn organization a first owner already had, 1.x moved the single row (and
  overwrote its credential with the second owner's token) without any event. Now
  each owner keeps its own row, backed by its own credential; reconnecting for
  the same owner, or without an owner, still updates the row in place.
- A connection with an owner no longer adopts an existing owner-less row; it
  creates its own.
- Lookups by external id must be scoped to the owner (`ownedBy()`).

### Added
- Migration `2025_01_01_000007_scope_identity_to_owner`: adds the owner to
  `social_tokens`, backfills it from the accounts (splitting a credential shared
  by several owners), and swaps the unique indexes. Its `down()` refuses once two
  owners share an external account.
- `SocialAccount::ownedBy()` / `SocialToken::ownedBy()` scopes,
  `SocialAccount::ownerKey()`, and the `SocialToken::ownable()` relation.

### Changed
- `revoke()` / `disconnect()` skip the provider-side revocation while another
  owner still holds an active credential for the same external user (a Google or
  TikTok revoke can end that user's whole consent for the app).
- The renewal dispatcher ties a Meta user credential to the Pages of its own
  owner only.

## [1.2.0]

A minor release with breaking changes: see Upgrading below, and
[UPGRADE.md, Coming from 1.0 or 1.1](UPGRADE.md#coming-from-10-or-11).

### Security
- **Secrets no longer leak through error messages.** Guzzle ends transport
  errors with the full request URI, and Meta calls authenticate through the
  query string, so a connection failure put `client_secret`, the
  `{id}|{secret}` app token, user tokens and Threads tokens in the renewal
  reason — and from there in the job exception (laravel.log, `failed_jobs`,
  error trackers), the `Store*` exceptions, `NeedsReconnectException`,
  `social_tokens.last_error` and `CredentialNeedsReconnect`. Query strings are
  now redacted (`https://host/path?[redacted]`) at the single point those
  reasons are built, and "malformed response" contexts list the body's keys
  instead of the body.
- `SocialToken` hides `access_token` and `refresh_token` from serialisation, so
  `toArray()` / `toJson()` — including `SocialAccount::with('credential')` —
  no longer return them decrypted. They stay readable as attributes.

### Fixed
- **A provider outage no longer disconnects a refresh-token credential.**
  `RenewCredential::failed()` flagged `needs_reconnect` any credential whose
  access token had expired by the time the queue gave up — including Google,
  TikTok and LinkedIn-with-refresh credentials whose refresh token still worked.
  It now flags only a credential that truly can no longer be renewed (an expired
  long-lived Meta/Threads token, an expired `ReauthOnly` token, or an expired
  refresh token). Otherwise the credential stays `active`, `renew_at` is backed
  off (15 min once the token has expired, otherwise a quarter of the time left,
  5–60 min) so the dispatcher stops re-dispatching it on every tick, and
  `last_error` records the outage.
- The job's uniqueness lock now covers every configured retry (it was a fixed
  10 minutes, shorter than the default backoff), so a dispatcher run can no
  longer stack a second job for a credential that is still retrying.
- A renewal job whose credential was deleted before it ran is dropped quietly
  instead of landing in `failed_jobs`.
- **A misconfigured app no longer disconnects a whole network.**
  `invalid_client` / `unauthorized_client` (LinkedIn, TikTok, Google) and
  `invalid_request` (LinkedIn, TikTok — unless it is about the refresh token)
  describe the app's own OAuth client, not the member's grant, yet flagged every
  due credential of the provider `needs_reconnect` — and reconnecting could not
  help, the OAuth flow uses the same broken client. They are now retried, never
  escalated (not even by `failed()`), and logged once per provider every 15
  minutes via `Log::critical`, whatever `log_unknown_errors` says. While the
  client is rejected, renewal jobs for that provider skip the provider call for
  15 minutes. `invalid_grant`, `refresh_token_client_mismatch` and an
  `invalid_request` about the refresh token stay terminal.
- `StoreLinkedInOrganizations` no longer flags the member's personal LinkedIn row
  (the row whose `provider_user_id` is the member id) during reconciliation,
  including when the member administers no organizations. That row shares the
  member's `provider_holder_id`, so it was flagged "Organization no longer
  administered" and could no longer post.
- **An incomplete or malformed listing no longer flags accounts.** Reconciliation
  flags every account missing from the provider's listing, and a listing page
  that was not a 2xx carrying the list (an HTML 4xx from a proxy or WAF, `{}`, an
  empty 404, a 200 without `data` / `elements`) was read as "end of the list".
  `fetchPages()` and `fetchOrganizations()` now return a failure instead, so the
  `Store*` actions throw their documented `RuntimeException` before reconciling.
  LinkedIn also pages on `paging.links rel=next` / `paging.count` instead of
  assuming a short page is the last one, resolves an organization from its URN
  when LinkedIn throttles its decoration (keeping the stored name and logo), and
  refuses to reconcile while an approved grant cannot be resolved.
- **`social-tokens:check-static` no longer flags healthy Pages.** A verdict
  cuts a Page off and a flagged static token is never checked again, yet a
  permission code (10, 200–299) on `GET /me`, a single unconfirmed 190, or a
  Meta-side incident answering 190 for everyone flagged Pages on the spot; one
  credential that threw (e.g. undecryptable after an `APP_KEY` rotation) stopped
  the whole run; and a Page reconnected while its check was in flight had its
  fresh token flagged. Now: session subcodes flag at once, a bare 190/102 needs
  two consecutive runs, permission codes are only logged, a breaker
  (`check_static_breaker`: more than 20% of at least 10 checked) flags nothing
  and raises `Log::critical`, a credential that throws is logged and skipped,
  and a credential whose token changed during the check is left alone.
- **A renewal that does not extend the token no longer re-runs on every tick.**
  When a provider returned a success whose expiry was still inside the lead time
  (e.g. Meta answering with the remaining lifetime), `renew_at` landed in the
  past and the dispatcher called the provider every 15 minutes until expiry.
  `renew_at` now moves to the expiry, a warning logs the `expires_in` received,
  and for long-lived tokens (Meta, Threads) `CredentialExpiringSoon` fires once.
  Each successful long-lived extension also logs its `expires_in` (info), to
  confirm in production whether Meta really extends.
- **Status changes are one-way and idempotent.** `renewCredential()` re-reads
  the credential under its lock and never renews one that is no longer active
  (a revoked credential used to be sent to the provider, and a stale copy could
  resurrect it); `applyRenewal()` never writes over a revoke that landed during
  the provider call; `markNeedsReconnect()` / `markRevoked()` (on credentials
  and accounts) are compare-and-set, fire their event only on an actual change,
  keep the first `last_error` (the root cause), and never downgrade `revoked`;
  `validAccessTokenFor()` re-reads the account and its credential, so it no
  longer hands out the cached token of a credential disabled after the caller
  loaded it. This makes the README's "`CredentialNeedsReconnect` fires once"
  true under concurrency.
- `dispatch_schedule => null` (or `false`) crashed the host app's scheduler
  (`schedule:run`, `schedule:list` — including the app's own tasks); it now
  disables the dispatcher schedule, like `check_static_schedule => null`.
- Both scheduled commands now use `onOneServer()`, and `check-static` runs in the
  background so its provider calls do not delay the app's tasks due the same
  minute. Their overlap mutexes expire after 10 and 120 minutes (Laravel's
  default is 24 hours: a killed process stopped every renewal for a day).
- **A Facebook connection keeps the Meta user token.** `StoreFacebookPages` (and
  `StoreConnection('facebook')`) extended the user token, minted the page tokens
  from it and threw it away, so a Facebook reconnect never refreshed the shared
  credential the user's Instagram accounts post with. It is now stored as the
  renewable Meta user credential (`provider = facebook`, holder = Facebook user
  id), the same row `StoreInstagramAccounts` uses, only when the user manages at
  least one Page. Pages keep posting with their static page tokens.
- The renewal dispatcher also renews a credential that its accounts reference by
  `(provider, provider_holder_id)` rather than `social_token_id` — the Meta user
  token behind a user's Pages kept dying after ~60 days once the Instagram
  accounts were removed. It still skips credentials no active account depends
  on, and now walks them by id.
- **LinkedIn organizations are filtered by role.** Every approved
  `organizationAcls` grant was stored as a postable organization, including
  ANALYST, CURATOR or LEAD_GEN_FORMS_MANAGER grants that cannot post, and an
  organization held through two roles came back twice (duplicate collection
  entries, two `AccountConnected`, and `profile.role` set to whichever role came
  last). Only posting roles are kept now, one entry per organization, and an
  organization the member was demoted on is flagged at the next connect.
- **Renewal is scheduled from the moment of connection.** A renewable token
  stored without a known expiry (Meta's `fb_exchange_token` without
  `expires_in`, `StoreInstagramAccounts(extend: false)`) got `renew_at = null` —
  it looked static and was never renewed, dying silently. Every connect action
  now uses `SocialToken::renewAtFor()`: an unknown expiry is checked back after
  one lead time (a `ReauthOnly` credential stays unscheduled).
- The dispatcher skips (with a warning) credentials of providers that have no
  connector instead of dispatching jobs that crash, and no longer renews a
  credential whose accounts are all revoked or flagged. A renewal job for a
  provider without a connector ends quietly instead of flagging the credential.
- Google's lead time was 10 minutes, shorter than the 15-minute dispatcher
  cadence, so Google tokens routinely expired before their renewal; it is now
  25 minutes.
- **Users are warned before a refresh token dies, not after.** LinkedIn
  refresh tokens live 365 days and are never extended, but nothing looked at
  `refresh_expires_at` until it had passed: the warning came at best a lead
  time before the access token died, and with an access token capped at the
  refresh token's expiry there was none at all — the job refreshed on every
  pass, then flagged the credential. `renew_at` now accounts for the refresh
  token's expiry, and `CredentialExpiringSoon` fires once, a lead time before
  it, with the date the credential really stops working.
- `StoreAccountFromSocialite` and `StoreLinkedInOrganizations` no longer replace
  a known refresh token or its expiry with null (storing the LinkedIn personal
  profile, or re-syncing organizations, without one wiped it from the shared
  credential), and `StoreAccountFromSocialite` also reads LinkedIn's
  `refresh_token_expires_in` (from a driver that exposes the token response,
  such as SocialiteProviders'; core Socialite's LinkedIn drivers do not, see
  3.0).
- `MetaErrorMapper::map()` accepts any value: a response whose `error` is a
  string (OAuth 2 style) used to throw a `TypeError` out of every Meta call; it
  is now an unknown, retried and logged failure. Terminal and transient Meta
  results now carry `code`, `error_subcode`, `type`, `message` and
  `fbtrace_id` in their context, and the reason names the subcode
  (`190/460 OAuthException: …`), which reaches `last_error` and
  `CredentialNeedsReconnect`. A scalar JSON body no longer breaks any connector.
- **Stored data stays clean.** A failed `debug_token` (5xx, top-level error, no
  `data`) wiped every Page's and Instagram account's scopes to `[]`; a provider
  that reported no scopes stored `[]` or `[""]`, indistinguishable from "none
  granted"; LinkedIn organizations never got scopes; a reconnect replaced the
  account's whole `profile`, dropping keys the app stored there; and a Facebook
  Page listed without an `access_token` was stored as an active account that
  could not post. Now `null` means unknown and never overwrites a known list,
  lists are normalised, `profile` is merged, and Pages without a token are not
  stored (they still count as managed, so they are not flagged either).
- Threads renewals are refused (transient, no call to Meta) while the token is
  less than 24 hours old, which `th_refresh_token` rejects. The token's age comes
  from `last_renewed_at`, now also set at connection and reconnection; rows
  where it is unknown are renewed as before.
- README: `StoreConnection` stores the LinkedIn personal profile only (it never
  fans out to organizations), and the personal row does carry a
  `provider_holder_id`.

### Added
- `RenewalStrategy::requiresLiveAccessToken()`.
- `RenewalResult::clientFailure()` and `RenewalResult::$clientError`.
- `RenewalResult::terminalFailure()` and `transientFailure()` take an optional
  `$context`, which does not mark the result unknown.
- `MetaErrorMapper::mapCredentialCheck()`, the stricter mapping used by
  credential health checks.
- Migration `2025_01_01_000006`: `social_tokens.failed_checks`.
- Config `check_static_breaker`.
- `SocialToken::inUse()` scope and `SocialToken::renewAtFor()`.
- `SocialTokens::disconnect(SocialAccount)`: remove one account without
  touching the accounts that share its credential; the credential is revoked
  (at the provider too) only when its last connected account goes.
- `SocialTokens::reportRejected($account, $reason, terminal: true, rejectedToken: null)`:
  report a token the provider rejected at publish time. Flags the credential
  once (returns whether this call did), never touches a revoked credential or
  one that no longer holds `$rejectedToken`, and with `terminal: false` asks the
  provider first. A renewable credential used to stay "usable" until its renewal
  came due (~53 days for Meta and Threads) even after the user revoked it.
- `SocialToken::markNeedsReconnectOnce($reason, $checkedToken)`.
- `SocialToken::isRefreshTokenExpiring($lead)`.
- `SocialToken::normaliseScopes()`, `SocialToken::scopesKnown()`,
  `SocialAccount::scopesKnown()`, `SocialAccount::mergeProfile()`.
- `StoreLinkedInOrganizations::handle(..., ?array $scopes = null)` (last
  parameter): store the member's granted scopes on the credential and the
  organizations.
- `validAccessTokenFor($account, int $minValiditySeconds = 30)` and
  `renewCredential($token, int $minValiditySeconds = 30)`: a job that holds the
  token for minutes (Instagram/Threads container polling, video uploads) can ask
  for one that stays valid that long; a token expiring sooner is renewed first.
  The 30-second margin stays a floor, and the default behaviour is unchanged.
  A still-valid token that cannot be renewed unattended is handed out instead of
  being flagged when such a minimum is asked.
- Config `check_renewable` (`SOCIAL_TOKENS_CHECK_RENEWABLE`, off by default):
  `check-static` also checks renewable credentials between renewals.
  `ThreadsConnector` (`/me`) and `LinkedInConnector` (token introspection) now
  implement `ChecksCredential`.
- Config `connectors.linkedin.posting_roles` (defaults to
  `LinkedInConnector::DEFAULT_POSTING_ROLES`; an empty or missing list falls
  back to the defaults, so a published v1.1 config keeps working).

### Changed
- `renewCredential()` throws `InvalidArgumentException` for a `SocialToken` that
  was never saved (it used to insert it into `social_tokens`). To refresh a
  token held elsewhere, call `connector($provider)->refreshCredential()`.
- `revoke()` no longer throws for a provider without a connector (it skips the
  provider call and still revokes locally).
- Dependencies: the package now requires `laravel/framework` (its events and
  job use `Illuminate\Foundation` traits, which no `illuminate/*` package
  ships) instead of five `illuminate/*` packages; no change in a Laravel app.
  Laravel 13 is tested in CI (with Testbench 11 and Pest 4, PHP 8.3+).
- Docs: `StoreConnection`'s LinkedIn routing, the personal LinkedIn row's
  `provider_holder_id`, the `LinkedInConnector` scopes note, the unused
  `InstagramConnector::refreshCredential()` and a dead "Upgrading to 1.0" link
  are corrected; a "Using a connector on its own" section is added.

### Upgrading
- **Run `php artisan migrate`** (new column `social_tokens.failed_checks`). If
  you published the package migrations, publish or copy
  `2025_01_01_000006_add_failed_checks_to_social_tokens_table.php` too.
- **Rotate your Meta and Threads app secrets** if logs, `failed_jobs` or an
  error tracker may hold `client_secret=` from a connection failure. Existing
  `last_error` values written by earlier versions may also contain secrets:
  clear or redact them (e.g. with `AbstractConnector::redactQueryStrings()`).
- **Breaking:** `StoreConnection` and `StoreAccountFromSocialite` throw
  `InvalidArgumentException` for a provider without a connector (missing key or
  `driver => null`); they used to store the account as static, and it broke at
  expiry. Configure a connector (e.g. `'youtube' => ['driver' =>
  GoogleConnector::class]`, reading `services.youtube`) or stop routing that
  provider (e.g. X/Twitter) through the package.
- **If you followed the README and call `revoke($account->credential)` to remove
  one account, switch to `disconnect($account)`**: `revoke()` revokes every
  Instagram account of the same Facebook user, and every LinkedIn organization
  plus the personal profile.
- `SocialToken::toArray()` / `toJson()` no longer include `access_token` /
  `refresh_token`. Read them as attributes (`$token->access_token`) or call
  `makeVisible([...])`.
- Meta user credentials created by earlier versions with neither `expires_at`
  nor `renew_at` stay static. Schedule them once:
  ```php
  SocialToken::where('provider', 'facebook')->whereNull('expires_at')->whereNull('renew_at')
      ->whereHas('accounts', fn ($q) => $q->where('provider', 'instagram'))
      ->update(['renew_at' => now()]);
  ```
  (Page tokens only back `facebook` accounts, so this leaves them alone.)
- `CredentialNeedsReconnect` is no longer fired after a provider outage on a
  refresh-token credential. If you relied on it to detect outages, watch
  `last_error` / `last_renewed_at` instead. A non-null `last_error` on an
  `active` credential now means "recent failures, still retrying": the status,
  not `last_error`, says whether a credential is broken.
- If you relied on `CredentialNeedsReconnect` to detect a broken app config,
  alert on the critical log instead. Credentials that 1.1.0 already flagged for
  these errors stay flagged; once the config is fixed you can reactivate those
  with `status = needs_reconnect` whose `last_error` starts with
  `invalid_client:`, `unauthorized_client:` or `invalid_request:` and does not
  mention the refresh token (a truly dead refresh token will be flagged again,
  properly, by `invalid_grant`).
- `CredentialNeedsReconnect`, `CredentialRevoked`, `AccountNeedsReconnect` and
  `AccountRevoked` fire only on a real state change; listeners that counted on
  repeats will not get them. `markNeedsReconnect()` / `markRevoked()` no longer
  `save()` the model: other unsaved changes are not persisted with them, save
  those yourself. `markNeedsReconnect()` on a revoked credential or account is
  a no-op. `renewCredential()` on a credential that is not active returns
  `terminalFailure('Credential is …')` without calling the provider.
  `validAccessTokenFor()` does one or two more queries per call, and throws
  `NeedsReconnectException` for an account deleted since it was loaded.
- `check-static` runs in the background: its "Checked …" line no longer shows
  in `schedule:run` output.
- Facebook connections now store the Meta user credential and the dispatcher
  renews it (one `fb_exchange_token` about every 53 days per user): expect
  `Credential*` events for a credential whose `accounts` is empty. Existing
  Facebook-only connections get it at their next reconnect (the token was never
  stored, so no migration can recreate it).
- LinkedIn organizations the member holds only through a non-posting role move
  to `needs_reconnect` (with `AccountNeedsReconnect`) at the member's next
  connect. To keep a different role list, set `posting_roles` in your published
  config.
- Meta reasons (`$result->reason`, `last_error`, `CredentialNeedsReconnect::$reason`)
  read `190/460 OAuthException: …` when Meta sends a subcode (unchanged without
  one). Code that parses them should read `$result->context['code']` /
  `['error_subcode']` instead. To tell an uncatalogued error, test
  `$result->unknown`, not a non-empty `context`.
- `$account->scopes` / `$credential->scopes` can be `null` where they used to be
  `[]` or `[""]`; `grantedScopes()` and `hasScope()` answer as before. Use
  `scopesKnown()` to tell "unknown" from "none granted". Pages without an
  `access_token` are no longer returned or announced by `AccountConnected`.
  `profile` is merged, not replaced. To clean old rows:
  `SocialAccount::whereNotNull('scopes')->each(fn ($a) => $a->forceFill(['scopes' => SocialToken::normaliseScopes($a->scopes)])->saveQuietly())`
  (same for `SocialToken`; leave `[]` from Meta alone, it may be a real "none").
- `social_tokens.last_renewed_at` is now the issue date of the current token: it
  is set at connection and reconnection too, not only by a renewal. If you read
  `last_renewed_at !== null` as "renewed at least once", adapt it.
- If you extend `SocialTokens` and override `validAccessTokenFor()` or
  `renewCredential()`, add the new optional `int $minValiditySeconds = 30`
  parameter to your signature.
- `CredentialExpiringSoon` can now arrive **before** a refresh token expires
  (reason "Refresh token expires soon…", `expiresAt` = the date the credential
  stops working), and `renew_at` can be earlier than before. The connect actions
  no longer erase `refresh_token` / `refresh_expires_at`; to forget a refresh
  token, revoke or reconnect. Existing credentials only get the early warning
  after their next renewal; to schedule it now, run once:
  ```php
  $registry = app(\Pr4w\SocialTokens\Support\ConnectorRegistry::class);
  SocialToken::where('status', 'active')->whereNotNull('refresh_expires_at')->whereNotNull('renew_at')
      ->each(function (SocialToken $token) use ($registry) {
          if ($registry->has($token->provider)) {
              $warnAt = $token->refresh_expires_at->copy()->sub($registry->for($token->provider)->leadTime());
              $token->forceFill(['renew_at' => $token->renew_at->min($warnAt)])->save();
          }
      });
  ```
- For Meta/Threads, `CredentialExpiringSoon` can now also follow a
  "successful" renewal that did not extend the token: the user must reconnect.
- `check-static`: a bare Meta 190/102 is now flagged a day later (second
  consecutive run); permission codes no longer flag. A custom `ChecksCredential`
  connector that returns a plain `terminalFailure()` now needs two runs; return
  `terminalFailure($reason, ['definitive' => true])` to flag on the first.
  An aborted run exits with a failure code.
- Facebook Pages each have their own credential, so `check-static` fires one
  `CredentialNeedsReconnect` per dead Page: group notifications yourself.
- Personal LinkedIn rows wrongly flagged by earlier versions can be repaired by
  reconnecting the profile, or with (check the linked credential is active first):
  ```sql
  UPDATE social_accounts SET status = 'active', last_error = NULL
  WHERE provider = 'linkedin' AND provider_user_id = provider_holder_id
    AND status = 'needs_reconnect'
    AND last_error = 'Organization no longer administered by the connected LinkedIn member.';
  ```

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

Upgrading: run `php artisan migrate` — the backfill migration groups existing
accounts into credentials before the old token columns are dropped.

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
