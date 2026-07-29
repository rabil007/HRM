<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive indexes for leave-request overlap/list queries and policy history.
 *
 * Intentionally does NOT recreate company/approver/status — that composite already
 * exists as leave_req_approvals_company_user_status_index from the create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            if (! $this->hasIndexWithColumns('leave_requests', ['company_id', 'employee_id', 'status', 'start_date', 'end_date'])) {
                $table->index(
                    ['company_id', 'employee_id', 'status', 'start_date', 'end_date'],
                    'idx_lr_company_emp_status_dates',
                );
            }

            if (! $this->hasIndexWithColumns('leave_requests', ['company_id', 'status', 'id'])) {
                $table->index(
                    ['company_id', 'status', 'id'],
                    'idx_lr_company_status_id',
                );
            }
        });

        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            // Skip duplicate of leave_req_approvals_company_user_status_index.
            if (! $this->hasIndexWithColumns('leave_request_approvals', ['company_id', 'policy_id'])) {
                $table->index(
                    ['company_id', 'policy_id'],
                    'idx_lra_company_policy',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            if (Schema::hasIndex('leave_requests', 'idx_lr_company_emp_status_dates')) {
                $table->dropIndex('idx_lr_company_emp_status_dates');
            }

            if (Schema::hasIndex('leave_requests', 'idx_lr_company_status_id')) {
                $table->dropIndex('idx_lr_company_status_id');
            }
        });

        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            if (Schema::hasIndex('leave_request_approvals', 'idx_lra_company_policy')) {
                $table->dropIndex('idx_lra_company_policy');
            }

            // Never drop leave_req_approvals_company_user_status_index — owned by create migration.
            // Drop the duplicate name only if a previous version of this migration created it.
            if (Schema::hasIndex('leave_request_approvals', 'idx_lra_company_approver_status')) {
                $table->dropIndex('idx_lra_company_approver_status');
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexWithColumns(string $table, array $columns): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        $wanted = array_map('strtolower', $columns);

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                $name = $index->name ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $indexColumns = collect(DB::select("PRAGMA index_info('{$name}')"))
                    ->sortBy('seqno')
                    ->pluck('name')
                    ->map(fn ($column) => strtolower((string) $column))
                    ->values()
                    ->all();

                if ($indexColumns === $wanted) {
                    return true;
                }
            }

            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            $indexColumns = array_map('strtolower', $index['columns'] ?? []);

            if ($indexColumns === $wanted) {
                return true;
            }
        }

        return false;
    }
};
