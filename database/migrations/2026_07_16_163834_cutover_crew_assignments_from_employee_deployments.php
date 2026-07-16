<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_sea_services', 'employee_deployment_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->dropForeign(['employee_deployment_id']);
                $table->dropUnique(['employee_deployment_id']);
                $table->dropColumn('employee_deployment_id');
            });
        }

        if (! Schema::hasColumn('employee_sea_services', 'crew_assignment_phase_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->foreignId('crew_assignment_phase_id')
                    ->nullable()
                    ->after('employee_id')
                    ->constrained('crew_assignment_phases')
                    ->nullOnDelete();
                $table->unique('crew_assignment_phase_id');
            });
        }

        if (Schema::hasColumn('crew_planning_assignments', 'relieves_employee_deployment_id')) {
            Schema::table('crew_planning_assignments', function (Blueprint $table) {
                $table->dropForeign(['relieves_employee_deployment_id']);
            });

            try {
                Schema::table('crew_planning_assignments', function (Blueprint $table) {
                    $table->dropIndex('cpa_relieves_deployment_idx');
                });
            } catch (Exception $e) {
            }

            Schema::table('crew_planning_assignments', function (Blueprint $table) {
                $table->dropColumn('relieves_employee_deployment_id');
            });
        }

        if (Schema::hasColumn('crew_planning_assignments', 'employee_deployment_id')) {
            Schema::table('crew_planning_assignments', function (Blueprint $table) {
                $table->dropForeign(['employee_deployment_id']);
                $table->dropUnique(['employee_deployment_id']);
                $table->dropColumn('employee_deployment_id');
            });
        }

        if (! Schema::hasColumn('crew_planning_assignments', 'crew_assignment_id')) {
            Schema::table('crew_planning_assignments', function (Blueprint $table) {
                $table->foreignId('crew_assignment_id')
                    ->nullable()
                    ->after('employee_id')
                    ->constrained('crew_assignments')
                    ->nullOnDelete();
                $table->unique('crew_assignment_id');

                $table->foreignId('relieves_crew_assignment_id')
                    ->nullable()
                    ->after('crew_assignment_id')
                    ->constrained('crew_assignments')
                    ->nullOnDelete();
                $table->index('relieves_crew_assignment_id');
            });
        }

        if (Schema::hasColumn('crew_assignments', 'employee_deployment_id')) {
            Schema::table('crew_assignments', function (Blueprint $table) {
                $table->dropForeign(['employee_deployment_id']);
                $table->dropUnique(['employee_deployment_id']);
                $table->dropColumn('employee_deployment_id');
            });
        }

        if (Schema::hasColumn('crew_assignments', 'crew_planning_assignment_id')) {
            Schema::table('crew_assignments', function (Blueprint $table) {
                $table->dropForeign(['crew_planning_assignment_id']);
                $table->dropUnique(['crew_planning_assignment_id']);
                $table->dropColumn('crew_planning_assignment_id');
            });
        }

        Schema::dropIfExists('employee_deployments');
    }

    public function down(): void
    {
        Schema::create('employee_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('company_visa_type_id')->nullable()->constrained('company_visa_types')->nullOnDelete();
            $table->foreignId('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->date('arrived_date')->nullable();
            $table->date('join_standby_from')->nullable();
            $table->date('join_standby_to')->nullable();
            $table->date('joined_date')->nullable();
            $table->date('disembarked_date')->nullable();
            $table->date('leave_standby_from')->nullable();
            $table->date('leave_standby_to')->nullable();
            $table->date('travelled_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->foreignId('employee_deployment_id')->nullable()->constrained('employee_deployments')->nullOnDelete();
            $table->unique('employee_deployment_id');
            $table->foreignId('crew_planning_assignment_id')->nullable()->constrained('crew_planning_assignments')->nullOnDelete();
            $table->unique('crew_planning_assignment_id');
        });

        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->dropForeign(['relieves_crew_assignment_id']);
            $table->dropIndex(['relieves_crew_assignment_id']);
            $table->dropColumn('relieves_crew_assignment_id');
            $table->dropForeign(['crew_assignment_id']);
            $table->dropUnique(['crew_assignment_id']);
            $table->dropColumn('crew_assignment_id');
        });

        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->foreignId('employee_deployment_id')->nullable()->constrained('employee_deployments')->nullOnDelete();
            $table->unique('employee_deployment_id');
            $table->unsignedBigInteger('relieves_employee_deployment_id')->nullable()->after('employee_deployment_id');
            $table->foreign('relieves_employee_deployment_id', 'cpa_relieves_deployment_fk')
                ->references('id')
                ->on('employee_deployments')
                ->nullOnDelete();
            $table->index('relieves_employee_deployment_id', 'cpa_relieves_deployment_idx');
        });

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropForeign(['crew_assignment_phase_id']);
            $table->dropUnique(['crew_assignment_phase_id']);
            $table->dropColumn('crew_assignment_phase_id');
            $table->foreignId('employee_deployment_id')->nullable()->constrained('employee_deployments')->nullOnDelete();
            $table->unique('employee_deployment_id');
        });
    }
};
