<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade-safe cleanup for environments that already created the duplicate
 * leave_request_approvals company/approver/status index under the temporary name
 * idx_lra_company_approver_status. Fresh installs never create that duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_request_approvals')) {
            return;
        }

        if (! $this->hasIndexNamed('leave_request_approvals', 'idx_lra_company_approver_status')) {
            return;
        }

        // Only drop when the original create-migration index is present so we never
        // leave approvals without a company/approver/status access path.
        if (! $this->hasIndexWithColumns('leave_request_approvals', ['company_id', 'approver_user_id', 'status'])) {
            return;
        }

        // Prefer keeping leave_req_approvals_company_user_status_index.
        if (! $this->hasIndexNamed('leave_request_approvals', 'leave_req_approvals_company_user_status_index')) {
            return;
        }

        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            $table->dropIndex('idx_lra_company_approver_status');
        });
    }

    public function down(): void
    {
        // Intentionally empty: recreating the duplicate index would reintroduce the defect.
        // Fresh installs already have leave_req_approvals_company_user_status_index.
    }

    private function hasIndexNamed(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
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
