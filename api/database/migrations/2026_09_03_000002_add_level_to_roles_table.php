<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The role's level — the basis of the permission hierarchy.
 *
 * The level is a property of the role, not of the person, so there are never
 * two sources of truth pulling against each other. A user's level is then the
 * highest level among their roles.
 *
 * The default value is zero: any role created later without an explicit level
 * holds authority over nobody — the safe default is the least privilege, not
 * the most.
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
