<?php

namespace Pr4w\SocialTokens\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
 *
 * Laravel 13.44's web group runs PreventRequestForgery, which VerifyCsrfToken
 * and ValidateCsrfToken now extend: excluding VerifyCsrfToken no longer
 * excluded the web group's check, and Meta's posts got a 419 again.
 * Excluding a class excludes its subclasses, so the routes exclude the
 * newest base there is.
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
                Route::post('{provider}/deauthorize', HandleDeauthorizeCallback::class)->name('deauthorize')
                    ->withoutMiddleware(self::csrfMiddleware());
                Route::post('{provider}/delete', HandleDataDeletionCallback::class)->name('delete')
                    ->withoutMiddleware(self::csrfMiddleware());
                Route::get('{provider}/deletion/{code}', ShowDataDeletionStatus::class)->name('deletion-status');
            });
    }

    /**
     * The CSRF check to skip: PreventRequestForgery where Laravel has it (it
     * covers VerifyCsrfToken, ValidateCsrfToken and an app's own subclass),
     * VerifyCsrfToken before (it covers ValidateCsrfToken).
     */
    private static function csrfMiddleware(): string
    {
        $forgery = PreventRequestForgery::class;

        return class_exists($forgery) ? $forgery : VerifyCsrfToken::class;
    }
}
