<?php

use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalStrategy;

it('treats only active as usable', function () {
    expect(AccountStatus::Active->isUsable())->toBeTrue()
        ->and(AccountStatus::NeedsReconnect->isUsable())->toBeFalse()
        ->and(AccountStatus::Revoked->isUsable())->toBeFalse();
});

it('has no expiring status', function () {
    expect(collect(AccountStatus::cases())->map->value->all())
        ->toBe(['active', 'needs_reconnect', 'revoked']);
});

it('knows which strategies renew unattended', function () {
    expect(RenewalStrategy::RotatingRefreshToken->canRenewUnattended())->toBeTrue()
        ->and(RenewalStrategy::StableRefreshToken->canRenewUnattended())->toBeTrue()
        ->and(RenewalStrategy::ExtendLongLived->canRenewUnattended())->toBeTrue()
        ->and(RenewalStrategy::ReauthOnly->canRenewUnattended())->toBeFalse();
});
