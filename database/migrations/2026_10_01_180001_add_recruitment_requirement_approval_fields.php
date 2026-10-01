<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requirements', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('opened_at');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')
                ->constrained('users', 'id', 'fk_req_submitted_by')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users', 'id', 'fk_req_approved_by')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('approved_by');
            $table->foreignId('returned_by')->nullable()->after('returned_at')
                ->constrained('users', 'id', 'fk_req_returned_by')->nullOnDelete();
            $table->text('return_reason')->nullable()->after('returned_by');

            $table->index(['company_id', 'submitted_at'], 'idx_recruitment_req_company_submitted_at');
            $table->index(['company_id', 'approved_at'], 'idx_recruitment_req_company_approved_at');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('recruitment_requirements', function (Blueprint $table) use ($driver) {
            $this->dropForeignForColumn($table, $driver, 'fk_req_submitted_by', 'submitted_by');
            $this->dropForeignForColumn($table, $driver, 'fk_req_approved_by', 'approved_by');
            $this->dropForeignForColumn($table, $driver, 'fk_req_returned_by', 'returned_by');

            $this->dropIndexIfPresent($table, $driver, 'idx_recruitment_req_company_submitted_at', ['company_id', 'submitted_at']);
            $this->dropIndexIfPresent($table, $driver, 'idx_recruitment_req_company_approved_at', ['company_id', 'approved_at']);

            $table->dropColumn([
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'returned_at',
                'returned_by',
                'return_reason',
            ]);
        });
    }

    private function dropForeignForColumn(Blueprint $table, string $driver, string $customName, string $columnName): void
    {
        if ($driver === 'sqlite') {
            try {
                $table->dropForeign([$columnName]);
            } catch (Throwable) {
                // SQLite may recreate tables without named FKs.
            }

            return;
        }

        try {
            $table->dropForeign($customName);
        } catch (Throwable) {
            try {
                $table->dropForeign([$columnName]);
            } catch (Throwable) {
                // Foreign key already absent.
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexIfPresent(Blueprint $table, string $driver, string $indexName, array $columns): void
    {
        try {
            $table->dropIndex($indexName);
        } catch (Throwable) {
            if ($driver === 'sqlite') {
                try {
                    $table->dropIndex($columns);
                } catch (Throwable) {
                    // Index already absent or SQLite rebuilt the table.
                }
            }
        }
    }
};
