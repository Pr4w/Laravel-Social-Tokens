<?php

use Illuminate\Support\Facades\Bus;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewAccountToken;
use Pr4w\SocialTokens\Models\SocialAccount;

function makeAccount(array $attrs): SocialAccount
{
    return SocialAccount::create(array_merge([
        'provider' => 'fake',
        'provider_user_id' => 'c-'.uniqid(),
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('dispatches a renewal job only for due accounts', function () {
    Bus::fake();

    $due = makeAccount(['renew_at' => now()->subMinute()]);
    makeAccount(['renew_at' => now()->addHour()]);              // not yet due
    makeAccount(['renew_at' => null]);                          // no schedule
    makeAccount(['status' => AccountStatus::NeedsReconnect, 'renew_at' => now()->subDay()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 1 renewal job(s).')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(RenewAccountToken::class, 1);
    Bus::assertDispatched(RenewAccountToken::class, fn ($job) => $job->account->is($due));
});

it('dispatches nothing when no accounts are due', function () {
    Bus::fake();

    makeAccount(['renew_at' => now()->addHour()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 0 renewal job(s).')
        ->assertSuccessful();

    Bus::assertNotDispatched(RenewAccountToken::class);
});
