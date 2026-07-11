<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Event;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\TokenRenewed;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

function account(array $attrs = []): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'tiktok',
        'provider_user_id' => 'u'.uniqid(),
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('honours the configured table name', function () {
    config()->set('social-tokens.table', 'my_accounts');

    expect((new SocialAccount)->getTable())->toBe('my_accounts');
});

it('detects an expired access token with a buffer', function () {
    expect(account(['expires_at' => null])->isAccessTokenExpired())->toBeFalse()
        ->and(account(['expires_at' => now()->addHour()])->isAccessTokenExpired())->toBeFalse()
        ->and(account(['expires_at' => now()->addSeconds(10)])->isAccessTokenExpired())->toBeTrue()
        ->and(account(['expires_at' => now()->subMinute()])->isAccessTokenExpired())->toBeTrue()
        ->and(account(['expires_at' => now()->addSeconds(10)])->isAccessTokenExpired(0))->toBeFalse();
});

it('detects an expired refresh token', function () {
    expect(account(['refresh_expires_at' => null])->isRefreshTokenExpired())->toBeFalse()
        ->and(account(['refresh_expires_at' => now()->addDay()])->isRefreshTokenExpired())->toBeFalse()
        ->and(account(['refresh_expires_at' => now()->subDay()])->isRefreshTokenExpired())->toBeTrue();
});

it('scopes accounts due for renewal', function () {
    $due = account(['status' => AccountStatus::Active, 'renew_at' => now()->subMinute()]);
    account(['status' => AccountStatus::Active, 'renew_at' => now()->addHour()]);      // not yet due
    account(['status' => AccountStatus::Active, 'renew_at' => null]);                    // no schedule
    account(['status' => AccountStatus::NeedsReconnect, 'renew_at' => now()->subDay()]); // not usable

    $ids = SocialAccount::query()->dueForRenewal()->pluck('id');

    expect($ids->all())->toBe([$due->id]);
});

it('applies a rotating renewal and recomputes the renewal window', function () {
    $this->freezeTime();
    Event::fake([TokenRenewed::class]);

    $account = account([
        'status' => AccountStatus::NeedsReconnect,
        'refresh_token' => 'old-refresh',
        'last_error' => 'prior error',
    ]);

    FakeConnector::$lead = CarbonInterval::minutes(15);

    $result = RenewalResult::success(
        accessToken: 'fresh-access',
        expiresAt: now()->addHour(),
        refreshToken: 'new-refresh',
    );

    $account->applyRenewal($result, new FakeConnector);

    expect($account->access_token)->toBe('fresh-access')
        ->and($account->refresh_token)->toBe('new-refresh')
        ->and($account->status)->toBe(AccountStatus::Active)
        ->and($account->last_error)->toBeNull()
        ->and($account->last_renewed_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($account->expires_at->toDateTimeString())->toBe(now()->addHour()->toDateTimeString())
        ->and($account->renew_at->toDateTimeString())->toBe(now()->addMinutes(45)->toDateTimeString());

    Event::assertDispatched(TokenRenewed::class);
});

it('keeps the existing refresh token when the result carries none', function () {
    $account = account(['refresh_token' => 'keep-me']);

    $account->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour(), refreshToken: null),
        new FakeConnector,
    );

    expect($account->refresh_token)->toBe('keep-me');
});

it('clears renew_at when a renewal reports no expiry', function () {
    $account = account(['renew_at' => now()->subDay(), 'expires_at' => now()->subHour()]);

    $account->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', expiresAt: null),
        new FakeConnector,
    );

    expect($account->renew_at)->toBeNull();
});

it('merges profile data on renewal', function () {
    $account = account(['profile' => ['a' => 1]]);

    $account->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', profile: ['b' => 2]),
        new FakeConnector,
    );

    expect($account->profile)->toBe(['a' => 1, 'b' => 2]);
});

it('marks an account for reconnection and fires an event', function () {
    Event::fake([AccountNeedsReconnect::class]);

    $account = account();
    $account->markNeedsReconnect('token dead');

    expect($account->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($account->last_error)->toBe('token dead');

    Event::assertDispatched(AccountNeedsReconnect::class);
});

it('marks an account revoked and fires an event', function () {
    Event::fake([AccountRevoked::class]);

    $account = account();
    $account->markRevoked();

    expect($account->status)->toBe(AccountStatus::Revoked);

    Event::assertDispatched(AccountRevoked::class);
});

it('exposes scope helpers', function () {
    $account = account(['scopes' => ['a', 'b']]);

    expect($account->grantedScopes())->toBe(['a', 'b'])
        ->and($account->hasScope('a'))->toBeTrue()
        ->and($account->hasScope('c'))->toBeFalse()
        ->and($account->hasScopes(['a', 'b']))->toBeTrue()
        ->and($account->hasScopes(['a', 'c']))->toBeFalse()
        ->and($account->missingScopes(['a', 'c', 'd']))->toBe(['c', 'd']);
});

it('treats a null scopes column as empty', function () {
    $account = account(['scopes' => null]);

    expect($account->grantedScopes())->toBe([])
        ->and($account->hasScope('a'))->toBeFalse()
        ->and($account->hasScopes([]))->toBeTrue()
        ->and($account->missingScopes(['a']))->toBe(['a']);
});
