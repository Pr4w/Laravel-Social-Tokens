<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * Nothing ever removed a credential: revoked and flagged ones, those left
 * behind by a reconnect, and those whose accounts the app deleted all stayed,
 * with their tokens. A renewable one nobody used kept a past renew_at, so
 * every dispatcher run re-read it through the inUse() subqueries, for good.
 */

/** A credential last changed $daysAgo days ago, with an account of the given status (none for null). */
function prunable(string $holder, AccountStatus $status, ?AccountStatus $account, int $daysAgo, ?Owner $owner = null): SocialToken
{
    $token = SocialToken::persistCredential(['provider' => 'tiktok', 'provider_holder_id' => $holder] + SocialAccount::ownerKey($owner), [
        'access_token' => "{$holder}-access",
        'status' => $status,
        'renew_at' => now()->addHour(),
    ]);

    if ($account !== null) {
        SocialAccount::create([
            'provider' => 'tiktok', 'provider_user_id' => $holder, 'provider_holder_id' => $holder,
            'social_token_id' => $token->getKey(), 'status' => $account,
        ] + SocialAccount::ownerKey($owner));
    }

    DB::table('social_tokens')->where('id', $token->getKey())->update(['updated_at' => now()->subDays($daysAgo)]);

    return $token;
}

function prunedIds(): array
{
    return SocialToken::query()->orderBy('id')->pluck('provider_holder_id')->all();
}

it('deletes the revoked and flagged credentials and the unused ones past the retention', function () {
    prunable('revoked-old', AccountStatus::Revoked, AccountStatus::Revoked, 31);
    prunable('revoked-new', AccountStatus::Revoked, AccountStatus::Revoked, 29);
    prunable('flagged-old', AccountStatus::NeedsReconnect, AccountStatus::Active, 31);
    prunable('orphan-old', AccountStatus::Active, null, 31);
    prunable('orphan-new', AccountStatus::Active, null, 2);
    prunable('only-revoked-accounts', AccountStatus::Active, AccountStatus::Revoked, 31);
    prunable('in-use', AccountStatus::Active, AccountStatus::Active, 400);
    prunable('flagged-account', AccountStatus::Active, AccountStatus::NeedsReconnect, 400);

    $this->artisan('social-tokens:prune')
        ->expectsOutputToContain('Pruned 4 credential(s)')
        ->assertSuccessful();

    expect(prunedIds())->toBe(['revoked-new', 'orphan-new', 'in-use', 'flagged-account']);
});

it('keeps the Meta user credential behind connected Facebook Pages', function () {
    $owner = Owner::create();
    $user = prunable('user-1', AccountStatus::Active, null, 90, $owner);
    $page = prunable('page-1', AccountStatus::Active, null, 90, $owner);
    SocialAccount::create([
        'provider' => 'tiktok', 'provider_user_id' => 'page-1', 'provider_holder_id' => 'user-1',
        'social_token_id' => $page->getKey(), 'status' => AccountStatus::Active,
    ] + SocialAccount::ownerKey($owner));

    // Another owner's Page of the same user does not keep this owner's credential.
    $other = prunable('user-1', AccountStatus::Active, null, 90, Owner::create());

    $this->artisan('social-tokens:prune')->assertSuccessful();

    expect($user->fresh())->not->toBeNull()
        ->and($page->fresh())->not->toBeNull()
        ->and($other->fresh())->toBeNull();
});

it('unlinks the accounts of a deleted credential', function () {
    $token = prunable('revoked-old', AccountStatus::Revoked, AccountStatus::Revoked, 31);

    $this->artisan('social-tokens:prune')->assertSuccessful();

    expect(SocialAccount::query()->sole()->social_token_id)->toBeNull();
});

it('takes the retention from the config, at least one day, or from --days', function () {
    prunable('ten-days', AccountStatus::Revoked, null, 10);
    prunable('hours', AccountStatus::Revoked, null, 0);

    config(['social-tokens.prune_after_days' => 'nonsense']);
    $this->artisan('social-tokens:prune')->assertSuccessful();
    expect(prunedIds())->toBe(['ten-days', 'hours']); // the default, 30

    config(['social-tokens.prune_after_days' => 0]);
    $this->artisan('social-tokens:prune')->assertSuccessful();
    expect(prunedIds())->toBe(['hours']); // at least 1 day

    $this->travel(2)->days();
    $this->artisan('social-tokens:prune', ['--days' => 1])->assertSuccessful();
    expect(prunedIds())->toBe([]);
});

it('leaves a credential reconnected since it was read', function () {
    $token = prunable('flagged-old', AccountStatus::NeedsReconnect, AccountStatus::Active, 31);

    // The user reconnects right after the command read the candidates: the
    // delete re-checks, and the credential is active and recent by then.
    $reconnected = false;
    DB::listen(function ($query) use (&$reconnected) {
        if (! $reconnected && preg_match('/^select .* from [`"]?\w*social_tokens[`"]? /', $query->sql)) {
            $reconnected = true;
            SocialToken::persistCredential(['provider' => 'tiktok', 'provider_holder_id' => 'flagged-old'], ['access_token' => 'reconnected']);
        }
    });

    $this->artisan('social-tokens:prune')->assertSuccessful();

    expect($token->fresh()?->access_token)->toBe('reconnected');
});

it('is scheduled daily, and can be turned off', function () {
    $expressions = function () {
        app()->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);

        return collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains($event->command ?? '', 'social-tokens:prune'))
            ->map(fn (ScheduledEvent $event) => [$event->expression, $event->withoutOverlapping, $event->onOneServer])
            ->values()->all();
    };

    expect($expressions())->toBe([['0 0 * * *', true, true]]);

    config(['social-tokens.prune_schedule' => '15 3 * * *']);
    expect($expressions())->toBe([['15 3 * * *', true, true]]);

    config(['social-tokens.prune_schedule' => null]);
    expect($expressions())->toBe([]);
});

// The dispatcher -------------------------------------------------------------

it('unschedules a due credential no active account uses, once', function () {
    Queue::fake();
    $unused = prunable('unused', AccountStatus::Active, AccountStatus::NeedsReconnect, 1);
    $used = prunable('used', AccountStatus::Active, AccountStatus::Active, 1);
    SocialToken::query()->update(['renew_at' => now()->subMinute()]);

    $this->artisan('social-tokens:dispatch-renewals')
        ->expectsOutputToContain('Unscheduled 1 credential(s) no active account uses.')
        ->assertSuccessful();

    expect($unused->fresh()->renew_at)->toBeNull()
        ->and($used->fresh()->renew_at)->not->toBeNull();
    Queue::assertPushed(RenewCredential::class, 1);

    $this->artisan('social-tokens:dispatch-renewals')
        ->doesntExpectOutputToContain('Unscheduled')
        ->assertSuccessful();
});

it('schedules an unscheduled credential again when its account reconnects', function () {
    Queue::fake();
    $account = app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r1', 'expiresIn' => 86400]))->sole();
    $account->markNeedsReconnect('flagged');
    $this->travel(23)->hours();

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();
    expect($account->credential->fresh()->renew_at)->toBeNull();

    app(StoreConnection::class)->handle('tiktok', socialiteUser(['id' => 'tt-1', 'refreshToken' => 'r2', 'expiresIn' => 86400]));

    expect($account->credential->fresh()->renew_at)->not->toBeNull();
});
