<?php

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreAccountFromSocialite;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Events\AccountConnected;
use Pr4w\SocialTokens\Events\AccountNeedsReconnect;
use Pr4w\SocialTokens\Events\AccountRevoked;
use Pr4w\SocialTokens\Events\CredentialDataAccessExpiring;
use Pr4w\SocialTokens\Events\CredentialExpiringSoon;
use Pr4w\SocialTokens\Events\CredentialNeedsReconnect;
use Pr4w\SocialTokens\Events\CredentialRenewed;
use Pr4w\SocialTokens\Events\CredentialRevoked;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * A queued listener (ShouldQueue) gets the event PHP-serialised into the
 * queue: Redis, the jobs table, Horizon, and failed_jobs for good when the
 * listener fails. Without SerializesModels the whole model went in, with
 * every loaded relation: the app's User model passed as owner (password
 * hash, remember_token, email) and the credential's token ciphertext. With
 * it, the payload holds class + key, and the listener re-reads the row.
 */

class EventSerializationQueuedListener implements ShouldQueue
{
    /** @var array<int, SocialAccount> */
    public static array $accounts = [];

    public function handle(AccountConnected $event): void
    {
        static::$accounts[] = $event->account;
    }
}

beforeEach(fn () => EventSerializationQueuedListener::$accounts = []);

function eventSerializationOwner(): Owner
{
    return Owner::create(['name' => 'OWNER-SECRET-FIELD']);
}

function eventSerializationCredential(): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'tiktok',
        'provider_holder_id' => 'tt-1',
        'access_token' => 'access-plain',
        'refresh_token' => 'refresh-plain',
        'expires_at' => now()->addDay(),
        'status' => AccountStatus::Active,
    ]);

    SocialAccount::create([
        'provider' => 'tiktok',
        'provider_user_id' => 'tt-1',
        'provider_holder_id' => 'tt-1',
        'social_token_id' => $token->getKey(),
        'email' => 'person@example.test',
        'status' => AccountStatus::Active,
    ]);

    return $token;
}

it('serialises every event with model identifiers, never the models', function (Closure $build) {
    $token = eventSerializationCredential();
    $account = $token->accounts()->first();
    $account->ownable()->associate(eventSerializationOwner());
    $account->setRelation('credential', $token);

    $event = $build($account, $token);
    $payload = serialize($event);

    expect(class_uses_recursive($event))->toContain(SerializesModels::class)
        ->and($payload)->toContain(ModelIdentifier::class)
        ->and($payload)->not->toContain('OWNER-SECRET-FIELD')
        ->and($payload)->not->toContain('person@example.test')
        ->and($payload)->not->toContain($token->getRawOriginal('access_token'))
        ->and($payload)->not->toContain($token->getRawOriginal('refresh_token'));
})->with([
    'AccountConnected' => fn ($account) => new AccountConnected($account),
    'AccountNeedsReconnect' => fn ($account) => new AccountNeedsReconnect($account, 'gone'),
    'AccountRevoked' => fn ($account) => new AccountRevoked($account),
    'CredentialDataAccessExpiring' => fn ($account, $token) => new CredentialDataAccessExpiring($token, now()->addDay()),
    'CredentialExpiringSoon' => fn ($account, $token) => new CredentialExpiringSoon($token, now()->addDay(), 'soon'),
    'CredentialNeedsReconnect' => fn ($account, $token) => new CredentialNeedsReconnect($token, 'gone'),
    'CredentialRenewed' => fn ($account, $token) => new CredentialRenewed($token),
    'CredentialRevoked' => fn ($account, $token) => new CredentialRevoked($token),
]);

it('restores the event with a fresh copy of the model and its relations', function () {
    $owner = eventSerializationOwner();
    $account = app(StoreAccountFromSocialite::class)->handle('tiktok', socialiteUser(['id' => 'tt-9']), owner: $owner, connectedBy: $owner);

    $restored = unserialize(serialize(new AccountConnected($account)));

    expect($restored->account->is($account))->toBeTrue()
        ->and($restored->account->relationLoaded('ownable'))->toBeTrue()
        ->and($restored->account->relationLoaded('connectedBy'))->toBeTrue()
        ->and($restored->account->ownable->is($owner))->toBeTrue()
        ->and($restored->account->connectedBy->is($owner))->toBeTrue();
});

it('runs a queued listener of a connection, which re-reads the account', function () {
    Event::listen(AccountConnected::class, EventSerializationQueuedListener::class);

    $owner = eventSerializationOwner();
    app(StoreAccountFromSocialite::class)->handle('tiktok', socialiteUser(['id' => 'tt-9']), owner: $owner, connectedBy: $owner);

    // The sync queue serialises the listener job too, then runs it.
    expect(EventSerializationQueuedListener::$accounts)->toHaveCount(1)
        ->and(EventSerializationQueuedListener::$accounts[0]->connectedBy->is($owner))->toBeTrue();
});

it('hands a queued revocation listener the credential as it is now', function () {
    Http::fake();
    $token = eventSerializationCredential();
    $event = new CredentialRevoked($token);
    $payload = serialize($event);

    app(SocialTokens::class)->revoke($token->fresh());

    expect(unserialize($payload)->token->status)->toBe(AccountStatus::Revoked);
});
