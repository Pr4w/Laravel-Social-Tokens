<?php

/*
 * 1.2.0, a minor release, changed behaviour 1.0 and 1.1 apps relied on and
 * came with one-off steps (secret rotation, data fixes) written only in the
 * changelog, which the dist archive left out. UPGRADE.md's "From 1.x to 2.0",
 * where the README sends every 1.x user, did not mention them. The guide now
 * carries them, the changelog ships, and the snippets it gives run against
 * the current schema.
 */

use Illuminate\Support\Facades\DB;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Tests\Fixtures\UpgradeGuide;

const FROM_ONE = '### Coming from 1.0 or 1.1';

/**
 * Insert credentials 1.1 flagged for an app-side error, past the thousand
 * rows Laravel's each() pages by.
 */
function flaggedForClientError(int $count): void
{
    $rows = array_map(fn (int $i) => [
        'provider' => 'tiktok',
        'provider_holder_id' => "tt-{$i}",
        'purpose' => SocialToken::DEFAULT_PURPOSE,
        'status' => AccountStatus::NeedsReconnect->value,
        'last_error' => 'invalid_client: Client key or secret is incorrect.',
        'created_at' => now(),
        'updated_at' => now(),
    ], range(1, $count));

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table((new SocialToken)->getTable())->insert($chunk);
    }
}

it('ships the changelog and the upgrade guide in the dist archive', function () {
    $ignored = collect(file(__DIR__.'/../../.gitattributes', FILE_IGNORE_NEW_LINES))
        ->filter(fn (string $line) => str_contains($line, 'export-ignore'))
        ->map(fn (string $line) => ltrim(strtok($line, " \t"), '/'))
        ->all();

    foreach (['README.md', 'UPGRADE.md', 'CHANGELOG.md', 'LICENSE'] as $file) {
        expect($ignored)->not->toContain($file);
    }
});

it('walks 1.0 and 1.1 apps through the 1.2 changes', function () {
    $section = UpgradeGuide::section(FROM_ONE);

    foreach ([
        'Rotate your Meta and Threads app secrets',
        'redactQueryStrings',
        'InvalidArgumentException',
        'disconnect($account)',
        '$minValiditySeconds',
        'no longer `save()`',
        'toArray()',
        "whereNull('expires_at')->whereNull('renew_at')",
        'refresh_expires_at',
        'invalid_client:',
        'provider_user_id = provider_holder_id',
        'CHANGELOG.md',
    ] as $step) {
        expect($section)->toContain($step);
    }

    expect(UpgradeGuide::section('## From 2.x to 3.0'))->toContain('[Coming from 1.0 or 1.1](#coming-from-10-or-11)')
        ->and(UpgradeGuide::section('### 4. Behaviour changes to know'))->not->toContain('`validAccessTokenFor()` are unchanged.');
});

it('redacts the secrets earlier versions left in last_error', function () {
    $credential = SocialToken::factory()->needsReconnect(
        'Connection error: cURL error 28 for https://graph.facebook.com/oauth/access_token?client_secret=s3cret&fb_exchange_token=t0ken'
    )->create();
    $account = SocialAccount::factory()->needsReconnect('Page no longer managed by the connected Facebook user.')->create();

    UpgradeGuide::run(FROM_ONE, 'redactQueryStrings');

    expect($credential->fresh()->last_error)->toBe('Connection error: cURL error 28 for https://graph.facebook.com/oauth/access_token?[redacted]')
        ->and($account->fresh()->last_error)->toBe('Page no longer managed by the connected Facebook user.');
});

it('reactivates the credentials 1.1 flagged for an app-side error', function () {
    $client = SocialToken::factory()->forProvider('tiktok')->needsReconnect('invalid_client: Client key or secret is incorrect.')->create();
    $deadRefresh = SocialToken::factory()->forProvider('linkedin')->needsReconnect('invalid_request: The provided refresh token is invalid')->create();
    $deadGrant = SocialToken::factory()->forProvider('google')->needsReconnect('invalid_grant: Token has been expired or revoked.')->create();

    UpgradeGuide::run(FROM_ONE, 'invalid_client:%');

    expect($client->fresh()->status)->toBe(AccountStatus::Active)
        ->and($client->fresh()->isDueForRenewal())->toBeTrue()
        ->and($deadRefresh->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($deadGrant->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});

it('reactivates every flagged credential, past the first thousand', function () {
    // each() pages by offset: rows reactivated in one page leave the query,
    // and the next offset skipped as many still flagged ones.
    flaggedForClientError(1050);

    UpgradeGuide::run(FROM_ONE, 'invalid_client:%');

    expect(SocialToken::where('status', AccountStatus::NeedsReconnect->value)->count())->toBe(0)
        ->and(SocialToken::where('status', AccountStatus::Active->value)->count())->toBe(1050);
});

it('redacts the stored reasons once 3.0 is deployed, not before', function () {
    $section = UpgradeGuide::section(FROM_ONE);
    $beforeDeploy = substr($section, strpos($section, '**Before you deploy:**'), strpos($section, '**What changed under your code:**') - strpos($section, '**Before you deploy:**'));
    $oneOff = substr($section, strpos($section, '**One-off data fixes'));

    // 1.0 and 1.1 have no redactQueryStrings(), and keep writing unredacted reasons until the deploy.
    expect($beforeDeploy)->toContain('Rotate your Meta and Threads app secrets')
        ->and($beforeDeploy)->not->toContain('redactQueryStrings')
        ->and($oneOff)->toContain('redactQueryStrings');
});

it('normalises the scopes stored before 1.2, keeping Meta\'s known-empty ones', function () {
    $none = SocialAccount::factory()->forProvider('facebook')->create(['scopes' => []]);
    $joined = SocialToken::factory()->forProvider('linkedin')->create(['scopes' => ['email,openid profile', 'w_member_social']]);
    $blank = SocialToken::factory()->forProvider('tiktok')->create(['scopes' => ['']]);
    $clean = SocialAccount::factory()->forProvider('tiktok')->create(['scopes' => ['video.publish']]);

    UpgradeGuide::run(FROM_ONE, 'normaliseScopes');

    expect($none->fresh()->scopes)->toBe([])
        ->and($none->fresh()->scopesKnown())->toBeTrue()
        ->and($joined->fresh()->scopes)->toBe(['email', 'openid', 'profile', 'w_member_social'])
        ->and($blank->fresh()->scopes)->toBeNull()
        ->and($clean->fresh()->scopes)->toBe(['video.publish']);
});

it('pages by id in every guide block that walks a table', function () {
    // A block that changes the rows its query selects skips some with each().
    preg_match_all('/```php\n(.*?)```/s', UpgradeGuide::text(), $blocks);

    foreach ($blocks[1] as $block) {
        expect($block)->not->toMatch('/\)\s*->each\((fn|function) \((SocialToken|SocialAccount|\$row)/');
    }
});
