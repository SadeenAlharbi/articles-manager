<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding one permission from one particular user.
 *
 * A role grants a whole set, and sometimes a single person must be excepted
 * from a single permission inside it without being demoted to a lesser role —
 * a "content supervisor who cannot delete", say. Without this table the only
 * remedy was to create a new role for every exception, and the roles would
 * multiply for no good reason.
 *
 * A denial outranks a grant: a row here drops the permission even if the role
 * granted it, and even if it was granted individually. It is enforced in
 * User::hasPermissionTo — that is, on the same path the middleware and the Gate
 * already go through, not in the interface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_denials', function (Blueprint $table) {
            $table->id();

            // Deleting the user or the permission drops the denial with it —
            // an orphaned row means nothing
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // One denial per (user, permission) — a duplicate would mean nothing
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_denials');
    }
};
