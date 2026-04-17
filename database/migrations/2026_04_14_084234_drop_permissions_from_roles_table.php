<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::statement('ALTER TABLE roles DROP INDEX uq_role_company');
        } catch (Throwable) {
        }

        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'permissions')) {
                $table->dropColumn('permissions');
            }

            if (Schema::hasColumn('roles', 'is_system')) {
                $table->dropColumn('is_system');
            }

            if (Schema::hasColumn('roles', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'slug')) {
                $table->string('slug')->nullable();
            }

            if (! Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false);
            }

            if (! Schema::hasColumn('roles', 'permissions')) {
                $table->json('permissions')->nullable();
            }
        });
    }
};
