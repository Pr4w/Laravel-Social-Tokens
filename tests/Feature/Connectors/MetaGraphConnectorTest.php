<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;
use Pr4w\SocialTokens\Support\RenewalResult;

/*
 * Facebook and Instagram (Facebook Login path) keep the same Meta user token
 * alive with the same fb_exchange_token call. They used to carry one copy
 * each, with their own Graph version default and their own reading of the
 * reply: a fix made to one copy (a missing expires_in, a reason) missed the
 * other.
 */

/** @return array<string, MetaGraphConnector> */
function metaGraphConnectors(array $config = []): array
{
    return [
        'facebook' => new FacebookConnector($config + ['credentials' => 'facebook'], 'facebook'),
        'instagram' => new InstagramConnector($config + ['credentials' => 'facebook'], 'instagram'),
    ];
}

/** The parts of a result that say what happened, without the moment it was built. */
function metaExchangeOutcome(RenewalResult $result): array
{
    return [
        'outcome' => $result->outcome,
        'access_token' => $result->accessToken,
        'expires_in' => $result->expiresAt === null ? null : (int) round(now()->diffInSeconds($result->expiresAt)),
        'reason' => $result->reason,
        'unknown' => $result->unknown,
        'client_error' => $result->clientError,
    ];
}

it('reads an fb_exchange_token reply the same way for Facebook and Instagram', function (array $reply, int $status) {
    $this->freezeTime();
    Http::fake(['graph.facebook.com/*' => Http::response($reply, $status)]);

    $connectors = metaGraphConnectors();

    $facebook = metaExchangeOutcome($connectors['facebook']->exchangeUserToken('user-token'));
    $instagram = metaExchangeOutcome($connectors['instagram']->exchangeForLongLived('user-token'));

    expect($instagram)->toBe($facebook);
})->with([
    'extended' => [['access_token' => 'long', 'expires_in' => 5183944], 200],
    'no expiry' => [['access_token' => 'long'], 200],
    'no token' => [['token_type' => 'bearer'], 200],
    'a Graph error' => [['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'bad']], 400],
    'a scalar body' => [['oops'], 200],
]);

it('sends one request for both, signed with the shared Meta app', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'long', 'expires_in' => 60])]);

    foreach (metaGraphConnectors() as $connector) {
        $connector->exchangeUserToken('user-token');
    }

    $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), $pair[0]->url()])->unique()->values();

    expect($sent)->toHaveCount(1)
        ->and($sent[0][0])->toBe('GET')
        ->and($sent[0][1])->toStartWith('https://graph.facebook.com/'.MetaGraphConnector::DEFAULT_GRAPH_VERSION.'/oauth/access_token?');

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'fb_exchange_token'
        && $request['client_id'] === 'facebook-id'
        && $request['client_secret'] === 'facebook-secret'
        && $request['fb_exchange_token'] === 'user-token');
});

it('has one Graph version default, and each block can set its own', function () {
    expect(collect(metaGraphConnectors())->map->graphVersion()->unique()->all())->toBe(['facebook' => MetaGraphConnector::DEFAULT_GRAPH_VERSION])
        ->and(collect(metaGraphConnectors(['graph_version' => 'v99.0']))->map->graphVersion()->unique()->all())->toBe(['facebook' => 'v99.0'])
        ->and(collect(metaGraphConnectors(['graph_version' => '']))->map->graphVersion()->unique()->all())->toBe(['facebook' => MetaGraphConnector::DEFAULT_GRAPH_VERSION])
        ->and(config('social-tokens.connectors.facebook.graph_version'))->toBe(MetaGraphConnector::DEFAULT_GRAPH_VERSION)
        ->and(config('social-tokens.connectors.instagram.graph_version'))->toBe(MetaGraphConnector::DEFAULT_GRAPH_VERSION);
});

it('calls every Graph endpoint of the Facebook connector on its configured version', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [], 'id' => 'user-1', 'access_token' => 'long'])]);

    $facebook = metaGraphConnectors(['graph_version' => 'v99.0'])['facebook'];

    $facebook->exchangeUserToken('user-token');
    $facebook->fetchUserId('user-token');
    $facebook->fetchPages('user-token');
    $facebook->grantedScopesByAccount('user-token', ['page-1']);

    expect(Http::recorded())->toHaveCount(4);
    Http::assertNotSent(fn (Request $request) => ! str_starts_with($request->url(), 'https://graph.facebook.com/v99.0/'));
});

it('keeps the array shape of extendUserToken() for 2.x callers', function () {
    $this->freezeTime();
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['access_token' => 'long', 'expires_in' => 60])
        ->push(['error' => ['code' => 190, 'message' => 'bad']], 400)]);

    $extended = metaGraphConnectors()['facebook']->extendUserToken('user-token');

    expect($extended['token'])->toBe('long')
        ->and($extended['expiresAt']->equalTo(now()->addMinute()))->toBeTrue();

    expect(metaGraphConnectors()['facebook']->extendUserToken('user-token'))->toBeInstanceOf(RenewalResult::class);
});
