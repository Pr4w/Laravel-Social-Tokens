<?php

namespace Pr4w\SocialTokens\Support;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Pr4w\SocialTokens\Actions\HandleDataDeletionCallback;
use Pr4w\SocialTokens\Actions\HandleDeauthorizeCallback;
use Pr4w\SocialTokens\Actions\ShowDataDeletionStatus;

/**
 * The Meta callbacks' routes, under `callbacks.prefix` with
 * `callbacks.middleware`, named social-tokens.callbacks.*. The service
 * provider registers them when `callbacks.enabled` is on; call register()
 * from your own routes file instead to place them yourself, outside a group
 * that prefixes route names (the deletion answer links to the status route
 * by its name).
 *
 * The two POST routes skip CSRF verification wherever they are placed: Meta
 * sends no CSRF token, so inside the `web` group (routes/web.php) every
 * callback was refused with a 419 before it was verified. The signed_request
 * is their authentication.
 */
final class CallbackRoutes
{
    public static function register(): void
    {
        $callbacks = Settings::callbacks();

        Route::prefix($callbacks['prefix'])
            ->middleware($callbacks['middleware'])
            ->name('social-tokens.callbacks.')
            ->where(['provider' => '[A-Za-z0-9_-]+'])
            ->group(function () {
                // VerifyCsrfToken covers ValidateCsrfToken, the web group's,
                // and an app's own subclass of either.
                Route::post('{provider}/deauthorize', HandleDeauthorizeCallback::class)->name('deauthorize')
                    ->withoutMiddleware(VerifyCsrfToken::class);
                Route::post('{provider}/delete', HandleDataDeletionCallback::class)->name('delete')
                    ->withoutMiddleware(VerifyCsrfToken::class);
                Route::get('{provider}/deletion/{code}', ShowDataDeletionStatus::class)->name('deletion-status');
            });
    }
}
