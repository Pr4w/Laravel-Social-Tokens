<?php

/*
 * An OAuth `invalid_token` from Google, TikTok or X usually means the access
 * token expired; the refresh token still works. The README told apps to report
 * it as terminal, which flags a healthy credential, and did not say that
 * `terminal: false` does nothing for a connector that cannot check a token.
 * LinkedIn checks a token whether or not refresh tokens are on, so its
 * guidance differs: report an ambiguous 401 with `terminal: false`.
 *
 * A non-definitive report is one strike that only `social-tokens:check-static`
 * confirms. An app that turned that schedule off never gets the confirmation,
 * so the guidance has to say so wherever it describes the strike.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

function reportGuidanceReadme(): string
{
    return (string) file_get_contents(__DIR__.'/../../README.md');
}

/** The text between $heading and the next heading of the same level. */
function reportGuidanceSection(string $file, string $heading): string
{
    $text = (string) file_get_contents(__DIR__.'/../../'.$file);
    $start = strpos($text, "\n".$heading."\n");

    expect($start)->not->toBeFalse();

    $level = strstr($heading, ' ', true).' ';
    $end = strpos($text, "\n".$level, $start + strlen($heading) + 2);

    return substr($text, $start, $end === false ? null : $end - $start);
}

/** The bullet of $section that starts with $opening, up to the next bullet. */
function reportGuidanceBullet(string $section, string $opening): string
{
    $start = strpos($section, "\n- ".$opening);

    expect($start)->not->toBeFalse();

    $end = strpos($section, "\n- ", $start + 3);

    return substr($section, $start, $end === false ? null : $end - $start);
}

function reportGuidanceLinkedInWithRefresh(): SocialAccount
{
    config()->set('social-tokens.connectors.linkedin.refresh_enabled', true);
    app()->forgetInstance(ConnectorRegistry::class);

    $token = SocialToken::create([
        'provider' => 'linkedin',
        'provider_holder_id' => 'member-1',
        'access_token' => 'member-token',
        'refresh_token' => 'member-refresh-token',
        'expires_at' => now()->addDays(50),
        'renew_at' => now()->addDays(45),
        'status' => AccountStatus::Active,
    ]);

    return SocialAccount::create(['provider' => 'linkedin', 'provider_user_id' => 'org-1', 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
}

it('does not tell apps to report an OAuth invalid_token as terminal', function () {
    $readme = reportGuidanceReadme();

    expect($readme)->not->toMatch('/terminal:[^\n]*invalid_token/')
        ->and($readme)->toContain('do not report a 401')
        ->and((string) (new ReflectionMethod(SocialTokens::class, 'reportRejected'))->getDocComment())
        ->not->toContain('invalid_grant/invalid_token');
});

it('says a non-terminal report does nothing for connectors that cannot check a token', function () {
    $bullet = reportGuidanceBullet(reportGuidanceSection('README.md', '## Posting'), 'Google, TikTok');

    expect($bullet)->toContain('`terminal: false` does nothing for them')
        ->and($bullet)->not->toContain('LinkedIn');

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

it('tells apps with LinkedIn refresh tokens to report a revoked token as non-terminal, which LinkedIn confirms', function () {
    $bullet = reportGuidanceBullet(reportGuidanceSection('README.md', '## Posting'), 'LinkedIn with refresh tokens');

    expect($bullet)->toContain('`REVOKED_ACCESS_TOKEN`')
        ->and($bullet)->toContain('`terminal: false`')
        ->and($bullet)->not->toContain('does nothing');

    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'revoked', 'client_id' => 'linkedin-id'])]);

    $account = reportGuidanceLinkedInWithRefresh();

    expect(app(SocialTokens::class)->reportRejected($account, '401 REVOKED_ACCESS_TOKEN', terminal: false))->toBeTrue()
        ->and($account->credential()->first()->status)->toBe(AccountStatus::NeedsReconnect);

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('leaves an expired LinkedIn access token to the renewal when refresh tokens are on', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake(['www.linkedin.com/oauth/v2/introspectToken' => Http::response(['active' => false, 'status' => 'expired'])]);

    $account = reportGuidanceLinkedInWithRefresh();

    expect(app(SocialTokens::class)->reportRejected($account, '401 EXPIRED_ACCESS_TOKEN', terminal: false))->toBeFalse()
        ->and($account->credential()->first()->status)->toBe(AccountStatus::Active)
        ->and($account->credential()->first()->failed_checks)->toBe(0);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('says only check-static confirms a reported strike, so an app without its schedule must run it', function () {
    $readme = reportGuidanceBullet(reportGuidanceSection('README.md', '## Posting'), '`terminal: false`');
    $upgrade = reportGuidanceSection('UPGRADE.md', '#### Review your `reportRejected()` calls');
    $changelog = reportGuidanceBullet(reportGuidanceSection('CHANGELOG.md', '## [Unreleased]'), '**(breaking)** `reportRejected(terminal: false)` flags only');
    $docblock = (string) (new ReflectionMethod(SocialTokens::class, 'reportRejected'))->getDocComment();

    foreach ([$readme, $upgrade, $changelog, $docblock] as $guidance) {
        expect($guidance)->toContain('check_static_schedule');
    }
});
