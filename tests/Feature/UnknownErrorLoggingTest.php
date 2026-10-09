<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;

/*
 * What log_unknown_errors writes, and what the published config says it
 * writes. Since 1.2 the logged context never holds the response body (it may
 * carry tokens); the config comment, which every app copies, must not promise
 * the "raw payload" and invite someone to put it back.
 */

it('logs an uncatalogued renewal error without the response body', function () {
    Http::fake(['open.tiktokapis.com/*' => Http::response([
        'refresh_token' => 'leaked-refresh-token-9b1c', // no access_token: malformed
        'open_id' => 'tt-1',
    ])]);

    Log::spy();

    $token = SocialToken::create([
        'provider' => 'tiktok',
        'provider_holder_id' => 'tt-1',
        'access_token' => 'old',
        'refresh_token' => 'refresh',
        'expires_at' => now()->subMinute(),
        'renew_at' => now()->subHour(),
        'status' => AccountStatus::Active,
    ]);

    app(SocialTokens::class)->renewCredential($token);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) {
            return $message === '[social-tokens] Uncatalogued renewal error'
                && $context['context']['keys'] === ['refresh_token', 'open_id']
                && ! str_contains(json_encode($context), 'leaked-refresh-token-9b1c');
        })
        ->once();
});

it('describes the logged context as redacted in the published config', function () {
    // The comment text with its line breaks and `|` gutters folded away.
    $config = preg_replace('/\s*\n\s*\|\s*/', ' ', file_get_contents(__DIR__.'/../../config/social-tokens.php'));

    expect($config)->not->toContain('raw payload')
        ->and($config)->toContain('The response body is never logged');
});
