<?php

use Pr4w\SocialTokens\Connectors\MetaErrorMapper;
use Pr4w\SocialTokens\Enums\RenewalOutcome;

it('maps an invalid token to terminal', function () {
    $result = MetaErrorMapper::map([
        'type' => 'OAuthException',
        'code' => 190,
        'message' => 'Token expired',
    ]);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('Token expired');
});

it('maps token and session errors to terminal', function (array $error) {
    expect(MetaErrorMapper::map($error + ['message' => 'nope'])->outcome)->toBe(RenewalOutcome::Terminal);
})->with([
    'invalid token' => [['type' => 'OAuthException', 'code' => 190]],
    'invalid token, session subcode' => [['type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460]],
    'session key invalid' => [['code' => 102]],
    'session subcode on another code' => [['code' => 100, 'error_subcode' => 463]],
]);

it('maps a lost permission to terminal and says so', function (int $code) {
    $result = MetaErrorMapper::map(['type' => 'OAuthException', 'code' => $code, 'message' => 'Requires pages_manage_posts']);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('permission');
})->with([10, 200, 299]);

it('maps rate limits and temporary errors to transient, whatever their type', function (array $error) {
    $result = MetaErrorMapper::map($error + ['message' => 'slow down']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeFalse();
})->with([
    'app rate limit sent as OAuthException' => [['type' => 'OAuthException', 'code' => 4]],
    'user rate limit' => [['type' => 'OAuthException', 'code' => 17]],
    'page rate limit' => [['code' => 32]],
    'custom rate limit' => [['code' => 613]],
    'business use case limit' => [['type' => 'OAuthException', 'code' => 80001]],
    'last business use case limit' => [['code' => 80014]],
    'unknown error' => [['code' => 1]],
    'service unavailable' => [['code' => 2]],
    'flagged transient by meta' => [['code' => 999, 'is_transient' => true]],
]);

it('no longer treats OAuthException alone as terminal', function () {
    $result = MetaErrorMapper::map(['type' => 'OAuthException', 'code' => 100, 'message' => 'Invalid parameter']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue();
});

it('maps an unrecognised error to unknown/transient with context', function () {
    $result = MetaErrorMapper::map([
        'type' => 'GraphMethodException',
        'code' => 100,
        'error_subcode' => 33,
        'message' => 'Unsupported get request',
        'fbtrace_id' => 'AbC123',
    ]);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue()
        ->and($result->context)->toBe([
            'code' => 100,
            'error_subcode' => 33,
            'type' => 'GraphMethodException',
            'message' => 'Unsupported get request',
            'fbtrace_id' => 'AbC123',
        ]);
});

it('tolerates a missing message', function () {
    $result = MetaErrorMapper::map(['code' => 4, 'type' => 'Throttle']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->toContain('Unknown Meta error');
});
