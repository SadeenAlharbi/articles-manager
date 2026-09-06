<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعطيل حساب في نظام الإدارة.
 *
 * العمود منطقي وافتراضه true، فكل الحسابات القائمة تبقى نشطة كما هي
 * ولا تُمسّ أي بيانات. التعطيل حالة قابلة للعكس — لا حذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
