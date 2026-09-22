<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log: who did what, to which article, and when.
 *
 * Rows here are written, never updated and never deleted — which is why there
 * is only created_at and no updated_at: a log you can edit is not an audit log.
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
