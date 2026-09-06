<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ التدقيق: من فعل ماذا، على أي مقال، ومتى.
 *
 * الصفوف هنا تُكتب ولا تُعدَّل ولا تُحذف — لهذا يوجد created_at فقط
 * بلا updated_at: سجلّ يمكن تعديله ليس سجلّ تدقيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 64);

            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 191)->nullable();

            $table->jsonb('payload')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->boolean('succeeded')->default(true);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX audit_logs_payload_gin ON audit_logs USING GIN (payload)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
