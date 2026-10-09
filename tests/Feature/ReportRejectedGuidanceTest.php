<?php

/*
 * An OAuth `invalid_token` from a provider with refresh tokens (Google, TikTok,
 * X, LinkedIn with refresh tokens) usually means the access token expired; the
 * refresh token still works. The README told apps to report it as terminal,
 * which flags a healthy credential, and did not say that `terminal: false`
 * does nothing for a connector that cannot check a token.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

function reportGuidanceReadme(): string
{
    return (string) file_get_contents(__DIR__.'/../../README.md');
}

it('does not tell apps to report an OAuth invalid_token as terminal', function () {
    $readme = reportGuidanceReadme();

    expect($readme)->not->toMatch('/terminal:[^\n]*invalid_token/')
        ->and($readme)->toContain('do not report a 401')
        ->and((string) (new ReflectionMethod(SocialTokens::class, 'reportRejected'))->getDocComment())
        ->not->toContain('invalid_grant/invalid_token');
});

it('says a non-terminal report does nothing for connectors that cannot check a token', function () {
    expect(reportGuidanceReadme())->toContain('`terminal: false` does nothing for them');

    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake();

    $token = SocialToken::create([
        'provider' => 'google',
        'provider_holder_id' => 'g-1',
        'access_token' => 'expired-access-token',
        'refresh_token' => 'working-refresh-token',
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subMinutes(26),
        'status' => AccountStatus::Active,
    ]);
    $account = SocialAccount::create(['provider' => 'google', 'provider_user_id' => 'g-1', 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    expect(app(SocialTokens::class)->reportRejected($account, '401 invalid_token', terminal: false))->toBeFalse()
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Http::assertNothingSent();
    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});
