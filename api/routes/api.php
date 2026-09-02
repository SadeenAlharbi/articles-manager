<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        /* ------------------------------ المقالات ------------------------------ */

        Route::get('/articles', [ArticleController::class, 'index'])
            ->middleware('permission:articles.view');

        Route::get('/articles/{slug}', [ArticleController::class, 'show'])
            ->middleware('permission:articles.view');

        Route::post('/articles', [ArticleController::class, 'store'])
            ->middleware('permission:articles.create');

        Route::put('/articles/{slug}', [ArticleController::class, 'update'])
            ->middleware('permission:articles.update');

        Route::delete('/articles/{slug}', [ArticleController::class, 'destroy'])
            ->middleware('permission:articles.delete');

        /* ---------------------------- سجلّ التدقيق ---------------------------- */
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('role:admin');
    });
});