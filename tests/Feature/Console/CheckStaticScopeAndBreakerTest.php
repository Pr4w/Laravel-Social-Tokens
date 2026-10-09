<?php

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);
    config()->set('logging.default', 'null');
});

/** A Facebook Page credential (static) backing one Page account in the given status. */
function scopeBreakerPage(string $pageToken, AccountStatus $accountStatus = AccountStatus::Active): SocialToken
{
    $pageId = 'page-'.uniqid('', true);

    $token = SocialToken::create([
        'provider' => 'facebook',
        'provider_holder_id' => $pageId,
        'access_token' => $pageToken,
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'facebook',
        'provider_user_id' => $pageId,
        'provider_holder_id' => 'fb-user-1',
        'social_token_id' => $token->getKey(),
        'status' => $accountStatus,
    ]);

    return $token;
}

/** A renewable credential between two renewals, backing one active account. */
function scopeBreakerRenewable(string $provider, string $accessToken): SocialToken
{
    $token = SocialToken::create([
        'provider' => $provider,
        'provider_holder_id' => 'holder-'.uniqid('', true),
        'access_token' => $accessToken,
        'expires_at' => now()->addDays(60),
        'renew_at' => now()->addDays(53),
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => $provider,
        'provider_user_id' => 'acct-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/**
 * Graph, Threads and LinkedIn answer per token: a token listed in $dead is
 * rejected definitively (a session subcode, or introspection "revoked"); any
 * other token works.
 *
 * @param  array<int, string>  $dead
 */
function scopeBreakerFakeProviders(array $dead): void
{
    Http::fake(function (Request $request) use ($dead) {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);

        if ($host === 'www.linkedin.com') {
            $token = $request->data()['token'] ?? '';

            return Http::response(in_array($token, $dead, true)
                ? ['active' => false, 'status' => 'revoked']
                : ['active' => true]);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $token = preg_replace('/^Bearer\s+/', '', $request->header('Authorization')[0] ?? '') ?: ($query['access_token'] ?? '');

        if (in_array($token, $dead, true)) {
            return Http::response(['error' => [
                'message' => 'Error validating access token: The session has been invalidated because the user changed their password.',
                'type' => 'OAuthException',
                'code' => 190,
                'error_subcode' => 460,
            ]], 400);
        }

        return Http::response(['id' => 'id-for-'.$token]);
    });
}

/** @return ArrayObject<int, MessageLogged> */
function scopeBreakerListenToLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $log) => $logs->append($log));

    return $logs;
}

/** @param  ArrayObject<int, MessageLogged>  $logs */
function scopeBreakerCriticals(ArrayObject $logs): array
{
    return array_values(array_filter(
        $logs->getArrayCopy(),
        fn (MessageLogged $log) => in_array($log->level, ['critical', 'alert', 'emergency'], true),
    ));
}

// Which credentials are checked ------------------------------------------------

it('does not check a credential whose accounts are all flagged or revoked', function () {
    scopeBreakerFakeProviders([]);

    scopeBreakerPage('unmanaged-page-token', AccountStatus::NeedsReconnect);
    scopeBreakerPage('disconnected-page-token', AccountStatus::Revoked);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 0 static credential(s), flagged 0.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('still checks a credential that one active account uses', function () {
    scopeBreakerFakeProviders([]);

    $token = scopeBreakerPage('shared-page-token', AccountStatus::NeedsReconnect);
    SocialAccount::create([
        'provider' => 'facebook',
        'provider_user_id' => 'another-'.uniqid('', true),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 1 static credential(s), flagged 0.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer shared-page-token'));
});

it('lets Pages the user no longer manages neither trip the breaker nor be flagged', function () {
    // Reconciliation flagged three Pages the user lost; their page tokens stay
    // active and Meta rejects them now. One Page still in use really died.
    $unmanaged = collect(range(1, 3))->map(fn (int $i) => scopeBreakerPage("unmanaged-{$i}", AccountStatus::NeedsReconnect));
    $dead = scopeBreakerPage('dead-in-use');
    collect(range(1, 10))->each(fn (int $i) => scopeBreakerPage("healthy-{$i}"));

    scopeBreakerFakeProviders(['unmanaged-1', 'unmanaged-2', 'unmanaged-3', 'dead-in-use']);

    foreach ([1, 2] as $run) {
        Artisan::call('social-tokens:check-static');
    }

    expect($dead->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($unmanaged->every(fn (SocialToken $token) => $token->fresh()->status === AccountStatus::Active))->toBeTrue();

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($dead));
});

// The breaker, per provider ----------------------------------------------------

it('stops a minority provider incident from flagging that whole provider', function () {
    config(['social-tokens.check_renewable' => true]);
    $logs = scopeBreakerListenToLogs();

    // LinkedIn rejects every member token at once (an incident on its side, or
    // the app switched LinkedIn apps), among a large healthy Facebook fleet.
    $pages = collect(range(1, 100))->map(fn (int $i) => scopeBreakerPage("page-{$i}"));
    $members = collect(range(1, 10))->map(fn (int $i) => scopeBreakerRenewable('linkedin', "member-{$i}"));

    scopeBreakerFakeProviders($members->map(fn (SocialToken $token) => $token->access_token)->all());

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('linkedin')
        ->assertFailed();

    $flagged = $members->filter(fn (SocialToken $token) => $token->fresh()->status !== AccountStatus::Active);

    expect($flagged)->toHaveCount(0, "check-static flagged {$flagged->count()} of 10 LinkedIn credentials in one provider-wide rejection.")
        ->and($pages->every(fn (SocialToken $token) => $token->fresh()->status === AccountStatus::Active))->toBeTrue();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    $alerts = scopeBreakerCriticals($logs);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->context['provider'] ?? null)->toBe('linkedin')
        ->and($alerts[0]->context['checked'] ?? null)->toBe(10)
        ->and($alerts[0]->context['terminal'] ?? null)->toBe(10);
});

it('still flags the other providers when one provider trips its breaker', function () {
    config(['social-tokens.check_renewable' => true]);

    $deadPage = scopeBreakerPage('dead-page');
    collect(range(1, 99))->each(fn (int $i) => scopeBreakerPage("page-{$i}"));
    $members = collect(range(1, 10))->map(fn (int $i) => scopeBreakerRenewable('linkedin', "member-{$i}"));

    scopeBreakerFakeProviders(['dead-page', ...$members->map(fn (SocialToken $token) => $token->access_token)->all()]);

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 100 static credential(s), flagged 1.')
        ->expectsOutputToContain('Checked 10 renewable credential(s), flagged 0.')
        ->assertFailed();

    expect($deadPage->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($members->every(fn (SocialToken $token) => $token->fresh()->status === AccountStatus::Active))->toBeTrue();

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($deadPage));
});

it('records no strike for the provider whose breaker tripped', function () {
    config(['social-tokens.check_renewable' => true]);

    collect(range(1, 100))->each(fn (int $i) => scopeBreakerPage("page-{$i}"));
    $members = collect(range(1, 10))->map(fn (int $i) => scopeBreakerRenewable('threads', "threads-{$i}"));

    // A bare 190 (not definitive) on every Threads token.
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graph.threads.net')) {
            return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400);
        }

        return Http::response(['id' => 'ok']);
    });

    Artisan::call('social-tokens:check-static');
    Artisan::call('social-tokens:check-static');

    expect($members->every(fn (SocialToken $token) => $token->fresh()->failed_checks === 0
        && $token->fresh()->status === AccountStatus::Active))->toBeTrue();

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('keeps the run-wide breaker for providers too small to trip their own', function () {
    config(['social-tokens.check_renewable' => true]);
    $logs = scopeBreakerListenToLogs();

    // Each provider is under min_checked (10), so none trips on its own; together
    // 11 of 15 checked credentials are rejected at once.
    $threads = collect(range(1, 5))->map(fn (int $i) => scopeBreakerRenewable('threads', "threads-{$i}"));
    $members = collect(range(1, 6))->map(fn (int $i) => scopeBreakerRenewable('linkedin', "member-{$i}"));
    collect(range(1, 4))->each(fn (int $i) => scopeBreakerPage("page-{$i}"));

    scopeBreakerFakeProviders([
        ...$threads->map(fn (SocialToken $token) => $token->access_token)->all(),
        ...$members->map(fn (SocialToken $token) => $token->access_token)->all(),
    ]);

    $this->artisan('social-tokens:check-static')->assertFailed();

    expect(SocialToken::query()->where('status', '!=', AccountStatus::Active->value)->count())->toBe(0);
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    expect(scopeBreakerCriticals($logs))->not->toBeEmpty();
});
