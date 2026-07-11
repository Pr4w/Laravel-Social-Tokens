<?php

use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

function socialToken(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'facebook',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'cred-token',
        'status' => AccountStatus::Active,
    ], $attrs));
}

it('uses the configured tokens table', function () {
    expect((new SocialToken)->getTable())->toBe('social_tokens');
});

it('backs many accounts and each account belongs to it', function () {
    $token = socialToken();
    $a = SocialAccount::create(['provider' => 'facebook', 'provider_user_id' => 'p1', 'social_token_id' => $token->id, 'status' => AccountStatus::Active]);
    $b = SocialAccount::create(['provider' => 'instagram', 'provider_user_id' => 'ig1', 'social_token_id' => $token->id, 'status' => AccountStatus::Active]);

    expect($token->accounts)->toHaveCount(2)
        ->and($a->credential->is($token))->toBeTrue()
        ->and($b->credential->is($token))->toBeTrue();
});

it('detects expired access and refresh tokens', function () {
    expect(socialToken(['expires_at' => now()->subMinute()])->isAccessTokenExpired())->toBeTrue()
        ->and(socialToken(['expires_at' => now()->addHour()])->isAccessTokenExpired())->toBeFalse()
        ->and(socialToken(['refresh_expires_at' => now()->subDay()])->isRefreshTokenExpired())->toBeTrue();
});

it('scopes credentials due for renewal', function () {
    socialToken(['provider_holder_id' => 'due', 'renew_at' => now()->subMinute()]);
    socialToken(['provider_holder_id' => 'later', 'renew_at' => now()->addHour()]);
    socialToken(['provider_holder_id' => 'revoked', 'status' => AccountStatus::NeedsReconnect, 'renew_at' => now()->subDay()]);

    expect(SocialToken::query()->dueForRenewal()->pluck('provider_holder_id')->all())->toBe(['due']);
});

it('applies a renewal and recomputes the renewal window', function () {
    FakeConnector::reset();
    $token = socialToken();

    $token->applyRenewal(
        RenewalResult::success(accessToken: 'fresh', expiresAt: now()->addHour(), refreshToken: 'new-refresh'),
        new FakeConnector, // 15 minute lead time
    );

    expect($token->access_token)->toBe('fresh')
        ->and($token->refresh_token)->toBe('new-refresh')
        ->and($token->status)->toBe(AccountStatus::Active)
        ->and($token->renew_at->timestamp)->toEqualWithDelta(now()->addHour()->subMinutes(15)->timestamp, 2);
});

it('marks a credential for reconnection', function () {
    $token = socialToken();

    $token->markNeedsReconnect('revoked upstream');

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toBe('revoked upstream');
});
