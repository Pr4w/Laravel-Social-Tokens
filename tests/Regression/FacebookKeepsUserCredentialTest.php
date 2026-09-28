<?php

/*
 * Regression: a Facebook-only connection must keep the renewable Meta user
 * credential.
 *
 * StoreFacebookPages extends the Facebook user token (fb_exchange_token), uses it
 * to list /me/accounts and call debug_token, then drops it: only one static
 * page-token credential per Page is stored. The README promises that "a Meta
 * user token backs every Facebook Page and Instagram account for that user", and
 * StoreInstagramAccounts does store it (provider "facebook", holder = the
 * Facebook user id, renewable on the connector's lead time). In v1.1.0 a user
 * who connects Facebook Pages only has no user credential at all, so an app
 * cannot use it for per-Page scope checks or removed-Page detection, and a
 * Facebook reconnect never refreshes the credential Instagram accounts post with.
 *
 * Even when that credential exists, social-tokens:dispatch-renewals only renews a
 * credential some account points at through social_token_id. Page accounts point
 * at their page tokens and only name the user through provider_holder_id, so a
 * user credential that backs Pages alone is never renewed and dies after about
 * 60 days.
 *
 * The page accounts must keep posting with their own static page tokens: only
 * the user credential is added, next to them. It stays renewed while at least
 * one Page of that user is connected, and no longer once none is.
 */

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Actions\StoreFacebookPages;
use Pr4w\SocialTokens\Actions\StoreInstagramAccounts;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/** Seconds fb_exchange_token grants a long-lived user token (about 60 days). */
function facebookUserCredentialExpiresIn(): int
{
    return 5183944;
}

/**
 * Fake the Graph API. fb_exchange_token answers "long-{input token}", so each
 * test can tell which connection produced the stored token.
 *
 * @param  array<int, array<string, mixed>>  $pages
 */
function facebookUserCredentialFakeGraph(array $pages, string $userId = 'user-1'): void
{
    Http::fake(function ($request) use ($pages, $userId) {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response([
                'access_token' => 'long-'.($query['fb_exchange_token'] ?? ''),
                'token_type' => 'bearer',
                'expires_in' => facebookUserCredentialExpiresIn(),
            ]),
            str_contains($url, '/debug_token') => Http::response(['data' => [
                'scopes' => ['pages_show_list', 'pages_manage_posts', 'instagram_content_publish'],
                'granular_scopes' => [],
            ]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => $pages]),
            str_contains($url, '/me') => Http::response(['id' => $userId]),
            default => Http::response([], 404),
        };
    });
}

/** Two Pages, neither linked to an Instagram Business account. */
function facebookUserCredentialPagesOnly(): array
{
    return [
        ['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1', 'picture' => ['data' => ['url' => 'p1.png']]],
        ['id' => 'page-2', 'name' => 'Page Two', 'access_token' => 'pt-2', 'picture' => ['data' => ['url' => 'p2.png']]],
    ];
}

/** The Meta user credential (provider facebook, holder = the Facebook user id). */
function facebookUserCredential(string $userId = 'user-1'): ?SocialToken
{
    return SocialToken::query()
        ->where('provider', 'facebook')
        ->where('provider_holder_id', $userId)
        ->first();
}

function facebookUserCredentialLeadTimeSeconds(): int
{
    return (int) app(ConnectorRegistry::class)->for('facebook')->leadTime()->totalSeconds;
}

it('stores the renewable Meta user credential when connecting Facebook Pages only', function () {
    $this->freezeSecond();
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());

    $accounts = app(StoreFacebookPages::class)->handle(userToken: 'short', userId: 'user-1');

    $credential = facebookUserCredential();

    expect($credential)->not->toBeNull('StoreFacebookPages dropped the extended Meta user token: no social_tokens row for (facebook, user-1).')
        ->and($credential->access_token)->toBe('long-short')
        ->and($credential->refresh_token)->toBeNull()
        ->and($credential->status)->toBe(AccountStatus::Active)
        ->and($credential->expires_at?->timestamp)->toBe(now()->addSeconds(facebookUserCredentialExpiresIn())->timestamp)
        ->and($credential->renew_at?->timestamp)->toBe(
            now()->addSeconds(facebookUserCredentialExpiresIn() - facebookUserCredentialLeadTimeSeconds())->timestamp
        );

    // The user credential is not a postable account: still one row per Page.
    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('provider_user_id')->sort()->values()->all())->toBe(['page-1', 'page-2'])
        ->and(SocialAccount::count())->toBe(2);
});

it('stores the Meta user credential when StoreConnection handles the facebook provider', function () {
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());

    app(StoreConnection::class)->handle('facebook', socialiteUser(['id' => 'user-1', 'token' => 'socialite-token']));

    $credential = facebookUserCredential();

    expect($credential)->not->toBeNull('StoreConnection("facebook") left no Meta user credential for user-1.')
        ->and($credential->access_token)->toBe('long-socialite-token')
        ->and($credential->renew_at)->not->toBeNull();
});

it('keeps Facebook Pages posting with their own static page tokens', function () {
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());

    app(StoreFacebookPages::class)->handle(userToken: 'short', userId: 'user-1');

    $page = SocialAccount::query()->where('provider', 'facebook')->where('provider_user_id', 'page-1')->sole();

    expect($page->credential->provider_holder_id)->toBe('page-1')
        ->and($page->credential->renew_at)->toBeNull()
        ->and($page->credential->expires_at)->toBeNull()
        ->and(app(SocialTokens::class)->validAccessTokenFor($page))->toBe('pt-1');
});

it('keeps a single, current Meta user credential across Facebook reconnects', function () {
    $this->freezeSecond();
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());

    app(StoreFacebookPages::class)->handle(userToken: 'first', userId: 'user-1');

    $this->travel(30)->days();
    app(StoreFacebookPages::class)->handle(userToken: 'second', userId: 'user-1');

    $credentials = SocialToken::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->get();

    expect($credentials)->toHaveCount(1)
        ->and($credentials->sole()->access_token)->toBe('long-second')
        ->and($credentials->sole()->expires_at?->timestamp)->toBe(now()->addSeconds(facebookUserCredentialExpiresIn())->timestamp);
});

it('refreshes the Meta user credential Instagram accounts post with on a Facebook reconnect', function () {
    facebookUserCredentialFakeGraph([
        ['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1',
            'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one']],
    ]);

    app(StoreInstagramAccounts::class)->handle(userToken: 'instagram-login', userId: 'user-1');
    $instagram = SocialAccount::query()->where('provider', 'instagram')->where('provider_user_id', 'ig-1')->sole();

    // Meta invalidated the old user token (e.g. password change) and it was flagged.
    facebookUserCredential()->markNeedsReconnect('Error validating access token: the session has been invalidated.');

    // The user reconnects through the Facebook flow: same Meta user, new token.
    app(StoreFacebookPages::class)->handle(userToken: 'facebook-login', userId: 'user-1');

    $credential = facebookUserCredential();

    expect(SocialToken::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->count())->toBe(1)
        ->and($credential->access_token)->toBe('long-facebook-login')
        ->and($credential->status)->toBe(AccountStatus::Active)
        ->and($instagram->fresh()->social_token_id)->toBe($credential->getKey())
        ->and(app(SocialTokens::class)->validAccessTokenFor($instagram->fresh()))->toBe('long-facebook-login');
});

it('renews the Meta user credential of a Facebook-only connection when it comes due', function () {
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());
    app(StoreFacebookPages::class)->handle(userToken: 'short', userId: 'user-1');

    // Past renew_at (expiry minus the 7-day lead), before the token expires.
    $this->travel(55)->days();
    Bus::fake([RenewCredential::class]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Bus::assertDispatched(
        RenewCredential::class,
        fn (RenewCredential $job) => $job->token->provider === 'facebook' && $job->token->provider_holder_id === 'user-1',
    );
});

it('keeps renewing the Meta user credential while its Pages stay connected after the Instagram accounts are removed', function () {
    facebookUserCredentialFakeGraph([
        ['id' => 'page-1', 'name' => 'Page One', 'access_token' => 'pt-1',
            'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta_one']],
    ]);

    // Instagram connect: the user credential, the IG account on it, and the companion Page.
    app(StoreInstagramAccounts::class)->handle(userToken: 'short', userId: 'user-1');

    // The app removes the Instagram account; the Page of the same Facebook user stays.
    SocialAccount::query()->where('provider', 'instagram')->delete();
    expect(SocialAccount::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->count())->toBe(1)
        ->and(facebookUserCredential())->not->toBeNull();

    $this->travel(55)->days();
    Bus::fake([RenewCredential::class]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Bus::assertDispatched(
        RenewCredential::class,
        fn (RenewCredential $job) => $job->token->provider === 'facebook' && $job->token->provider_holder_id === 'user-1',
    );
});

it('stops renewing the Meta user credential once none of its Pages is connected any more', function () {
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());
    app(StoreFacebookPages::class)->handle(userToken: 'short', userId: 'user-1');

    // The app removed every Page of this user.
    SocialAccount::query()->where('provider', 'facebook')->where('provider_holder_id', 'user-1')->delete();

    $this->travel(55)->days();
    Bus::fake([RenewCredential::class]);

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Bus::assertNotDispatched(
        RenewCredential::class,
        fn (RenewCredential $job) => $job->token->provider_holder_id === 'user-1',
    );
});

it('leaves no orphaned Meta user credential when the Facebook user manages no Page', function () {
    facebookUserCredentialFakeGraph([]);

    $accounts = app(StoreFacebookPages::class)->handle(userToken: 'short', userId: 'user-1');

    expect($accounts)->toBeEmpty()
        ->and(facebookUserCredential())->toBeNull()
        ->and(SocialToken::count())->toBe(0);
});

it('keeps the Meta user credential renewable when the caller already extended the token', function () {
    $this->freezeSecond();
    facebookUserCredentialFakeGraph(facebookUserCredentialPagesOnly());

    app(StoreFacebookPages::class)->handle(userToken: 'already-long-lived', userId: 'user-1', extend: false);

    $credential = facebookUserCredential();

    // Expiry unknown without the exchange: the credential must not look static
    // (renew_at null). The dispatcher skips a null renew_at, and check-static
    // skips a credential no account points at, so nothing would ever look at
    // it again. It is renewed within one lead time instead.
    expect($credential)->not->toBeNull('extend:false dropped the caller-supplied Meta user token.')
        ->and($credential->access_token)->toBe('already-long-lived')
        ->and($credential->status)->toBe(AccountStatus::Active)
        ->and($credential->renew_at)->not->toBeNull()
        ->and($credential->renew_at->timestamp)->toBeLessThanOrEqual(now()->addSeconds(facebookUserCredentialLeadTimeSeconds())->timestamp);
});
