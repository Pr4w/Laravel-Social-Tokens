<?php

/*
 * reportRejected(terminal: false) asks the provider before flagging. Its answer
 * is held to the same standard as check-static's: a definitive one (a Meta
 * session subcode) flags at once; a bare 190/102, which Meta also sends to
 * valid tokens during an incident, is one strike that only a later check-static
 * run can confirm. Publish retries seconds apart never confirm each other.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

beforeEach(function () {
    Event::fake([CredentialNeedsReconnect::class]);
    config()->set('logging.default', 'null');
});

/** A Facebook Page credential (static) backing one active Page account. */
function reportStrikesPage(string $pageToken = 'page-token'): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'facebook',
        'provider_holder_id' => 'page-'.uniqid('', true),
        'access_token' => $pageToken,
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'facebook',
        'provider_user_id' => $token->provider_holder_id,
        'provider_holder_id' => 'fb-user-1',
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

function reportStrikesBare190(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190,
    ]], 400)]);
}

function reportStrikesReport(SocialToken $token, ?string $rejectedToken = null): bool
{
    return app(SocialTokens::class)->reportRejected(
        $token->accounts()->first(),
        'HTTP 400 from the feed endpoint',
        terminal: false,
        rejectedToken: $rejectedToken,
    );
}

it('does not flag on an unconfirmed rejection, and records it as one strike', function () {
    reportStrikesBare190();
    $token = reportStrikesPage();

    expect(reportStrikesReport($token))->toBeFalse()
        ->and($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->failed_checks)->toBe(1);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('never lets publish retries confirm each other', function () {
    reportStrikesBare190();
    $token = reportStrikesPage();

    foreach (range(1, 5) as $retry) {
        expect(reportStrikesReport($token))->toBeFalse();
    }

    expect($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->failed_checks)->toBe(1);

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('lets the next check-static run confirm a reported strike', function () {
    reportStrikesBare190();
    $token = reportStrikesPage();

    reportStrikesReport($token);
    Artisan::call('social-tokens:check-static');

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('lets the next check-static run clear a reported strike the provider no longer confirms', function () {
    $rejecting = true;
    Http::fake(function () use (&$rejecting) {
        return $rejecting
            ? Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400)
            : Http::response(['id' => 'page-1']);
    });
    $token = reportStrikesPage();

    reportStrikesReport($token);

    $rejecting = false; // the incident is over
    Artisan::call('social-tokens:check-static');

    expect($token->fresh()->status)->toBe(AccountStatus::Active)
        ->and($token->fresh()->failed_checks)->toBe(0);
});

it('still flags at once on a definitive answer', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'The session has been invalidated because the user changed their password.',
        'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460,
    ]], 400)]);
    $token = reportStrikesPage();

    expect(reportStrikesReport($token))->toBeTrue()
        ->and($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('records no strike against a token the credential no longer holds', function () {
    reportStrikesBare190();
    $token = reportStrikesPage('token-after-reconnect');

    expect(reportStrikesReport($token, rejectedToken: 'token-before-reconnect'))->toBeFalse()
        ->and($token->fresh()->failed_checks)->toBe(0);

    Http::assertNothingSent();
});

it('records no strike when the user reconnects while the provider is asked', function () {
    $token = reportStrikesPage('old-page-token');

    Http::fake(function (Request $request) use ($token) {
        SocialToken::query()->whereKey($token->getKey())->firstOrFail()->forceFill(['access_token' => 'new-page-token'])->save();

        return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400);
    });

    expect(reportStrikesReport($token))->toBeFalse()
        ->and($token->fresh()->access_token)->toBe('new-page-token')
        ->and($token->fresh()->failed_checks)->toBe(0);
});
