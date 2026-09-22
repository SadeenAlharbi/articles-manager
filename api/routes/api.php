<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    /*
     * auth:sanctum  → who are you?           (authentication)
     * active        → is your account live?  (account state)
     * permission:…  → are you allowed to?    (authorisation, on every route)
     */
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        /* ------------------------------ Articles ------------------------------ */

        Route::get('/articles', [ArticleController::class, 'index'])
            ->middleware('permission:articles.view');

        /*
         * Categories comes before /articles/{slug} in the ordering on purpose: had
         * it come after, the wildcard route would have caught the word "categories"
         * and taken it for an article slug.
         */
        Route::get('/articles/categories', [ArticleController::class, 'categories'])
            ->middleware('permission:articles.view');

        Route::get('/articles/{slug}', [ArticleController::class, 'show'])
            ->middleware('permission:articles.view');

        Route::post('/articles', [ArticleController::class, 'store'])
            ->middleware('permission:articles.create');

        Route::put('/articles/{slug}', [ArticleController::class, 'update'])
            ->middleware('permission:articles.update');

        Route::delete('/articles/{slug}', [ArticleController::class, 'destroy'])
            ->middleware('permission:articles.delete');

        /*
         * Publishing and unpublishing are two standalone actions, not one generic
         * update: each has its own permission on its own route, and its own
         * explicit line in the audit log. Had they gone through the general PUT,
         * "publish an article" would have vanished inside "edit an article", and
         * both would have been guarded by a check inside the controller rather
         * than by a route guard.
         */
        Route::post('/articles/{slug}/publish', [ArticleController::class, 'publish'])
            ->middleware('permission:articles.publish');

        Route::post('/articles/{slug}/draft', [ArticleController::class, 'draft'])
            ->middleware('permission:articles.draft');

        /* ------------------------------ Users ------------------------------- */
        /*
         * The middleware guards the route: do you hold the permission at all?
         * UserPolicy inside the controller guards the target: do you outrank this
         * particular person?
         * There is no DELETE — disabling stands in for deletion, and the records
         * remain.
         */
        Route::get('/users/meta', [UserController::class, 'meta'])
            ->middleware('permission:users.manage');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('permission:users.manage');

        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:users.manage');

        Route::get('/users/{user}', [UserController::class, 'show'])
            ->middleware('permission:users.manage');

        Route::put('/users/{user}', [UserController::class, 'update'])
            ->middleware('permission:users.manage');

        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])
            ->middleware('permission:users.manage');

        Route::put('/users/{user}/role', [UserController::class, 'updateRole'])
            ->middleware('permission:roles.manage');

        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions'])
            ->middleware('permission:roles.manage');

        /* --------------------------- Dashboard ---------------------------- */
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:analytics.view');

        /* ---------------------------- Audit log --------------------------- */
        // An individual permission, not a role: reading the log can be granted to
        // one specific person without promoting them to supervisor — and that is
        // the whole point of per-user permissions.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
    });
});
