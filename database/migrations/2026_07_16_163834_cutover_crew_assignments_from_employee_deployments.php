<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_sea_services', 'employee_deployment_id')) {
            $this->dropForeignKeyOnColumn('employee_sea_services', 'employee_deployment_id');
            $this->dropUniqueOrIndex('employee_sea_services', 'employee_deployment_id', 'employee_sea_services_employee_deployment_id_unique');

            Schema::table('employee_sea_services', function (Blueprint $table) {
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
            $this->dropForeignKeyOnColumn(
                'crew_planning_assignments',
                'relieves_employee_deployment_id',
                ['cpa_relieves_deployment_fk'],
            );
            $this->dropNamedIndex(
                'crew_planning_assignments',
                'relieves_employee_deployment_id',
                'cpa_relieves_deployment_idx',
            );

            Schema::table('crew_planning_assignments', function (Blueprint $table) {
                $table->dropColumn('relieves_employee_deployment_id');
            });
        }

        if (Schema::hasColumn('crew_planning_assignments', 'employee_deployment_id')) {
            $this->dropForeignKeyOnColumn('crew_planning_assignments', 'employee_deployment_id');
            $this->dropUniqueOrIndex(
                'crew_planning_assignments',
                'employee_deployment_id',
                'crew_planning_assignments_employee_deployment_id_unique',
            );

            Schema::table('crew_planning_assignments', function (Blueprint $table) {
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
            $this->dropForeignKeyOnColumn('crew_assignments', 'employee_deployment_id');
            $this->dropUniqueOrIndex('crew_assignments', 'employee_deployment_id', 'crew_assignments_employee_deployment_id_unique');

            Schema::table('crew_assignments', function (Blueprint $table) {
                $table->dropColumn('employee_deployment_id');
            });
        }

        if (Schema::hasColumn('crew_assignments', 'crew_planning_assignment_id')) {
            $this->dropForeignKeyOnColumn('crew_assignments', 'crew_planning_assignment_id');
            $this->dropUniqueOrIndex(
                'crew_assignments',
                'crew_planning_assignment_id',
                'crew_assignments_crew_planning_assignment_id_unique',
            );

            Schema::table('crew_assignments', function (Blueprint $table) {
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

    /**
     * @param  list<string>  $alternateNames
     */
    private function dropForeignKeyOnColumn(string $table, string $column, array $alternateNames = []): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });

            return;
        }

        $names = array_values(array_unique([
            ...$alternateNames,
            "{$table}_{$column}_foreign",
        ]));

        foreach ($names as $foreignKey) {
            if (! $this->foreignKeyExists($table, $foreignKey)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign($foreignKey);
            });

            return;
        }
    }

    private function dropUniqueOrIndex(string $table, string $column, string $indexName): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropUnique([$column]);
                });
            } catch (Throwable) {
                // Unique may already be gone after foreign-key rebuild.
            }

            return;
        }

        $this->dropIndexByName($table, $indexName);
    }

    private function dropNamedIndex(string $table, string $column, string $indexName): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                    $blueprint->dropIndex($indexName);
                });
            } catch (Throwable) {
                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                        $blueprint->dropIndex([$column]);
                    });
                } catch (Throwable) {
                    // Index may already be gone after foreign-key rebuild.
                }
            }

            return;
        }

        $this->dropIndexByName($table, $indexName);
    }

    private function dropIndexByName(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
            });
        } catch (Throwable) {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropUnique($index);
                });
            } catch (Throwable) {
                // Already removed with the foreign key.
            }
        }
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignKey)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
