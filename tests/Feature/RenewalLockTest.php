<?php

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Enums\RenewalStrategy;
use Pr4w\SocialTokens\Exceptions\NeedsReconnectException;
use Pr4w\SocialTokens\Exceptions\TransientRenewalException;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

/**
 * The per-credential renewal lock is what keeps a scheduled job and a
 * synchronous validAccessTokenFor() from spending the same single-use refresh
 * token (TikTok, X) twice. These tests hold the real lock: time is frozen and
 * the faked Sleep moves it, so the 10-second wait for the lock takes no real
 * time.
 */
beforeEach(function () {
    FakeConnector::reset();
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $this->tokens = app(SocialTokens::class);
});

function lockedCredential(array $attrs = []): SocialToken
{
    return SocialToken::create(array_merge([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'current-token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->subMinute(), // expired
    ], $attrs));
}

function lockedAccount(SocialToken $token): SocialAccount
{
    return SocialAccount::create([
        'provider' => 'fake',
        'provider_user_id' => 'a-'.uniqid(),
        'social_token_id' => $token->getKey(),
        'status' => AccountStatus::Active,
    ]);
}

/** Another process renewing this credential right now. */
function holdRenewalLock(SocialToken $token): Lock
{
    $lock = Cache::lock("social-tokens:renew:{$token->getKey()}", 60);

    expect($lock->get())->toBeTrue();

    return $lock;
}

// The lock -------------------------------------------------------------------

it('never calls the provider while another process holds the credential lock', function () {
    $token = lockedCredential();
    holdRenewalLock($token);

    $result = $this->tokens->renewCredential($token);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->toBe('Could not acquire renewal lock.')
        ->and(FakeConnector::$renewCalls)->toBe(0)
        ->and($token->fresh()->access_token)->toBe('current-token')
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);

    Sleep::assertSlept(fn () => true, 39); // waited out the 10 seconds, 250 ms at a time
});

it('tells a synchronous caller to retry, not to reconnect, while the lock is held', function () {
    $token = lockedCredential();
    holdRenewalLock($token);

    try {
        $this->tokens->validAccessTokenFor(lockedAccount($token));
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeTrue()
            ->and(FakeConnector::$renewCalls)->toBe(0)
            ->and($token->fresh()->status)->toBe(AccountStatus::Active);
    }
});

it('retries the job, without flagging the credential, while the lock is held', function () {
    $token = lockedCredential();
    holdRenewalLock($token);

    expect(fn () => (new RenewCredential($token))->handle($this->tokens, app(ConnectorRegistry::class)))
        ->toThrow(TransientRenewalException::class);

    expect(FakeConnector::$renewCalls)->toBe(0)
        ->and($token->fresh()->status)->toBe(AccountStatus::Active);
});

it('locks each credential on its own', function () {
    $busy = lockedCredential();
    $other = lockedCredential();
    holdRenewalLock($busy);

    $result = $this->tokens->renewCredential($other);

    expect($result->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(1)
        ->and($other->fresh()->access_token)->toBe('renewed-token');

    Sleep::assertNeverSlept();
});

it('calls the provider while holding the credential lock, and releases it after', function () {
    $token = lockedCredential();
    $heldDuringCall = null;

    FakeConnector::$resultUsing = function (SocialToken $token) use (&$heldDuringCall) {
        $heldDuringCall = ! Cache::lock("social-tokens:renew:{$token->getKey()}", 60)->get();

        return RenewalResult::success(accessToken: 'renewed-token', expiresAt: now()->addHour());
    };

    $this->tokens->renewCredential($token);

    expect($heldDuringCall)->toBeTrue()
        ->and(Cache::lock("social-tokens:renew:{$token->getKey()}", 60)->get())->toBeTrue();
});

it('waits for a renewal in flight and hands out its token instead of refreshing again', function () {
    $token = lockedCredential(['expires_at' => now()->addDay(), 'renew_at' => now()->subMinute()]);
    $lock = holdRenewalLock($token);

    // The other process stores its renewal and releases the lock while this
    // one waits for it.
    Sleep::whenFakingSleep(function () use ($token, $lock) {
        if ($lock->release()) {
            SocialToken::findOrFail($token->getKey())->update([
                'access_token' => 'renewed-elsewhere',
                'refresh_token' => 'rotated-elsewhere',
                'expires_at' => now()->addDay(),
                'renew_at' => now()->addHours(22),
            ]);
        }
    });

    $result = $this->tokens->renewCredential($token);

    expect($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('renewed-elsewhere')
        ->and(FakeConnector::$renewCalls)->toBe(0) // the rotated refresh token is never sent twice
        ->and($token->fresh()->refresh_token)->toBe('rotated-elsewhere');
});

// Reading the credential under the lock -------------------------------------

it('re-reads the credential under the lock with a locking read', function () {
    $token = lockedCredential();
    $connection = DB::connection();

    // SQLite has no SELECT ... FOR UPDATE: record what the query asked for.
    $grammar = new class($connection) extends SQLiteGrammar
    {
        /** @var list<string> */
        public array $lockedReads = [];

        protected function compileLock(Builder $query, $value)
        {
            if ($value === true && is_string($query->from)) {
                $this->lockedReads[] = $query->from;
            }

            return parent::compileLock($query, $value);
        }
    };

    $connection->setQueryGrammar($grammar);

    FakeConnector::$resultUsing = function () use ($grammar) {
        expect($grammar->lockedReads)->toBe(['social_tokens']); // before the provider call

        return RenewalResult::success(accessToken: 'renewed-token', expiresAt: now()->addHour());
    };

    expect($this->tokens->renewCredential($token)->succeeded())->toBeTrue()
        ->and(FakeConnector::$renewCalls)->toBe(1);
});

it('treats a lock wait on the credential row as transient', function () {
    $token = lockedCredential();
    $thrown = false;

    DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$thrown) {
        if (! $thrown && str_starts_with($sql, 'select') && str_contains($sql, '"social_tokens"')) {
            $thrown = true;

            throw new QueryException('testing', $sql, $bindings, new PDOException(
                'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'
            ));
        }
    });

    $result = $this->tokens->renewCredential($token);

    expect($thrown)->toBeTrue()
        ->and($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and(FakeConnector::$renewCalls)->toBe(0)
        ->and($token->fresh()->access_token)->toBe('current-token');
});

it('gives up, rather than refresh, after waiting long on the credential row', function () {
    $token = lockedCredential();
    $waited = false;

    // Another transaction held the row for 31 seconds: the 60-second renewal
    // lock might lapse before the provider answers.
    DB::connection()->beforeExecuting(function (string $sql) use (&$waited) {
        if (! $waited && str_starts_with($sql, 'select') && str_contains($sql, '"social_tokens"')) {
            $waited = true;
            Carbon::setTestNow(now()->addSeconds(31));
        }
    });

    $result = $this->tokens->renewCredential($token);

    expect($waited)->toBeTrue()
        ->and($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and(FakeConnector::$renewCalls)->toBe(0)
        ->and($token->fresh()->access_token)->toBe('current-token');
});

it('lets any other database error through', function () {
    $token = lockedCredential();

    DB::connection()->beforeExecuting(function (string $sql, array $bindings) {
        if (str_starts_with($sql, 'select') && str_contains($sql, '"social_tokens"')) {
            throw new QueryException('testing', $sql, $bindings, new PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
        }
    });

    $this->tokens->renewCredential($token);
})->throws(QueryException::class, 'disk I/O error');

it('warns when it renews a single-use refresh token inside a database transaction', function () {
    Log::spy();
    $token = lockedCredential(); // FakeConnector rotates its refresh token by default

    DB::transaction(fn () => $this->tokens->renewCredential($token));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'inside a database transaction')
        && $context['provider'] === 'fake'
        && $context['token_id'] === $token->getKey()
    )->once();
});

it('does not warn outside a transaction, without a provider call, or for a refresh token that is kept', function (string $case) {
    Log::spy();

    match ($case) {
        'outside a transaction' => $this->tokens->renewCredential(lockedCredential()),
        'already renewed' => DB::transaction(fn () => $this->tokens->renewCredential(lockedCredential(['expires_at' => now()->addDay()]))),
        'stable refresh token' => DB::transaction(function () {
            FakeConnector::$strategy = RenewalStrategy::StableRefreshToken;

            return $this->tokens->renewCredential(lockedCredential());
        }),
    };

    Log::shouldNotHaveReceived('warning');
})->with(['outside a transaction', 'already renewed', 'stable refresh token']);

// Other guarantees around the renewal ----------------------------------------

it('skips the provider for the other credentials once it rejected the app client', function () {
    FakeConnector::$nextResult = RenewalResult::clientFailure('invalid_client');
    $credentials = [lockedCredential(), lockedCredential(), lockedCredential()];

    foreach ($credentials as $credential) {
        (new RenewCredential($credential))->handle($this->tokens, app(ConnectorRegistry::class));
    }

    expect(FakeConnector::$renewCalls)->toBe(1);

    foreach ($credentials as $credential) {
        expect($credential->fresh()->status)->toBe(AccountStatus::Active);
    }
});

it('asks for a reconnect, without renewing, for an account deleted since it was loaded', function () {
    $token = lockedCredential();
    $account = lockedAccount($token);
    SocialAccount::query()->whereKey($account->getKey())->delete();

    try {
        $this->tokens->validAccessTokenFor($account);
        $this->fail('expected NeedsReconnectException');
    } catch (NeedsReconnectException $e) {
        expect($e->transient)->toBeFalse()
            ->and($e->account->is($account))->toBeTrue()
            ->and(FakeConnector::$renewCalls)->toBe(0);
    }
});
