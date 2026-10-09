<?php

/*
 * 1.2.0, a minor release, changed behaviour 1.0 and 1.1 apps relied on and
 * came with one-off steps (secret rotation, data fixes) written only in the
 * changelog, which the dist archive left out. UPGRADE.md's "From 1.x to 2.0",
 * where the README sends every 1.x user, did not mention them. The guide now
 * carries them, the changelog ships, and the snippets it gives run against
 * the current schema.
 */

use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;

function upgradeGuide(): string
{
    return (string) file_get_contents(__DIR__.'/../../UPGRADE.md');
}

/** The text between $heading and the next heading of the same level or above. */
function upgradeSection(string $heading): string
{
    $guide = upgradeGuide();
    $start = strpos($guide, "\n{$heading}\n");

    expect($start)->not->toBeFalse("UPGRADE.md has no [{$heading}] section.");

    $level = strlen(strstr($heading, ' ', true));
    preg_match('/\n#{1,'.$level.'} /', $guide, $next, PREG_OFFSET_CAPTURE, $start + strlen($heading) + 2);

    return substr($guide, $start, ($next[0][1] ?? strlen($guide)) - $start);
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
    $section = upgradeSection('### Coming from 1.0 or 1.1');

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

    expect(upgradeSection('## From 2.x to 3.0'))->toContain('[Coming from 1.0 or 1.1](#coming-from-10-or-11)')
        ->and(upgradeSection('### 4. Behaviour changes to know'))->not->toContain('`validAccessTokenFor()` are unchanged.');
});

it('redacts the secrets earlier versions left in last_error', function () {
    $credential = SocialToken::factory()->needsReconnect(
        'Connection error: cURL error 28 for https://graph.facebook.com/oauth/access_token?client_secret=s3cret&fb_exchange_token=t0ken'
    )->create();
    $account = SocialAccount::factory()->needsReconnect('Page no longer managed by the connected Facebook user.')->create();

    // UPGRADE.md, "Coming from 1.0 or 1.1".
    foreach ([SocialToken::class, SocialAccount::class] as $model) {
        $model::query()->whereNotNull('last_error')->each(fn ($row) => $row
            ->forceFill(['last_error' => AbstractConnector::redactQueryStrings($row->last_error)])
            ->saveQuietly());
    }

    expect($credential->fresh()->last_error)->toBe('Connection error: cURL error 28 for https://graph.facebook.com/oauth/access_token?[redacted]')
        ->and($account->fresh()->last_error)->toBe('Page no longer managed by the connected Facebook user.');
});

it('reactivates the credentials 1.1 flagged for an app-side error', function () {
    $client = SocialToken::factory()->forProvider('tiktok')->needsReconnect('invalid_client: Client key or secret is incorrect.')->create();
    $deadRefresh = SocialToken::factory()->forProvider('linkedin')->needsReconnect('invalid_request: The provided refresh token is invalid')->create();
    $deadGrant = SocialToken::factory()->forProvider('google')->needsReconnect('invalid_grant: Token has been expired or revoked.')->create();

    // UPGRADE.md, "Coming from 1.0 or 1.1".
    SocialToken::query()->where('status', AccountStatus::NeedsReconnect->value)
        ->where(fn ($query) => $query->where('last_error', 'like', 'invalid_client:%')
            ->orWhere('last_error', 'like', 'unauthorized_client:%')
            ->orWhere('last_error', 'like', 'invalid_request:%'))
        ->each(function (SocialToken $token) {
            if (! preg_match('/refresh[\s_-]?token/i', (string) $token->last_error)) {
                $token->forceFill(['status' => AccountStatus::Active, 'last_error' => null, 'renew_at' => now()])->save();
            }
        });

    expect($client->fresh()->status)->toBe(AccountStatus::Active)
        ->and($client->fresh()->isDueForRenewal())->toBeTrue()
        ->and($deadRefresh->fresh()->status)->toBe(AccountStatus::NeedsReconnect)
        ->and($deadGrant->fresh()->status)->toBe(AccountStatus::NeedsReconnect);
});
