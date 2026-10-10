<?php

use Illuminate\Support\Facades\Http;
use Pr4w\SocialTokens\Actions\StoreConnection;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Tests\Fixtures\Owner;

/*
 * An `instagram` connection stores the Facebook Page linked to each Instagram
 * account as a `facebook` account, and returns it with them; the docs said
 * nothing of it. Since 3.0 a connected Page keeps the Meta user credential
 * alive, so an app that shows Instagram accounts only, disconnecting every
 * one of them, left that 60-day token active and renewed. The README now
 * says so, and shows how to disconnect the Page with the Instagram account:
 * these tests pin both.
 */

beforeEach(function () {
    Http::fake(function ($request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'user-token', 'expires_in' => 5184000]),
            str_contains($url, '/debug_token') => Http::response(['data' => ['scopes' => [], 'granular_scopes' => []]]),
            str_contains($url, '/me/accounts') => Http::response(['data' => [[
                'id' => 'page-1', 'name' => 'Page One', 'access_token' => 'page-token',
                'instagram_business_account' => ['id' => 'ig-1', 'username' => 'insta'],
            ]]]),
            default => Http::response([], 404),
        };
    });

    $this->owner = Owner::create(['name' => 'acme']);
    $this->accounts = app(StoreConnection::class)->handle('instagram', socialiteUser(['id' => 'fb-1']), owner: $this->owner);
    $this->user = SocialToken::query()->where('provider_holder_id', 'fb-1')->sole();
});

it('returns the linked Facebook Page with the Instagram account', function () {
    expect($this->accounts->map(fn (SocialAccount $account) => "{$account->provider}:{$account->provider_user_id}")->all())
        ->toBe(['instagram:ig-1', 'facebook:page-1'])
        ->and($this->accounts->firstWhere('provider', 'instagram')->profile['fb_page_id'])->toBe('page-1')
        ->and($this->accounts->firstWhere('provider', 'facebook')->profile['ig_account_id'])->toBe('ig-1');
});

it('keeps the Meta user credential active while the companion Page is connected', function () {
    app(SocialTokens::class)->disconnect($this->accounts->firstWhere('provider', 'instagram'));

    expect($this->user->fresh()->status)->toBe(AccountStatus::Active)
        ->and(SocialToken::query()->inUse()->whereKey($this->user->getKey())->exists())->toBeTrue();
});

it('revokes the Meta user credential once the README\'s snippet disconnects the Page too', function () {
    $instagram = $this->accounts->firstWhere('provider', 'instagram');

    // The README's block, as printed.
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $blocks);
    $snippet = collect($blocks[1])->sole(fn (string $block) => str_contains($block, "profile['fb_page_id']"));

    eval('use '.SocialAccount::class.'; use '.SocialTokens::class.';'.$snippet);

    expect($this->user->fresh()->status)->toBe(AccountStatus::Revoked)
        ->and($this->user->fresh()->access_token)->toBeNull();
});
