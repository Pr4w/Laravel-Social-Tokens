<?php

use Pr4w\SocialTokens\Connectors\MetaErrorMapper;
use Pr4w\SocialTokens\Enums\RenewalOutcome;

it('maps an OAuthException to terminal', function () {
    $result = MetaErrorMapper::map([
        'type' => 'OAuthException',
        'code' => 190,
        'message' => 'Token expired',
    ]);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->reason)->toContain('Token expired');
});

it('maps known terminal codes to terminal', function (int $code) {
    $result = MetaErrorMapper::map(['type' => 'SomeError', 'code' => $code, 'message' => 'nope']);

    expect($result->outcome)->toBe(RenewalOutcome::Terminal);
})->with([190, 102, 10, 200]);

it('maps an unrecognised error to unknown/transient with context', function () {
    $result = MetaErrorMapper::map([
        'type' => 'TransientError',
        'code' => 2,
        'message' => 'Please retry',
    ]);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->unknown)->toBeTrue()
        ->and($result->context)->toBe(['code' => 2, 'type' => 'TransientError', 'message' => 'Please retry']);
});

it('tolerates a missing message', function () {
    $result = MetaErrorMapper::map(['code' => 4, 'type' => 'Throttle']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->reason)->toContain('Unknown Meta error');
});
