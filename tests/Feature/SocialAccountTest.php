<?php

use Illuminate\Support\Facades\Event;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

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

it('belongs to the credential it posts with', function () {
    $token = SocialToken::create(['provider' => 'tiktok', 'provider_holder_id' => 'h1', 'status' => AccountStatus::Active]);
    $account = account(['social_token_id' => $token->getKey()]);

    expect($account->credential->is($token))->toBeTrue();
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

// Effective status -----------------------------------------------------------

function credentialWith(AccountStatus $status): SocialToken
{
    return SocialToken::create(['provider' => 'tiktok', 'provider_holder_id' => 'h-'.uniqid(), 'status' => $status]);
}

it('reports a dead credential on every account it backs, with a single event', function () {
    Event::fake([CredentialNeedsReconnect::class, AccountNeedsReconnect::class]);

    $token = credentialWith(AccountStatus::Active);
    $accounts = collect(range(1, 3))->map(fn () => account(['social_token_id' => $token->getKey()]));

    $token->markNeedsReconnect('refresh token revoked');

    $accounts->each(fn (SocialAccount $account) => expect($account->fresh()->effectiveStatus())->toBe(AccountStatus::NeedsReconnect)
        ->and($account->fresh()->isUsable())->toBeFalse()
        ->and($account->fresh()->status)->toBe(AccountStatus::Active)); // the row itself is untouched

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
    Event::assertNotDispatched(AccountNeedsReconnect::class);
});

it('combines account and credential status, the most severe winning', function (AccountStatus $account, AccountStatus $credential, AccountStatus $effective) {
    $row = account(['status' => $account, 'social_token_id' => credentialWith($credential)->getKey()]);

    expect($row->effectiveStatus())->toBe($effective);
})->with([
    'both active' => [AccountStatus::Active, AccountStatus::Active, AccountStatus::Active],
    'credential needs reconnect' => [AccountStatus::Active, AccountStatus::NeedsReconnect, AccountStatus::NeedsReconnect],
    'account flagged on its own' => [AccountStatus::NeedsReconnect, AccountStatus::Active, AccountStatus::NeedsReconnect],
    'revoked credential wins' => [AccountStatus::NeedsReconnect, AccountStatus::Revoked, AccountStatus::Revoked],
    'revoked account wins' => [AccountStatus::Revoked, AccountStatus::NeedsReconnect, AccountStatus::Revoked],
]);

it('treats an account without a credential as needing reconnection', function () {
    expect(account()->effectiveStatus())->toBe(AccountStatus::NeedsReconnect);
});

it('becomes usable again once its credential is reconnected', function () {
    $token = credentialWith(AccountStatus::NeedsReconnect);
    $account = account(['social_token_id' => $token->getKey()]);

    $token->update(['status' => AccountStatus::Active]);

    expect($account->fresh()->isUsable())->toBeTrue();
});

it('scopes usable and unusable accounts from both statuses', function () {
    $healthy = account(['social_token_id' => credentialWith(AccountStatus::Active)->getKey()]);
    $deadCredential = account(['social_token_id' => credentialWith(AccountStatus::NeedsReconnect)->getKey()]);
    $flagged = account(['status' => AccountStatus::NeedsReconnect, 'social_token_id' => credentialWith(AccountStatus::Active)->getKey()]);
    $orphan = account();

    expect(SocialAccount::query()->usable()->pluck('id')->all())->toBe([$healthy->id])
        ->and(SocialAccount::query()->unusable()->orderBy('id')->pluck('id')->all())
        ->toBe([$deadCredential->id, $flagged->id, $orphan->id]);
});
