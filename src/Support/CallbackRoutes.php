<?php

namespace Pr4w\SocialTokens\Support;

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
                Route::post('{provider}/deauthorize', HandleDeauthorizeCallback::class)->name('deauthorize');
                Route::post('{provider}/delete', HandleDataDeletionCallback::class)->name('delete');
                Route::get('{provider}/deletion/{code}', ShowDataDeletionStatus::class)->name('deletion-status');
            });
    }
}
