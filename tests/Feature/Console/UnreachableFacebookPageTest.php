<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

/*
 * Meta answers 100/33 ("Object with ID 'me' does not exist, cannot be loaded
 * due to missing permissions, or does not support this operation") to a Page
 * token's /me both when the Page token is stale and when Facebook keeps the
 * Page out of the API (restricted by country or age, limited by Facebook).
 * The check left it unknown: logged "Uncatalogued" every day, never flagged.
 * It now asks the Page's user token which it is.
 */

/** A Page's static credential and account, minted from user fb-user-1's token. */
function unreachablePage(string $pageId = 'page-1', string $token = 'page-token'): SocialToken
{
    $credential = SocialToken::create([
        'provider' => 'facebook',
        'provider_holder_id' => $pageId,
        'access_token' => $token,
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'facebook',
        'provider_user_id' => $pageId,
        'provider_holder_id' => 'fb-user-1',
        'social_token_id' => $credential->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $credential;
}

/** The Meta user token the Pages were minted from (renewable, backs no account itself). */
function unreachablePageUser(array $attrs = []): SocialToken
{
    return SocialToken::create($attrs + [
        'provider' => 'facebook',
        'provider_holder_id' => 'fb-user-1',
        'access_token' => 'user-token',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addDays(50),
        'renew_at' => now()->addDays(43),
    ]);
}

function unreachableError(): array
{
    return ['error' => [
        'message' => "Unsupported get request. Object with ID 'me' does not exist, cannot be loaded due to missing permissions, or does not support this operation.",
        'type' => 'GraphMethodException',
        'code' => 100,
        'error_subcode' => 33,
        'fbtrace_id' => 'trace-1',
    ]];
}

/**
 * Graph as seen by each token: the stored Page tokens get 100/33 on /me;
 * the user token lists $listed (page id => fresh token); a fresh token reads
 * its Page unless it is in $refused.
 *
 * @param  array<string, string>|null  $listed  null: /me/accounts fails
 * @param  array<int, string>  $refused
 */
function fakeUnreachableGraph(?array $listed, array $refused = [], array $working = []): void
{
    Http::fake(function (Request $request) use ($listed, $refused, $working) {
        $token = substr((string) ($request->header('Authorization')[0] ?? ''), 7);
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/me/accounts')) {
            return $listed === null
                ? Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable', 'type' => 'OAuthException']], 503)
                : Http::response(['data' => collect($listed)->map(fn ($fresh, $id) => ['id' => (string) $id, 'access_token' => $fresh])->values()->all()]);
        }

        if (str_ends_with($path, '/me')) {
            return in_array($token, $working, true)
                ? Http::response(['id' => 'page'])
                : Http::response(unreachableError(), 400);
        }

        // GET /{page-id} with a fresh Page token
        $pageId = basename($path);

        return in_array($token, $refused, true)
            ? Http::response(unreachableError(), 400)
            : Http::response(['id' => $pageId]);
    });
}

it('flags a Page its user no longer manages, on the second run', function () {
    unreachablePageUser();
    $page = unreachablePage();
    fakeUnreachableGraph(listed: ['page-other' => 'other-token']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.')->assertSuccessful();

    expect($page->fresh()->status)->toBe(AccountStatus::Active)
        ->and($page->fresh()->failed_checks)->toBe(1);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 1 static credential(s), flagged 1.');

    expect($page->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($page->fresh()->last_error)->toBe('The Page is no longer among the Pages its user manages (Meta 100/33).');
});

it('replaces a stale Page token with a fresh one that works', function () {
    Log::spy();
    unreachablePageUser();
    $page = unreachablePage();
    $page->forceFill(['failed_checks' => 1])->save();
    fakeUnreachableGraph(listed: ['page-1' => 'fresh-page-token']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.')->assertSuccessful();

    $fresh = $page->fresh();

    expect($fresh->access_token)->toBe('fresh-page-token')
        ->and($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->failed_checks)->toBe(0)
        ->and($fresh->renew_at)->toBeNull()
        ->and($fresh->expires_at)->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/page-1')
        && $request['fields'] === 'id'
        && $request->hasHeader('Authorization', 'Bearer fresh-page-token'));
    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'Replaced a token'))->once();
    Log::shouldNotHaveReceived('error');
});

it('flags a Page Facebook keeps out of the API, even with a fresh token, on the second run', function () {
    unreachablePageUser();
    $page = unreachablePage();
    fakeUnreachableGraph(listed: ['page-1' => 'fresh-page-token'], refused: ['fresh-page-token']);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.');

    expect($page->fresh()->failed_checks)->toBe(1)
        ->and($page->fresh()->access_token)->toBe('page-token');

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 1.');

    expect($page->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($page->fresh()->last_error)->toContain('Facebook refuses API access to this Page');
});

it('decides nothing without a usable user token or an answer from it', function (Closure $user, ?array $listed) {
    Log::spy();
    $user();
    $page = unreachablePage();
    fakeUnreachableGraph($listed);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.');
    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.');

    expect($page->fresh()->status)->toBe(AccountStatus::Active)
        ->and($page->fresh()->failed_checks)->toBe(0)
        ->and($page->fresh()->access_token)->toBe('page-token');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === '[social-tokens] Uncatalogued credential check error'
        && str_contains($context['reason'], '100/33'))->twice();
})->with([
    'no user credential' => [fn () => null, ['page-1' => 'fresh-page-token']],
    'a flagged user credential' => [fn () => unreachablePageUser(['status' => AccountStatus::NeedsReconnect]), ['page-1' => 'fresh-page-token']],
    'another owner\'s user credential' => [fn () => unreachablePageUser(['ownable_type' => 'owner', 'ownable_id' => 1]), ['page-1' => 'fresh-page-token']],
    'the Page list failing' => [fn () => unreachablePageUser(), null],
]);

it('does not resolve a 100/33 on a token that is no Page\'s', function () {
    // The Meta user token behind Instagram accounts: no Page account of its own
    $user = unreachablePageUser(['expires_at' => null, 'renew_at' => null]);
    SocialAccount::create(['provider' => 'instagram', 'provider_user_id' => 'ig-1', 'provider_holder_id' => 'fb-user-1', 'social_token_id' => $user->getKey(), 'status' => AccountStatus::Active]);
    fakeUnreachableGraph(listed: []);

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.');

    expect($user->fresh()->failed_checks)->toBe(0);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/me/accounts'));
});

it('lists a user\'s Pages once for all of their unreachable Pages', function () {
    unreachablePageUser();
    $first = unreachablePage('page-1', 'stale-1');
    $second = unreachablePage('page-2', 'stale-2');
    fakeUnreachableGraph(listed: ['page-1' => 'fresh-1', 'page-2' => 'fresh-2']);

    $this->artisan('social-tokens:check-static')->assertSuccessful();

    expect($first->fresh()->access_token)->toBe('fresh-1')
        ->and($second->fresh()->access_token)->toBe('fresh-2');

    Http::assertSentCount(5); // two /me, one /me/accounts, two /{page-id}
});

it('still lets the breaker stop the verdicts when every Page fails at once', function () {
    config(['social-tokens.check_static_breaker' => ['min_checked' => 3, 'max_terminal_ratio' => 0.2]]);
    unreachablePageUser();
    $pages = collect(['page-1', 'page-2', 'page-3'])->map(fn (string $id) => unreachablePage($id, "stale-{$id}"));
    fakeUnreachableGraph(listed: ['page-1' => 'f-1', 'page-2' => 'f-2', 'page-3' => 'f-3'], refused: ['f-1', 'f-2', 'f-3']);

    $this->artisan('social-tokens:check-static')->assertFailed();
    $this->artisan('social-tokens:check-static')->assertFailed();

    $pages->each(fn (SocialToken $page) => expect($page->fresh()->status)->toBe(AccountStatus::Active)
        ->and($page->fresh()->failed_checks)->toBe(0));
});

it('stores the fresh Page token when a publish reports the stale one', function () {
    unreachablePageUser();
    $page = unreachablePage();
    fakeUnreachableGraph(listed: ['page-1' => 'fresh-page-token']);

    $account = SocialAccount::where('social_token_id', $page->getKey())->sole();

    expect(app(SocialTokens::class)->reportRejected($account, '100/33 on publish', terminal: false))->toBeFalse()
        ->and($page->fresh()->access_token)->toBe('fresh-page-token')
        ->and($page->fresh()->status)->toBe(AccountStatus::Active)
        ->and(app(SocialTokens::class)->validAccessTokenFor($account->fresh()))->toBe('fresh-page-token');
});

it('does not overwrite a token a reconnect stored during the check', function () {
    $page = unreachablePage();
    $checkedCipher = $page->getRawOriginal('access_token');

    // A reconnect writes a new Page token meanwhile
    SocialToken::query()->whereKey($page->getKey())->first()->forceFill(['access_token' => 'reconnected-token'])->save();

    expect($page->replaceCheckedAccessToken($checkedCipher, 'fresh-page-token'))->toBeFalse()
        ->and($page->fresh()->access_token)->toBe('reconnected-token');
});
