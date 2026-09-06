<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مستوى الدور — أساس تدرّج الصلاحيات.
 *
 * المستوى صفة الدور لا الشخص، فلا يوجد مصدرا حقيقة يتعارضان.
 * ومستوى المستخدم = أعلى مستوى بين أدواره.
 *
 * القيمة الافتراضية صفر: أي دور يُنشأ لاحقاً بلا مستوى صريح لا يملك
 * سلطة على أحد — الافتراض الآمن هو أقلّ صلاحية لا أكثرها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(0)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
