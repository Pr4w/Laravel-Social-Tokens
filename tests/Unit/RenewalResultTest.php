<?php

use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Support\RenewalResult;

it('builds a success result and reports succeeded', function () {
    $expiresAt = now()->addHour();
    $refreshExpiresAt = now()->addDays(30);

    $result = RenewalResult::success(
        accessToken: 'a-token',
        expiresAt: $expiresAt,
        refreshToken: 'r-token',
        refreshExpiresAt: $refreshExpiresAt,
        profile: ['open_id' => 'x'],
    );

    expect($result->outcome)->toBe(RenewalOutcome::Success)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->accessToken)->toBe('a-token')
        ->and($result->refreshToken)->toBe('r-token')
        ->and($result->expiresAt)->toBe($expiresAt)
        ->and($result->refreshExpiresAt)->toBe($refreshExpiresAt)
        ->and($result->profile)->toBe(['open_id' => 'x'])
        ->and($result->unknown)->toBeFalse()
        ->and($result->reason)->toBeNull();
});

it('builds a transient failure', function () {
    $result = RenewalResult::transientFailure('network');

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->reason)->toBe('network')
        ->and($result->unknown)->toBeFalse();
});

it('builds a terminal failure', function () {
    $result = RenewalResult::terminalFailure('revoked');

    expect($result->outcome)->toBe(RenewalOutcome::Terminal)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->reason)->toBe('revoked');
});

it('builds an unknown failure that behaves as transient but is flagged', function () {
    $result = RenewalResult::unknownFailure('weird', ['error' => 'weird']);

    expect($result->outcome)->toBe(RenewalOutcome::Transient)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->unknown)->toBeTrue()
        ->and($result->reason)->toBe('weird')
        ->and($result->context)->toBe(['error' => 'weird']);
});

it('defaults optional success fields', function () {
    $result = RenewalResult::success(accessToken: 'a');

    expect($result->refreshToken)->toBeNull()
        ->and($result->expiresAt)->toBeNull()
        ->and($result->refreshExpiresAt)->toBeNull()
        ->and($result->profile)->toBe([]);
});
