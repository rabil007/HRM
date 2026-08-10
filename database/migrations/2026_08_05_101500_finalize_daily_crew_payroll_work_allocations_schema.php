<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent cleanup for environments that already ran the prior-period-lines
 * + early allocations schema. Fresh installs skip most work via hasColumn/hasTable guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_work_allocations')
            && Schema::hasColumn('payroll_work_allocations', 'crew_timesheet_prior_period_line_id')) {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('crew_timesheet_prior_period_line_id');
            });
        }

        if (Schema::hasTable('crew_timesheet_prior_period_lines')) {
            Schema::drop('crew_timesheet_prior_period_lines');
        }

        if (! Schema::hasTable('payroll_work_allocations')) {
            return;
        }

        Schema::table('payroll_work_allocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_work_allocations', 'status')) {
                $table->string('status', 32)->default('reserved')->after('period_classification');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'source')) {
                $table->string('source', 32)->nullable()->after('status');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'crew_assignment_id')) {
                $table->foreignId('crew_assignment_id')
                    ->nullable()
                    ->after('source')
                    ->constrained('crew_assignments', indexName: 'pwa_assignment_fk')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'crew_assignment_phase_id')) {
                $table->foreignId('crew_assignment_phase_id')
                    ->nullable()
                    ->after('crew_assignment_id')
                    ->constrained('crew_assignment_phases', indexName: 'pwa_phase_fk')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('total_amount');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('approved_at');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('paid_at');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'reversal_reason')) {
                $table->text('reversal_reason')->nullable()->after('reversed_at');
            }

            if (! Schema::hasColumn('payroll_work_allocations', 'active_allocation_key')) {
                $table->string('active_allocation_key', 64)
                    ->nullable()
                    ->after('reversal_reason');
            }
        });

        $this->dropUniqueIfExists('payroll_work_allocations', 'pwa_company_employee_work_date_unique');

        if (! $this->indexExists('payroll_work_allocations', 'pwa_active_allocation_key_unique')) {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->unique('active_allocation_key', 'pwa_active_allocation_key_unique');
            });
        }

        if (! $this->indexExists('payroll_work_allocations', 'pwa_company_employee_work_date_idx')) {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->index(
                    ['company_id', 'employee_id', 'work_date'],
                    'pwa_company_employee_work_date_idx',
                );
            });
        }

        if (! $this->indexExists('payroll_work_allocations', 'pwa_company_status_idx')) {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->index(['company_id', 'status'], 'pwa_company_status_idx');
            });
        }

        // Convert cascadeOnDelete payroll_record FK to nullOnDelete when the old schema is present.
        $this->ensurePayrollRecordNullOnDelete();

        // Backfill active keys for any pre-existing non-reversed rows.
        DB::table('payroll_work_allocations')
            ->whereNull('active_allocation_key')
            ->whereIn('status', ['reserved', 'approved', 'paid'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $workDate = is_string($row->work_date)
                        ? $row->work_date
                        : (string) $row->work_date;

                    DB::table('payroll_work_allocations')
                        ->where('id', $row->id)
                        ->update([
                            'active_allocation_key' => sprintf(
                                '%d:%d:%s',
                                (int) $row->company_id,
                                (int) $row->employee_id,
                                substr($workDate, 0, 10),
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: do not recreate prior-period-lines or the old unique index.
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropUnique($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = Schema::getConnection()->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $result = Schema::getConnection()->selectOne(
            'select count(*) as aggregate from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ?',
            [$database, $table, $indexName],
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }

    private function ensurePayrollRecordNullOnDelete(): void
    {
        if (! Schema::hasColumn('payroll_work_allocations', 'payroll_record_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->dropForeign('pwa_record_fk');
            });
        } catch (Throwable) {
            // FK may already have been dropped or renamed.
        }

        Schema::table('payroll_work_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('payroll_record_id')->nullable()->change();
        });

        Schema::table('payroll_work_allocations', function (Blueprint $table): void {
            $table->foreign('payroll_record_id', 'pwa_record_fk')
                ->references('id')
                ->on('payroll_records')
                ->nullOnDelete();
        });
    }
};
