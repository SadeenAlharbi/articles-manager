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
     * auth:sanctum  → مَن أنت؟          (مصادقة)
     * active        → هل حسابك مفعّل؟   (حالة الحساب)
     * permission:…  → هل يُسمح لك؟      (تفويض، على كل مسار)
     */
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        /* ------------------------------ المقالات ------------------------------ */

        Route::get('/articles', [ArticleController::class, 'index'])
            ->middleware('permission:articles.view');

        /*
         * التصنيفات قبل /articles/{slug} في الترتيب عمداً: لو جاءت بعده لالتقط
         * المسار المتغيّر كلمة "categories" وعدّها اسم مقال.
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
         * النشر والسحب إجراءان مستقلّان لا تعديلٌ عام: لكلٍّ صلاحيته على المسار
         * نفسه، وسطره الصريح في سجلّ العمليات. ولو مرّا عبر PUT العام لاختفت
         * «نشر مقال» داخل «تعديل مقال» ولحرسهما فحصٌ داخل المتحكّم لا حارس مسار.
         */
        Route::post('/articles/{slug}/publish', [ArticleController::class, 'publish'])
            ->middleware('permission:articles.publish');

        Route::post('/articles/{slug}/draft', [ArticleController::class, 'draft'])
            ->middleware('permission:articles.draft');

        /* ---------------------------- المستخدمون ---------------------------- */
        /*
         * الـmiddleware يحرس المسار: هل تملك الصلاحية أصلاً؟
         * و UserPolicy داخل المتحكّم تحرس الهدف: هل تعلو على هذا الشخص؟
         * لا يوجد DELETE — التعطيل بديل الحذف، والسجلّات تبقى.
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

        /* ------------------------- لوحة المعلومات ------------------------- */
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:analytics.view');

        /* --------------------------- سجلّ التدقيق -------------------------- */
        // صلاحية فردية لا دور: يمكن منح قراءة السجلّ لشخص بعينه
        // دون ترقيته إلى مشرف — وهذا جوهر الصلاحيات الفردية.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
    });
});
