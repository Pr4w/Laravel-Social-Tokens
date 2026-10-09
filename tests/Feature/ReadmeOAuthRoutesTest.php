<?php

/*
 * The README's OAuth routes take the connector key from the URL. Used as the
 * Socialite driver name too, two keys break: Socialite has no `instagram`
 * driver (Instagram through a Page logs in with Facebook), and its `linkedin`
 * driver asks for r_liteprofile and r_emailaddress, which LinkedIn stopped
 * granting to new apps in 2023 (the profile signs in with `linkedin-openid`,
 * which reads services.linkedin-openid). The sample maps those keys to their
 * driver, keeps the key for StoreConnection, and sends each key back to its
 * own callback: through the facebook driver's configured redirect, an
 * Instagram login would come back as a Facebook one and store Pages only.
 */

use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/** The "Connecting an account" section of the README. */
function readmeConnectingSection(): string
{
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    $start = strpos($readme, "\n## Connecting an account\n");
    $end = strpos($readme, "\n## ", $start + 1);

    return substr($readme, $start, $end - $start);
}

/**
 * The README's map of connector key => Socialite driver.
 *
 * @return array<string, string>
 */
function readmeDriverMap(): array
{
    expect(preg_match('/\$drivers = \[(.*?)\];/s', readmeConnectingSection(), $block))->toBe(1, 'The README routes have no $drivers map.');

    preg_match_all("/^\s*'([\w-]+)' => '([\w-]+)',/m", $block[1], $pairs, PREG_SET_ORDER);

    return collect($pairs)->mapWithKeys(fn (array $pair) => [$pair[1] => $pair[2]])->all();
}

beforeEach(function () {
    config()->set('services.linkedin-openid', config('services.linkedin'));
});

it('maps the connector keys Socialite has no driver for', function () {
    $socialite = new SocialiteManager(app());

    expect(fn () => $socialite->driver('instagram'))->toThrow(InvalidArgumentException::class);

    $drivers = readmeDriverMap();

    expect($drivers)->toMatchArray(['instagram' => 'facebook', 'linkedin' => 'linkedin-openid'])
        ->and($socialite->driver($drivers['instagram']))->toBeInstanceOf(FacebookProvider::class)
        ->and($socialite->driver($drivers['linkedin']))->toBeInstanceOf(LinkedInOpenIdProvider::class);

    // StoreConnection gets the key, which names a connector; the driver does not.
    foreach (array_keys($drivers) as $key) {
        expect(app(ConnectorRegistry::class)->has($key))->toBeTrue("[{$key}] is not a connector key");
    }

    expect(app(ConnectorRegistry::class)->has('linkedin-openid'))->toBeFalse();
});

it('drives Socialite through the map and hands StoreConnection the connector key', function () {
    $section = readmeConnectingSection();

    expect(substr_count($section, 'Socialite::driver($drivers[$provider] ?? $provider)'))->toBe(2)
        ->and($section)->not->toContain('Socialite::driver($provider)')
        ->and($section)->toContain('provider: $provider')
        // Each key comes back to its own callback, whatever its driver's configured redirect.
        ->and(substr_count($section, '->redirectUrl(url("/oauth/{$provider}/callback"))'))->toBe(2);
});

it('declares the services entry the LinkedIn OpenID Connect driver reads', function () {
    expect(readmeConnectingSection())->toContain("'linkedin-openid'")
        ->and((string) file_get_contents(__DIR__.'/../../README.md'))->toMatch("/'linkedin-openid' => \['client_id' => env\('LINKEDIN_CLIENT_ID'\)/");
});
