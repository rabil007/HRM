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
        $rolesTable = config('permission.table_names.roles', 'spatie_roles');

        if (! Schema::hasColumn($rolesTable, 'employee_visibility_scope')) {
            Schema::table($rolesTable, function (Blueprint $table) {
                $table->string('employee_visibility_scope', 32)->default('all')->after('guard_name');
            });
        }

        if (! Schema::hasTable('role_employee_visibility_departments')) {
            Schema::create('role_employee_visibility_departments', function (Blueprint $table) use ($rolesTable) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained($rolesTable)->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'department_id'], 'role_dept_scope_unique');
                $table->index(['company_id', 'role_id'], 'role_dept_scope_company_role_idx');
                $table->index(['company_id', 'department_id'], 'role_dept_scope_company_dept_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_employee_visibility_departments');

        $rolesTable = config('permission.table_names.roles', 'spatie_roles');

        if (Schema::hasColumn($rolesTable, 'employee_visibility_scope')) {
            Schema::table($rolesTable, function (Blueprint $table) {
                $table->dropColumn('employee_visibility_scope');
            });
        }
    }
};
