# Changelog

All notable changes to `pr4w/laravel-social-tokens`. This project adheres to
[Semantic Versioning](https://semver.org).

## [Unreleased]

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
  `refresh_token_expires_in`.
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
