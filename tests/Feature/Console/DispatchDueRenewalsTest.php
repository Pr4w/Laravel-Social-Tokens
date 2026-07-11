<?php

use Illuminate\Support\Facades\Bus;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialToken;

function makeCredential(array $attrs): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'status' => AccountStatus::Active,
    ], $attrs));
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

it('dispatches nothing when no credentials are due', function () {
    Bus::fake();

    makeCredential(['renew_at' => now()->addHour()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Dispatched 0 renewal job(s).')
        ->assertSuccessful();

    Bus::assertNotDispatched(RenewCredential::class);
});
