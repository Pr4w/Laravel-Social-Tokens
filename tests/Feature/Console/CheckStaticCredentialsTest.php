<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

/** A static credential (no expiry, no renewal) backing one account. */
function staticCredential(string $provider = 'facebook', array $attrs = [], bool $withAccount = true): SocialToken
{
    $token = SocialToken::create(array_merge([
        'provider' => $provider,
        'provider_holder_id' => 'page-'.uniqid(),
        'access_token' => 'page-token',
        'status' => AccountStatus::Active,
    ], $attrs));

    if ($withAccount) {
        SocialAccount::create(['provider' => $provider, 'provider_user_id' => 'a-'.uniqid(), 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);
    }

    return $token;
}

it('flags a static page token Meta no longer accepts', function () {
    Event::fake([CredentialNeedsReconnect::class]);
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['error' => [
        'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460,
        'message' => 'The session has been invalidated because the user changed their password.',
    ]], 400)]);
    $token = staticCredential();

    $this->artisan('social-tokens:check-static')
        ->expectsOutputToContain('Checked 1 static credential(s), flagged 1.')
        ->assertSuccessful();

    expect($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($token->fresh()->last_error)->toContain('changed their password');

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('leaves a working static token alone', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'page-1'])]);
    $token = staticCredential();

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 1 static credential(s), flagged 0.');

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer page-token'));
});

it('does not flag on a transient error', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['error' => ['type' => 'OAuthException', 'code' => 4, 'message' => 'rate limited']], 400)]);
    $token = staticCredential();

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('flagged 0.');

    expect($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('only checks active static credentials that back an account, on providers that can check', function () {
    Http::fake(['graph.facebook.com/*/me*' => Http::response(['id' => 'x'])]);

    staticCredential('facebook', ['expires_at' => now()->addDays(30), 'renew_at' => now()->addDays(23)]); // renewable
    staticCredential('facebook', ['status' => AccountStatus::NeedsReconnect]);                             // already flagged
    staticCredential('facebook', withAccount: false);                                                      // orphan
    staticCredential('fake');                                                                              // connector cannot check

    $this->artisan('social-tokens:check-static')->expectsOutputToContain('Checked 0 static credential(s), flagged 0.');

    Http::assertNothingSent();
});
