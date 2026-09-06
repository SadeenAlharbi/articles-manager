<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حجب صلاحية عن مستخدم بعينه.
 *
 * الدور يمنح مجموعة، وأحياناً يجب استثناء شخص واحد من صلاحية واحدة فيها
 * دون إنزاله إلى دور أدنى — «مشرف محتوى بلا حذف» مثلاً. بدون هذا الجدول
 * كان الحل الوحيد إنشاء دور جديد لكل استثناء، فتتضخّم الأدوار بلا معنى.
 *
 * الحجب يعلو على المنح: صف هنا يُسقط الصلاحية حتى لو منحها الدور، وحتى لو
 * مُنحت منحاً فردياً. والتطبيق في User::hasPermissionTo — أي في نفس المسار
 * الذي تمرّ منه الـmiddleware و Gate، لا في الواجهة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_denials', function (Blueprint $table) {
            $table->id();

            // حذف المستخدم أو الصلاحية يُسقط الحجب معه — لا معنى لصفّ يتيم
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // حجب واحد لكل (مستخدم، صلاحية) — التكرار لا يعني شيئاً
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_denials');
    }
};
