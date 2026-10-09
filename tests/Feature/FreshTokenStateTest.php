<?php

/*
 * A check-static strike judges one token. Every write of a token the provider
 * has just issued or accepted (a connection, a reconnection, a successful
 * renewal) starts the credential over: active, no error, no strike, and
 * last_renewed_at set to the issue date.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);
    config()->set('logging.default', 'null');
});

/**
 * Threads: the long-lived exchange hands out $exchanged, the refresh grant
 * $refreshed, and GET /me answers a bare 190 (not definitive) for every token
 * in $rejected.
 *
 * @param  array<int, string>  $rejected
 */
function freshTokenFakeThreads(array &$rejected, string $exchanged = 'threads-token-2', string $refreshed = 'threads-token-2'): void
{
    Http::fake(function (Request $request) use (&$rejected, $exchanged, $refreshed) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (str_contains($url, 'graph.threads.net/access_token')) {
            return Http::response(['access_token' => $exchanged, 'expires_in' => 5_184_000]);
        }

        if (str_contains($url, 'graph.threads.net/refresh_access_token')) {
            return Http::response(['access_token' => $refreshed, 'expires_in' => 5_184_000]);
        }

        if (in_array($query['access_token'] ?? null, $rejected, true)) {
            return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400);
        }

        return Http::response(['id' => 'threads-user-1']);
    });
}

/** A Threads credential between two renewals with one strike against its token. */
function freshTokenStruckThreadsCredential(): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'threads',
        'provider_holder_id' => 'threads-user-1',
        'access_token' => 'threads-token-1',
        'expires_at' => now()->addDays(30),
        'renew_at' => now()->addDays(23),
        'last_renewed_at' => now()->subDays(30),
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'threads',
        'provider_user_id' => 'threads-user-1',
        'provider_holder_id' => 'threads-user-1',
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

it('does not let a reconnected token inherit the previous token\'s strike', function () {
    config(['social-tokens.check_renewable' => true]);

    $rejected = ['threads-token-1'];
    freshTokenFakeThreads($rejected);
    $credential = freshTokenStruckThreadsCredential();

    Artisan::call('social-tokens:check-static');
    expect($credential->fresh()->failed_checks)->toBe(1);

    // The user reconnects: a new long-lived token is stored on the same row.
    app(StoreConnection::class)->handle('threads', socialiteUser(['id' => 'threads-user-1', 'token' => 'short-lived']));

    expect($credential->fresh()->access_token)->toBe('threads-token-2')
        ->and($credential->fresh()->failed_checks)->toBe(0);

    // One unconfirmed glitch on the new token is its first strike, not its second.
    $rejected = ['threads-token-2'];
    Artisan::call('social-tokens:check-static');

    expect($credential->fresh()->status)->toBe(AccountStatus::Active)
        ->and($credential->fresh()->failed_checks)->toBe(1);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('clears the strike when the credential is renewed', function () {
    $rejected = [];
    freshTokenFakeThreads($rejected);
    $credential = freshTokenStruckThreadsCredential();
    $credential->forceFill(['failed_checks' => 1, 'renew_at' => now()->subMinute()])->save();

    $result = app(SocialTokens::class)->renewCredential($credential);

    expect($result->succeeded())->toBeTrue()
        ->and($credential->fresh()->access_token)->toBe('threads-token-2')
        ->and($credential->fresh()->failed_checks)->toBe(0);
});

it('clears the strike when a renewal hands back the same token', function () {
    $rejected = [];
    freshTokenFakeThreads($rejected, refreshed: 'threads-token-1');
    $credential = freshTokenStruckThreadsCredential();
    $credential->forceFill(['failed_checks' => 1, 'renew_at' => now()->subMinute()])->save();

    app(SocialTokens::class)->renewCredential($credential);

    expect($credential->fresh()->failed_checks)->toBe(0);
});

it('clears the strike when LinkedIn organizations are stored again', function () {
    Http::fake(['api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [[
        'role' => 'ADMINISTRATOR',
        'state' => 'APPROVED',
        'organization~' => ['id' => '111', 'localizedName' => 'Acme'],
    ]]])]);

    $credential = SocialToken::create([
        'provider' => 'linkedin',
        'provider_holder_id' => 'member-1',
        'purpose' => StoreLinkedInOrganizations::PURPOSE,
        'access_token' => 'member-token-1',
        'status' => AccountStatus::Active,
        'failed_checks' => 1,
    ]);

    app(StoreLinkedInOrganizations::class)->handle(accessToken: 'member-token-2', memberId: 'member-1', expiresAt: now()->addDays(60));

    expect($credential->fresh()->access_token)->toBe('member-token-2')
        ->and($credential->fresh()->failed_checks)->toBe(0);
});

it('records when a Facebook page token was issued', function () {
    $this->freezeSecond();

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => 'page-1', 'name' => 'Page', 'access_token' => 'page-token']]]),
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['scopes' => ['pages_manage_posts']]]),
        'graph.facebook.com/*' => Http::response(['id' => 'fb-user-1']),
    ]);

    app(StoreFacebookPages::class)->handle('short-user-token', userId: 'fb-user-1');

    $page = SocialToken::query()->where('provider_holder_id', 'page-1')->sole();

    expect($page->last_renewed_at?->equalTo(now()))->toBeTrue()
        ->and($page->failed_checks)->toBe(0)
        ->and($page->status)->toBe(AccountStatus::Active);
});

it('stores a freshly issued credential active, without error or strike', function () {
    $this->freezeSecond();

    SocialToken::create([
        'provider' => 'fake',
        'provider_holder_id' => 'holder-1',
        'access_token' => 'old',
        'status' => AccountStatus::NeedsReconnect,
        'last_error' => 'Token revoked.',
        'failed_checks' => 1,
    ]);

    $stored = SocialToken::persistCredential(['provider' => 'fake', 'provider_holder_id' => 'holder-1', 'ownable_type' => null, 'ownable_id' => null], [
        'access_token' => 'new',
    ]);

    $fresh = $stored->fresh();

    expect($fresh->access_token)->toBe('new')
        ->and($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->failed_checks)->toBe(0)
        ->and($fresh->last_renewed_at?->equalTo(now()))->toBeTrue();
});
