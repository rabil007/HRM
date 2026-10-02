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
        $table = 'recruitment_requirements';

        $this->dropForeignKey($table, 'fk_req_submitted_by', 'submitted_by');
        $this->dropForeignKey($table, 'fk_req_approved_by', 'approved_by');
        $this->dropForeignKey($table, 'fk_req_returned_by', 'returned_by');

        $this->dropNamedIndex($table, 'idx_recruitment_req_company_submitted_at');
        $this->dropNamedIndex($table, 'idx_recruitment_req_company_approved_at');

        $columns = [
            'submitted_at',
            'submitted_by',
            'approved_at',
            'approved_by',
            'returned_at',
            'returned_by',
            'return_reason',
        ];

        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }

    private function dropForeignKey(string $table, string $constraintName, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $foreignKeys = collect(Schema::getForeignKeys($table));

        if ($driver === 'sqlite') {
            // SQLite often omits custom constraint names; drop by column only when a FK exists on it.
            $hasFkOnColumn = $foreignKeys->contains(
                fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'] ?? [], true),
            );

            if (! $hasFkOnColumn || ! Schema::hasColumn($table, $column)) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });

            return;
        }

        $existsByName = $foreignKeys->contains(
            fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === $constraintName,
        );

        if (! $existsByName) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($constraintName): void {
            $blueprint->dropForeign($constraintName);
        });
    }

    private function dropNamedIndex(string $table, string $indexName): void
    {
        if (! Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }
};
