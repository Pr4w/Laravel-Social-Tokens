<?php

use Illuminate\Support\Facades\Event;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
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
