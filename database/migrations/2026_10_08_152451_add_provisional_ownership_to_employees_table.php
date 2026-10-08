<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('provisional_created_by')
                ->nullable()
                ->after('employee_profile_template_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('provisional_ensure_key', 64)
                ->nullable()
                ->after('provisional_created_by');

            $table->unique(
                ['company_id', 'provisional_created_by', 'provisional_ensure_key'],
                'employees_provisional_ensure_uidx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_provisional_ensure_uidx');
            $table->dropConstrainedForeignId('provisional_created_by');
            $table->dropColumn('provisional_ensure_key');
        });
    }
};
