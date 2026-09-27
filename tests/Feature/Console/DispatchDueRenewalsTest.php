<?php

use Illuminate\Support\Facades\Bus;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

/** A credential backing one account, unless $withAccount is false (an orphan). */
function makeCredential(array $attrs, bool $withAccount = true): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'status' => AccountStatus::Active,
    ], $attrs));

    if ($withAccount) {
        SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a-'.uniqid(), 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
    }

    return $token;
}

it('dispatches a renewal job only for due credentials', function () {
    Bus::fake();

    $due = makeCredential(['renew_at' => now()->subMinute()]);
    makeCredential(['renew_at' => now()->addHour()]);          // not yet due
    makeCredential(['renew_at' => null]);                      // static — never scanned
    makeCredential(['status' => AccountStatus::NeedsReconnect, 'renew_at' => now()->subDay()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 1 renewal job(s).')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(RenewCredential::class, 1);
    Bus::assertDispatched(RenewCredential::class, fn ($job) => $job->token->is($due));
});

it('stops dispatching a credential once its renewal has run', function () {
    config()->set('queue.default', 'sync');
    FakeConnector::reset();
    FakeConnector::$nextResult = RenewalResult::success(accessToken: 'extended', expiresAt: now()->addDays(60));

    makeCredential([
        'access_token' => 'current',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addDays(6), // still valid, but the window is open
        'renew_at' => now()->subMinute(),
    ]);

    $this->artisan('social-tokens:dispatch-renewals')->expectsOutputToContain('Dispatched 1 renewal job(s).');
    $this->artisan('social-tokens:dispatch-renewals')->expectsOutputToContain('Dispatched 0 renewal job(s).');

    expect(FakeConnector::$renewCalls)->toBe(1);
});

it('skips due credentials that no account uses any more', function () {
    Bus::fake();

    $used = makeCredential(['renew_at' => now()->subMinute()]);
    makeCredential(['renew_at' => now()->subMinute()], withAccount: false); // orphan, e.g. left behind by a reconnect

    $this->artisan('social-tokens:dispatch-renewals')->expectsOutputToContain('Dispatched 1 renewal job(s).');

    Bus::assertDispatched(RenewCredential::class, fn ($job) => $job->token->is($used));
});

it('dispatches nothing when no credentials are due', function () {
    Bus::fake();

    makeCredential(['renew_at' => now()->addHour()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 0 renewal job(s).')
        ->assertSuccessful();

    Bus::assertNotDispatched(RenewCredential::class);
});
