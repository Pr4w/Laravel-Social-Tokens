<?php

use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Models\SocialAccount;

it('builds a default message from the account', function () {
    $account = SocialAccount::create([
        'provider' => 'tiktok',
        'provider_user_id' => 'x',
        'status' => AccountStatus::Active,
    ]);

    $exception = NeedsReconnectException::for($account);

    expect($exception->account->is($account))->toBeTrue()
        ->and($exception->getMessage())->toContain('tiktok')
        ->and($exception->getMessage())->toContain((string) $account->id);
});

it('uses a provided reason as the message', function () {
    $account = SocialAccount::create([
        'provider' => 'tiktok',
        'provider_user_id' => 'y',
        'status' => AccountStatus::Active,
    ]);

    $exception = NeedsReconnectException::for($account, 'token revoked');

    expect($exception->getMessage())->toBe('token revoked')
        ->and($exception->account->is($account))->toBeTrue();
});
