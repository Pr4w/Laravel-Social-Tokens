<?php

/*
 * Regression: social-tokens:check-static must be safe to run every day against
 * every Facebook Page token, because its verdict cuts the Page off (the
 * credential becomes needs_reconnect, validAccessTokenFor throws, the app emails
 * the user) and a flagged static token is never checked again: only a manual
 * reconnect brings it back.
 *
 * In v1.1.0 the command:
 *  - flags a Page on the first Terminal verdict, including Meta's permission
 *    codes 10 and 200-299, which on GET /me?fields=id do not prove the token is
 *    dead;
 *  - flags on a single bare 190 with no confirmation, and has no breaker: a
 *    Meta-side incident that answers 190 for every Page flags every Page of
 *    every user in one run;
 *  - stops entirely on the first credential that throws (a DecryptException
 *    after an APP_KEY rotation, a corrupted row), so every later credential goes
 *    unchecked, every day, in the same lazyById order;
 *  - saves its verdict on the model it loaded before the HTTP call, so a Page
 *    reconnected while /me was in flight gets its fresh token flagged.
 *
 * Desired:
 *  - codes 10 and 200-299 never flag in the health check (logged as unknown);
 *  - a bare 190/102 flags only when two consecutive runs agree (the session
 *    subcodes 458-492 are explicit and may still flag on the first run);
 *  - the run flags nothing, and raises a critical log, when a large share of the
 *    checked tokens come back terminal at once;
 *  - one credential that throws is logged and skipped, the others are checked;
 *  - a credential whose access_token changed during the check is not flagged.
 *
 * The schedule itself (runInBackground + onOneServer) is covered by
 * SchedulerRegistrationTest.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);

    // Keep log calls out of the filesystem; tests read them via MessageLogged.
    config()->set('logging.default', 'null');
});

/**
 * A Facebook Page credential stored the way StoreFacebookPages stores it: a
 * static token (no expiry, no renewal) keyed on the Page id, backing one Page
 * account whose holder is the Facebook user.
 */
function staticCheckSafetyPage(string $pageToken): SocialToken
{
    $pageId = 'page-'.uniqid('', true);

    $token = SocialToken::create([
        'provider' => 'facebook',
        'provider_holder_id' => $pageId,
        'access_token' => $pageToken,
        'refresh_token' => null,
        'expires_at' => null,
        'renew_at' => null,
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'facebook',
        'provider_user_id' => $pageId,
        'provider_holder_id' => 'fb-user-1',
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

/** A Meta Graph error object, as returned under "error". */
function staticCheckSafetyMetaError(int $code, ?int $subcode = null, string $message = 'Invalid OAuth access token.'): array
{
    return array_filter([
        'message' => $message,
        'type' => 'OAuthException',
        'code' => $code,
        'error_subcode' => $subcode,
        'fbtrace_id' => 'trace-'.$code,
    ], fn ($value) => $value !== null);
}

/**
 * Fake the Graph API. Each request is answered according to the bearer token it
 * carries: $answers maps a page token to a Meta error array (or to a Closure
 * returning one), and any token not in $answers works. $answers is held by
 * reference so a test can change what Meta says between two runs.
 *
 * @param  array<string, array<string, mixed>|Closure>  $answers
 */
function staticCheckSafetyFakeGraph(array &$answers): void
{
    Http::fake(function (Request $request) use (&$answers) {
        $bearer = preg_replace('/^Bearer\s+/', '', $request->header('Authorization')[0] ?? '');
        $answer = $answers[$bearer] ?? null;

        if ($answer instanceof Closure) {
            $answer = $answer();
        }

        if ($answer === null) {
            return Http::response(['id' => 'id-for-'.$bearer]);
        }

        return Http::response(['error' => $answer], $answer['code'] === 190 || $answer['code'] === 102 ? 400 : 403);
    });
}

function staticCheckSafetyRun(): void
{
    Artisan::call('social-tokens:check-static');
}

/**
 * Collect every log entry written from now on.
 *
 * @return ArrayObject<int, MessageLogged>
 */
function staticCheckSafetyListenToLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $log) use ($logs) {
        $logs->append($log);
    });

    return $logs;
}

/**
 * @param  ArrayObject<int, MessageLogged>  $logs
 * @param  array<int, string>  $levels
 */
function staticCheckSafetyLogsAbout(ArrayObject $logs, SocialToken $token, array $levels): array
{
    return array_values(array_filter(
        $logs->getArrayCopy(),
        fn (MessageLogged $log) => in_array($log->level, $levels, true)
            && (string) ($log->context['token_id'] ?? '') === (string) $token->getKey(),
    ));
}

dataset('permission errors the health check must not act on', [
    'code 10 (permission denied)' => [10],
    'code 200 (permissions error)' => [200],
    'code 299 (end of the 2xx permission range)' => [299],
]);

it('never flags a Page token for a permission error, even on consecutive runs', function (int $code) {
    $logs = staticCheckSafetyListenToLogs();
    $answers = ['page-token' => staticCheckSafetyMetaError($code, message: "(#{$code}) Requires pages_manage_posts permission")];
    staticCheckSafetyFakeGraph($answers);
    $token = staticCheckSafetyPage('page-token');

    staticCheckSafetyRun();
    staticCheckSafetyRun();

    expect($token->fresh()->status)->toBe(AccountStatus::Active, "A code {$code} answer to GET /me flagged the Page.");

    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    // Not acted on, but not swallowed either: an uncatalogued answer is logged.
    expect(staticCheckSafetyLogsAbout($logs, $token, ['warning', 'error', 'critical', 'alert', 'emergency']))
        ->not->toBeEmpty("A code {$code} answer to the health check was neither acted on nor logged.");
})->with('permission errors the health check must not act on');

it('does not flag a Page token on a single bare 190, and flags it when the next run confirms it', function () {
    $answers = ['dead-token' => staticCheckSafetyMetaError(190)];
    staticCheckSafetyFakeGraph($answers);

    // One dead Page among healthy ones, so no mass-failure breaker is involved.
    $dead = staticCheckSafetyPage('dead-token');
    $healthy = collect(range(1, 19))->map(fn (int $i) => staticCheckSafetyPage("healthy-token-{$i}"));

    staticCheckSafetyRun();

    expect($dead->fresh()->status)->toBe(AccountStatus::Active, 'A single bare 190 flagged the Page on the first run.');
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    staticCheckSafetyRun();

    expect($dead->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($dead->fresh()->last_error)->toContain('Invalid OAuth access token')
        ->and($healthy->every(fn (SocialToken $token) => $token->fresh()->status === AccountStatus::Active))->toBeTrue();

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($dead));
});

it('starts the strike count over when a Page token works again between two failures', function () {
    $answers = [];
    staticCheckSafetyFakeGraph($answers);

    $flaky = staticCheckSafetyPage('flaky-token');
    collect(range(1, 19))->each(fn (int $i) => staticCheckSafetyPage("healthy-token-{$i}"));

    $answers['flaky-token'] = staticCheckSafetyMetaError(190);
    staticCheckSafetyRun();

    unset($answers['flaky-token']); // Meta accepts it again.
    staticCheckSafetyRun();

    $answers['flaky-token'] = staticCheckSafetyMetaError(190);
    staticCheckSafetyRun();

    // Two failures, but not consecutive: the credential is not flagged.
    expect($flaky->fresh()->status)->toBe(AccountStatus::Active, 'Two non-consecutive 190s flagged the Page.');
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('flags nothing and raises a critical log when most checked Page tokens fail at once', function () {
    $logs = staticCheckSafetyListenToLogs();

    // A Meta-side incident: every Page token answers "password changed", a
    // session subcode that would otherwise flag on the first run.
    $answers = [];
    $tokens = collect(range(1, 20))->map(function (int $i) use (&$answers) {
        $answers["page-token-{$i}"] = staticCheckSafetyMetaError(190, 460, 'The session has been invalidated because the user changed their password.');

        return staticCheckSafetyPage("page-token-{$i}");
    });
    staticCheckSafetyFakeGraph($answers);

    staticCheckSafetyRun();

    $flagged = $tokens->filter(fn (SocialToken $token) => $token->fresh()->status !== AccountStatus::Active);

    expect($flagged)->toHaveCount(0, "check-static flagged {$flagged->count()} of 20 Pages in a single mass-failure run.");
    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    $alerts = array_filter(
        $logs->getArrayCopy(),
        fn (MessageLogged $log) => in_array($log->level, ['critical', 'alert', 'emergency'], true),
    );

    expect($alerts)->not->toBeEmpty('The aborted run did not raise a critical log for the operator.');
});

it('keeps checking the other Page tokens when one cannot be decrypted', function () {
    $logs = staticCheckSafetyListenToLogs();
    $answers = ['dead-token' => staticCheckSafetyMetaError(190, 460, 'The session has been invalidated because the user changed their password.')];
    staticCheckSafetyFakeGraph($answers);

    // Created first, so lazyById reaches it before the others.
    $corrupt = staticCheckSafetyPage('corrupt-token');
    DB::table($corrupt->getTable())->where($corrupt->getKeyName(), $corrupt->getKey())->update(['access_token' => 'not-an-encrypted-payload']);

    $dead = staticCheckSafetyPage('dead-token');

    $thrown = null;

    try {
        staticCheckSafetyRun();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull('check-static aborted on one credential: '.($thrown ? $thrown::class.' '.$thrown->getMessage() : ''));

    // The credential after the broken one was still checked and flagged.
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer dead-token'));
    expect($dead->fresh()->status)->toBe(AccountStatus::NeedsReconnect);

    // The broken one is reported, not flagged: after an APP_KEY rotation,
    // restoring the key fixes it, a reconnect email to every user would not.
    expect(DB::table($corrupt->getTable())->where($corrupt->getKeyName(), $corrupt->getKey())->value('status'))->toBe(AccountStatus::Active->value)
        ->and(staticCheckSafetyLogsAbout($logs, $corrupt, ['error', 'critical', 'alert', 'emergency']))
        ->not->toBeEmpty('The credential that could not be checked was not logged.');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($dead));
});

it('does not flag a Page token that was reconnected while its check was in flight', function () {
    $token = staticCheckSafetyPage('old-page-token');

    // Control: another dead Page, not reconnected. It must be flagged by this
    // same run, so the test cannot pass merely because a first run flags nothing.
    $control = staticCheckSafetyPage('other-dead-token');

    $invalidated = staticCheckSafetyMetaError(190, 460, 'The session has been invalidated because the user changed their password.');

    $answers = [
        'old-page-token' => function () use ($token, $invalidated) {
            // The user reconnects while /me is in flight: StoreFacebookPages
            // writes the new page token, status active, on the same row.
            SocialToken::query()->whereKey($token->getKey())->firstOrFail()->forceFill([
                'access_token' => 'new-page-token',
                'status' => AccountStatus::Active,
                'last_error' => null,
            ])->save();

            return $invalidated;
        },
        'other-dead-token' => $invalidated,
    ];
    staticCheckSafetyFakeGraph($answers);

    staticCheckSafetyRun();

    $fresh = $token->fresh();

    expect($fresh->access_token)->toBe('new-page-token')
        ->and($fresh->status)->toBe(AccountStatus::Active, 'check-static flagged the token written by the reconnect.')
        ->and($fresh->last_error)->toBeNull()
        ->and($control->fresh()->status)->toBe(AccountStatus::NeedsReconnect, 'The control Page was not flagged: this run never reached the flagging path.');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertDispatched(CredentialNeedsReconnect::class, fn (CredentialNeedsReconnect $event) => $event->token->is($control));
});
