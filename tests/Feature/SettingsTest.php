<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Jobs\RenewCredential;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\RenewalResult;
use Pr4w\SocialTokens\Support\Settings;
use Pr4w\SocialTokens\Tests\Fixtures\FakeConnector;

beforeEach(fn () => FakeConnector::reset());

/** A due credential backing one account. */
function settingsDueCredential(): SocialToken
{
    $token = SocialToken::create([
        'provider' => 'fake',
        'provider_holder_id' => 'h-'.uniqid(),
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'status' => AccountStatus::Active,
        'expires_at' => now()->addHour(),
        'renew_at' => now()->subMinute(),
    ]);

    SocialAccount::create(['provider' => 'fake', 'provider_user_id' => 'a-'.uniqid(), 'social_token_id' => $token->getKey(), 'status' => AccountStatus::Active]);

    return $token;
}

/** Drop a key from the merged config, as a published file without it would. */
function settingsForget(string $key): void
{
    $config = config('social-tokens');
    unset($config[$key]);
    config(['social-tokens' => $config]);
}

it('dispatches renewals with a single backoff value, the Laravel-standard form', function () {
    config(['social-tokens.queue.backoff' => 60]);
    Queue::fake();
    $token = settingsDueCredential();

    $this->artisan('social-tokens:dispatch-renewals')->assertSuccessful();

    Queue::assertPushed(RenewCredential::class, 1);

    $job = new RenewCredential($token);

    expect($job->backoff())->toBe([60])
        ->and($job->uniqueFor())->toBe(3 * 60 + 300); // every retry waits the one value
});

it('normalises the backoff to a list of seconds', function (mixed $value, array $expected) {
    config(['social-tokens.queue.backoff' => $value]);

    expect(Settings::backoff())->toBe($expected);
})->with([
    'int' => [60, [60]],
    'numeric string' => ['60', [60]],
    'comma-separated string' => ['60, 300,900', [60, 300, 900]],
    'strings' => [['60', '300'], [60, 300]],
    'keyed array' => [[1 => 30, 5 => 90], [30, 90]],
    'negative' => [[-5, 10], [0, 10]],
    'null' => [null, [60, 300, 900]],
]);

it('reads the number of tries as an int', function (mixed $value, int $expected) {
    config(['social-tokens.queue.tries' => $value]);

    expect(Settings::tries())->toBe($expected)
        ->and((new RenewCredential(settingsDueCredential()))->tries())->toBe($expected);
})->with([
    'int' => [3, 3],
    'numeric string' => ['5', 5],
    'null' => [null, 4],
]);

it('reads boolean settings written as strings', function (mixed $value, bool $expected) {
    config([
        'social-tokens.log_unknown_errors' => $value,
        'social-tokens.check_renewable' => $value,
    ]);

    expect(Settings::logUnknownErrors())->toBe($expected)
        ->and(Settings::checkRenewable())->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    '"true"' => ['true', true],
    '"false"' => ['false', false],
    '"1"' => ['1', true],
    '"0"' => ['0', false],
    '"off"' => ['off', false],
    'null' => [null, false],
]);

it('falls back to the shipped defaults for missing or unreadable booleans', function () {
    settingsForget('log_unknown_errors');
    settingsForget('check_renewable');

    expect(Settings::logUnknownErrors())->toBeTrue()
        ->and(Settings::checkRenewable())->toBeFalse();

    config(['social-tokens.log_unknown_errors' => 'maybe', 'social-tokens.check_renewable' => ['yes']]);

    expect(Settings::logUnknownErrors())->toBeTrue()
        ->and(Settings::checkRenewable())->toBeFalse();
});

it('does not log uncatalogued errors when log_unknown_errors is the string "false"', function () {
    Log::spy();
    config(['social-tokens.log_unknown_errors' => 'false']);
    FakeConnector::$nextResult = RenewalResult::unknownFailure('weird');

    app(SocialTokens::class)->renewCredential(settingsDueCredential());

    Log::shouldNotHaveReceived('error');
});

it('reads the provider timeouts as numbers', function () {
    config(['social-tokens.http.timeout' => '20', 'social-tokens.http.connect_timeout' => '2.5']);

    expect(Settings::httpTimeout())->toBe(20)
        ->and(Settings::connectTimeout())->toBe(2.5);

    config(['social-tokens.http' => []]);

    expect(Settings::httpTimeout())->toBe(15)
        ->and(Settings::connectTimeout())->toBe(5);
});

it('keeps the static-check breaker meaningful', function (mixed $breaker, array $expected) {
    config(['social-tokens.check_static_breaker' => $breaker]);

    expect(Settings::staticCheckBreaker())->toBe($expected);
})->with([
    'shipped' => [['min_checked' => 10, 'max_terminal_ratio' => 0.2], ['min_checked' => 10, 'max_terminal_ratio' => 0.2]],
    'strings' => [['min_checked' => '5', 'max_terminal_ratio' => '0.5'], ['min_checked' => 5, 'max_terminal_ratio' => 0.5]],
    'zero minimum' => [['min_checked' => 0], ['min_checked' => 1, 'max_terminal_ratio' => 0.2]],
    'negative minimum' => [['min_checked' => -3], ['min_checked' => 1, 'max_terminal_ratio' => 0.2]],
    'not an array' => [null, ['min_checked' => 10, 'max_terminal_ratio' => 0.2]],
]);

it('reads config changed at runtime', function () {
    config(['social-tokens.queue.backoff' => [10]]);
    expect(Settings::backoff())->toBe([10]);

    config(['social-tokens.queue.backoff' => [20]]);
    expect(Settings::backoff())->toBe([20]);
});
