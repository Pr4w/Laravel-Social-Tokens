<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

/**
 * The user reconnects (a new grant, new scopes) while a renewal of the old
 * token is in flight: the reconnect is written between the lock's re-read and
 * the provider's reply. The renewal must not undo it.
 */
beforeEach(fn () => FakeConnector::reset());

function reconnectTikTok(string $access, string $refresh, array $scopes): SocialAccount
{
    return app(StoreAccountFromSocialite::class)->handle('tiktok', socialiteUser([
        'id' => 'tt-1',
        'token' => $access,
        'refreshToken' => $refresh,
        'expiresIn' => 86400,
        'approvedScopes' => $scopes,
        'accessTokenResponseBody' => ['refresh_expires_in' => 31536000],
    ]));
}

/** A TikTok credential whose renewal window has opened, or that has expired. */
function reconnectDueCredential(bool $expired = false): SocialToken
{
    $account = reconnectTikTok('A1', 'R1', ['user.info.basic']);
    $token = $account->credential;
    $token->update($expired
        ? ['expires_at' => now()->subMinute(), 'renew_at' => now()->subHours(2)]
        : ['renew_at' => now()->subMinute()]);

    return $token;
}

/** TikTok answers $response, after the reconnect has landed. */
function reconnectWhileRefreshing(array $response, int $status = 200): void
{
    Http::fake(['open.tiktokapis.com/v2/oauth/token/*' => function () use ($response, $status) {
        reconnectTikTok('A2', 'R2', ['user.info.basic', 'video.publish']);

        return Http::response($response, $status);
    }]);
}

function reconnectRunJob(SocialToken $token): void
{
    (new RenewCredential($token))->handle(app(SocialTokens::class), app(ConnectorRegistry::class));
}

it('keeps the reconnected tokens when the renewal of the old ones succeeds', function () {
    $token = reconnectDueCredential();
    Event::fake([CredentialRenewed::class]);

    reconnectWhileRefreshing([
        'access_token' => 'A1b', 'refresh_token' => 'R1b', 'expires_in' => 86400,
        'refresh_expires_in' => 31536000, 'scope' => 'user.info.basic', 'open_id' => 'tt-1',
    ]);

    reconnectRunJob($token);

    $fresh = $token->fresh();

    expect($fresh->access_token)->toBe('A2')
        ->and($fresh->refresh_token)->toBe('R2')
        ->and($fresh->scopes)->toBe(['user.info.basic', 'video.publish'])
        ->and($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->renew_at->isFuture())->toBeTrue();

    Event::assertNotDispatched(CredentialRenewed::class);
});

it('does not flag the reconnected credential when the old refresh token is refused', function () {
    $token = reconnectDueCredential();
    Event::fake([CredentialNeedsReconnect::class]);

    reconnectWhileRefreshing(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'], 400);

    reconnectRunJob($token);

    $fresh = $token->fresh();

    expect($fresh->status)->toBe(AccountStatus::Active)
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->access_token)->toBe('A2')
        ->and($fresh->refresh_token)->toBe('R2');

    Event::assertNotDispatched(CredentialNeedsReconnect::class);
});

it('hands a synchronous caller the reconnected token, not the one renewed from the old grant', function () {
    $token = reconnectDueCredential(expired: true);
    $account = SocialAccount::query()->where('social_token_id', $token->getKey())->sole();

    reconnectWhileRefreshing([
        'access_token' => 'A1b', 'refresh_token' => 'R1b', 'expires_in' => 86400,
        'refresh_expires_in' => 31536000, 'open_id' => 'tt-1',
    ]);

    expect(app(SocialTokens::class)->validAccessTokenFor($account))->toBe('A2')
        ->and($token->fresh()->refresh_token)->toBe('R2');
});

it('tells a synchronous caller to retry, not to reconnect, when the refused token was replaced', function () {
    $token = reconnectDueCredential(expired: true);
    $account = SocialAccount::query()->where('social_token_id', $token->getKey())->sole();
    Event::fake([CredentialNeedsReconnect::class]);

    reconnectWhileRefreshing(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'], 400);

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeTrue()
            ->and($token->fresh()->status)->toBe(AccountStatus::Active);
    }

    Event::assertNotDispatched(CredentialNeedsReconnect::class);

    // The retry gets the reconnected token without asking TikTok again.
    expect(app(SocialTokens::class)->validAccessTokenFor($account))->toBe('A2');
    Http::assertSentCount(1);
});

it('still flags the credential when the refused token is the one it holds', function () {
    $token = reconnectDueCredential(expired: true);
    $account = SocialAccount::query()->where('social_token_id', $token->getKey())->sole();
    Event::fake([CredentialNeedsReconnect::class]);

    Http::fake(['open.tiktokapis.com/v2/oauth/token/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'], 400)]);

    try {
        app(SocialTokens::class)->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeFalse()
            ->and($token->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
    }

    Event::assertDispatchedTimes(CredentialNeedsReconnect::class, 1);
});

it('drops a renewal applied from a copy whose tokens were replaced since it was loaded', function () {
    $token = reconnectDueCredential();
    $stale = SocialToken::findOrFail($token->getKey());
    reconnectTikTok('A2', 'R2', ['user.info.basic', 'video.publish']);

    $stored = $stale->tryApplyRenewal(
        RenewalResult::success(accessToken: 'A1b', expiresAt: now()->addDay(), refreshToken: 'R1b'),
        app(ConnectorRegistry::class)->for('tiktok'),
    );

    expect($stored)->toBeFalse()
        ->and($stale->access_token)->toBe('A2') // the copy now shows the stored credential
        ->and($token->fresh()->access_token)->toBe('A2')
        ->and($token->fresh()->refresh_token)->toBe('R2');
});

it('applies a renewal, and says so, when the credential still holds the tokens it was loaded with', function () {
    $token = reconnectDueCredential();
    $token->update(['status' => AccountStatus::NeedsReconnect, 'last_error' => 'flagged meanwhile']);
    $loaded = SocialToken::findOrFail($token->getKey());
    Event::fake([CredentialRenewed::class]);

    $stored = $loaded->tryApplyRenewal(
        RenewalResult::success(accessToken: 'A1b', expiresAt: now()->addDay(), refreshToken: 'R1b'),
        app(ConnectorRegistry::class)->for('tiktok'),
    );

    $fresh = $token->fresh();

    expect($stored)->toBeTrue()
        ->and($fresh->access_token)->toBe('A1b')
        ->and($fresh->refresh_token)->toBe('R1b')
        ->and($fresh->status)->toBe(AccountStatus::Active) // a working refresh token proves the credential works
        ->and($fresh->last_error)->toBeNull();

    Event::assertDispatchedTimes(CredentialRenewed::class, 1);
});
