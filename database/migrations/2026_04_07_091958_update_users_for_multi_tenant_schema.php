<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('company_id')->constrained('roles');
            $table->string('avatar', 500)->nullable()->after('password');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('avatar');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['company_id', 'email'], 'uq_user_email_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_user_email_company');
            $table->unique('email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'avatar',
                'status',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }
};
