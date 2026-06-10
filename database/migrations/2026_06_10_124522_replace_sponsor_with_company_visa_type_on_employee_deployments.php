<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_deployments', 'sponsor_id')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->dropForeign(['sponsor_id']);
                $table->dropColumn('sponsor_id');
            });
        }

        if (! Schema::hasColumn('employee_deployments', 'company_visa_type_id')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->foreignId('company_visa_type_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('company_visa_types')
                    ->nullOnDelete();
            });
        }

        Schema::dropIfExists('sponsors');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sponsors')) {
            Schema::create('sponsors', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique('name', 'uq_sponsors_name');
            });
        }

        if (Schema::hasColumn('employee_deployments', 'company_visa_type_id')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->dropForeign(['company_visa_type_id']);
                $table->dropColumn('company_visa_type_id');
            });
        }

        if (! Schema::hasColumn('employee_deployments', 'sponsor_id')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->foreignId('sponsor_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('sponsors')
                    ->nullOnDelete();
            });
        }
    }
};
