<?php

/*
 * Regression: a renewable credential the provider has revoked must not stay
 * Active until its renew_at.
 *
 * In v1.1.0 only static credentials (expires_at and renew_at both null) are
 * ever asked whether they still work (social-tokens:check-static). A renewable
 * credential is re-validated only when its renewal job runs at renew_at: about
 * 53 days after connect for the shared Meta user credential behind Instagram
 * accounts and for Threads, about 55 days for LinkedIn. Until then
 * SocialTokens::validAccessTokenFor() keeps handing out the dead token and
 * every account it backs reports itself usable. The package also offers no way
 * for the app to report a rejection it saw at publish time:
 * SocialToken::markNeedsReconnect() is public but fires its event on every call.
 *
 * Proposed API, exercised below:
 *
 * - SocialTokens::reportRejected(SocialAccount $account, string $reason,
 *       bool $terminal = true, ?string $rejectedToken = null): bool
 *   Terminal: flag the account's credential NeedsReconnect, once (conditional
 *   update, one CredentialNeedsReconnect event, first reason kept). Non-terminal:
 *   ask the provider when the connector implements ChecksCredential, and flag
 *   only if it confirms. Never touches a Revoked credential, and ignores a
 *   rejection of a token the credential no longer holds. Returns true only when
 *   this call flagged the credential.
 *
 * - config('social-tokens.check_renewable') (bool, default false): when true,
 *   social-tokens:check-static also checks Active renewable credentials whose
 *   renewal is not yet due and whose connector implements ChecksCredential.
 *   ThreadsConnector and LinkedInConnector implement ChecksCredential.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

/**
 * A renewable credential (60-day token, renewal due in 53 days by default)
 * backing $accounts active accounts.
 */
function livenessReportCredential(string $provider, array $attrs = [], int $accounts = 1, ?string $accountProvider = null): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => 'live-token',
        'refresh_token' => null,
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(53),
        'status' => AccountStatus::Active,
    ], $attrs));

    for ($i = 0; $i < $accounts; $i++) {
        SocialAccount::create([
            'provider' => $accountProvider ?? $provider,
            'provider_user_id' => 'acct-'.uniqid('', true),
            'social_token_id' => $token->getKey(),
            'status' => AccountStatus::Active,
        ]);
    }

    return $token;
}

function livenessReportTokens(): SocialTokens
{
    return app(SocialTokens::class);
}

/**
 * The request asked $host about $token, whichever way the connector passes it:
 * as a bearer token, in the query string (Threads, debug_token) or in a form
 * body (LinkedIn token introspection). The endpoint itself is left to the fix.
 */
function livenessReportAsksAbout(Request $request, string $host, string $token): bool
{
    return str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), $host)
        && ($request->hasHeader('Authorization', 'Bearer '.$token)
            || str_contains(urldecode($request->url()), $token)
            || str_contains(urldecode($request->body()), $token));
}

/** Every Graph call answers the way Meta does once the user changed their password. */
function livenessReportMetaRejects(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Error validating access token: The session has been invalidated because the user changed their password.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 460,
    ]], 400)]);
}

// reportRejected() ------------------------------------------------------------

it('flags the shared credential once when a publish is rejected with a dead token', function () {
    Event::fake([CredentialNeedsReconnect::class, AccountNeedsReconnect::class]);
    Http::fake();

    // The shared Meta user credential behind three Instagram accounts.
    $credential = livenessReportCredential('facebook', ['access_token' => 'meta-user-token'], accounts: 3, accountProvider: 'instagram');
    [$rejected, $sibling] = $credential->accounts()->get()->all();
    $reason = '190 OAuthException: Error validating access token: the user changed their password.';

    $flagged = livenessReportTokens()->reportRejected($rejected, $reason, terminal: true);

    expect($flagged)->toBeTrue()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->fresh()->last_error)->toBe($reason);

    // Every account the credential backs stops posting; the flag lives on the
    // credential, the account rows are not rewritten.
    $credential->accounts()->get()->each(function (SocialAccount $account) {
        expect($account->isUsable())->toBeFalse()
            ->and($account->effectiveStatus())->toBe(AccountStatus::NeedsReconnect)
            ->and($account->status)->toBe(AccountStatus::Active);
    });

    expect(fn () => livenessReportTokens()->validAccessTokenFor($sibling->fresh()))
        ->toThrow(function (NeedsReconnectException $e) {
            expect($e->transient)->toBeFalse();
        });

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($credential) && $event->reason === $reason);
    Event::assertNotDispatched(AccountNeedsReconnect::class);

    // A terminal report is trusted as is: no call to the provider.
    Http::assertNothingSent();
});

it('reports a rejection only once even when each worker holds its own copy of the credential', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake();

    $credential = livenessReportCredential('facebook', accounts: 3, accountProvider: 'instagram');

    // Three publish jobs, each loaded its account and credential before any of them failed.
    $accounts = SocialAccount::with('credential')->where('social_token_id', $credential->getKey())->get();

    $results = $accounts->map(fn (SocialAccount $account, int $i) => livenessReportTokens()->reportRejected($account, "rejection #{$i}", terminal: true));

    expect($results->all())->toBe([true, false, false])
        ->and(livenessReportTokens()->reportRejected($accounts->first(), 'retried job', terminal: true))->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->fresh()->last_error)->toBe('rejection #0');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('never downgrades a revoked credential when a rejection is reported', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake();

    $credential = livenessReportCredential('facebook', ['status' => AccountStatus::Revoked], accounts: 1, accountProvider: 'instagram');
    $account = $credential->accounts()->first();

    expect(livenessReportTokens()->reportRejected($account, '190 OAuthException: token revoked', terminal: true))->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($credential->fresh()->last_error)->toBeNull();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('ignores a rejection of a token the credential has already replaced', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake();

    // The user reconnected while a long publish (an Instagram video) was still
    // running with the previous token; that publish then fails with 190.
    $credential = livenessReportCredential('facebook', ['access_token' => 'token-after-reconnect'], accounts: 1, accountProvider: 'instagram');
    $account = $credential->accounts()->first();
    $reason = '190 OAuthException: session invalidated';

    $flagged = livenessReportTokens()->reportRejected($account, $reason, terminal: true, rejectedToken: 'token-before-reconnect');

    expect($flagged)->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->fresh()->isUsable())->toBeTrue();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    // The same report about the token the credential still holds does flag it.
    expect(livenessReportTokens()->reportRejected($account->fresh(), $reason, terminal: true, rejectedToken: 'token-after-reconnect'))->toBeTrue()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('keeps the credential usable when the provider does not confirm a non-terminal rejection', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'meta-user-1'])]);

    $credential = livenessReportCredential('facebook', ['access_token' => 'meta-user-token'], accounts: 1, accountProvider: 'instagram');
    $account = $credential->accounts()->first();

    $flagged = livenessReportTokens()->reportRejected($account, 'HTTP 401 from the media endpoint', terminal: false);

    expect($flagged)->toBeFalse()
        ->and($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and(livenessReportTokens()->validAccessTokenFor($account->fresh()))->toBe('meta-user-token');

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('flags the credential when the provider confirms a non-terminal rejection', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    livenessReportMetaRejects();

    $credential = livenessReportCredential('facebook', ['access_token' => 'meta-user-token'], accounts: 1, accountProvider: 'instagram');
    $account = $credential->accounts()->first();

    $flagged = livenessReportTokens()->reportRejected($account, 'HTTP 401 from the media endpoint', terminal: false);

    expect($flagged)->toBeTrue()
        ->and($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->fresh()->last_error)->toContain('changed their password');

    // Verified with the provider (the connector's ChecksCredential), using the credential's own token.
    Http::assertSent(fn (Request $request) => livenessReportAsksAbout($request, 'graph.facebook.com', 'meta-user-token'));

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

// Liveness check for renewable credentials ------------------------------------

it('flags a revoked Meta user credential before its renew_at when renewable checks are enabled', function () {
    config(['social-tokens.check_renewable' => true]);
    Event::fake([CredentialNeedsReconnect::class]);
    livenessReportMetaRejects();

    $credential = livenessReportCredential('facebook', ['access_token' => 'dead-user-token'], accounts: 3, accountProvider: 'instagram');

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->fresh()->last_error)->toContain('changed their password');

    $credential->accounts()->get()->each(fn (SocialAccount $account) => expect($account->isUsable())->toBeFalse());

    expect(fn () => livenessReportTokens()->validAccessTokenFor($credential->accounts()->first()))
        ->toThrow(NeedsReconnectException::class);

    Http::assertSent(fn (Request $request) => livenessReportAsksAbout($request, 'graph.facebook.com', 'dead-user-token'));
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('leaves a live renewable credential untouched by the liveness check', function () {
    config(['social-tokens.check_renewable' => true]);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'meta-user-1'])]);

    $credential = livenessReportCredential('facebook', ['access_token' => 'live-user-token'], accounts: 1, accountProvider: 'instagram');
    $before = $credential->fresh();

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    $after = $credential->fresh();

    // A check only asks; it never renews, re-dates or rewrites the credential.
    expect($after->status)->toBe(AccountStatus::Active)
        ->and($after->access_token)->toBe('live-user-token')
        ->and($after->expires_at->equalTo($before->expires_at))->toBeTrue()
        ->and($after->renew_at->equalTo($before->renew_at))->toBeTrue()
        ->and($after->last_renewed_at)->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth/access_token'));

    // It was asked about, with its own token.
    Http::assertSent(fn (Request $request) => livenessReportAsksAbout($request, 'graph.facebook.com', 'live-user-token'));
});

it('flags a revoked Threads credential during the renewable liveness check', function () {
    config(['social-tokens.check_renewable' => true]);
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake(['graph.threads.net/*' => Http::response(['error' => [
        'message' => 'Error validating access token: The user has not authorized application.',
        'type' => 'OAuthException',
        'code' => 190,
        'error_subcode' => 458,
    ]], 400)]);

    $credential = livenessReportCredential('threads', ['access_token' => 'dead-threads-token']);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($credential->accounts()->first()->isUsable())->toBeFalse();

    Http::assertSent(fn (Request $request) => livenessReportAsksAbout($request, 'graph.threads.net', 'dead-threads-token'));
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('flags a revoked LinkedIn member credential during the renewable liveness check', function () {
    config(['social-tokens.check_renewable' => true]);
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake([
        // Token introspection answers for a revoked token ...
        'www.linkedin.com/*' => Http::response(['active' => false, 'status' => 'revoked']),
        // ... and so does any authenticated API call.
        'api.linkedin.com/*' => Http::response([
            'status' => 401,
            'serviceErrorCode' => 65601,
            'code' => 'REVOKED_ACCESS_TOKEN',
            'message' => 'The token used in the request has been revoked by the user',
        ], 401),
    ]);

    // Default LinkedIn setup (no refresh token): renewal only warns 5 days before expiry.
    $credential = livenessReportCredential('linkedin', [
        'access_token' => 'dead-linkedin-token',
        'renew_at' => now()->addDays(55),
    ], accounts: 2);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($credential->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    $credential->accounts()->get()->each(fn (SocialAccount $account) => expect($account->isUsable())->toBeFalse());

    // Introspection (token in the form body) or an authenticated call (bearer): either way, its own token.
    Http::assertSent(fn (Request $request) => livenessReportAsksAbout($request, 'linkedin.com', 'dead-linkedin-token'));
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('does not check renewable credentials when renewable checks are disabled', function () {
    config(['social-tokens.check_renewable' => false]);
    livenessReportMetaRejects();

    $credential = livenessReportCredential('facebook', accounts: 1, accountProvider: 'instagram');

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);

    Http::assertNothingSent();
});

it('leaves a renewable credential whose renewal is already due to the renewal job', function () {
    config(['social-tokens.check_renewable' => true]);
    livenessReportMetaRejects();

    // Expired access token, window long open: the renewal job owns it. Checking
    // it would flag an expiry rather than a revocation (and, for refresh-token
    // providers, an expired access token says nothing about the refresh token).
    $credential = livenessReportCredential('facebook', [
        'expires_at' => now()->subHour(),
        'renew_at' => now()->subDays(7)->subHour(),
    ], accounts: 1, accountProvider: 'instagram');

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($credential->fresh()->status)->toBe(AccountStatus::Active);

    Http::assertNothingSent();
});
