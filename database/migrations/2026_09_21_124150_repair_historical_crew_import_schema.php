<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair historical crew import schema for environments that partially applied
 * Phase 3 migrations (MySQL long FK name failure + hasTable early-return).
 *
 * Also adds workbook_hash / last_progress_at for resumable imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureBatchesTable();
        $this->ensureRowsTable();
        $this->ensureCrewAssignmentColumn();
        $this->ensureBatchProgressColumns();
        $this->ensureConstraintsAndIndexes();
    }

    public function down(): void
    {
        if (Schema::hasTable('historical_crew_import_batches')) {
            Schema::table('historical_crew_import_batches', function (Blueprint $table): void {
                if (Schema::hasColumn('historical_crew_import_batches', 'workbook_hash')) {
                    $table->dropColumn('workbook_hash');
                }
                if (Schema::hasColumn('historical_crew_import_batches', 'last_progress_at')) {
                    $table->dropColumn('last_progress_at');
                }
            });
        }
    }

    private function ensureBatchesTable(): void
    {
        if (Schema::hasTable('historical_crew_import_batches')) {
            return;
        }

        Schema::create('historical_crew_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('status', 40);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('blocked_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->string('idempotency_key', 64)->nullable();
            $table->string('workbook_hash', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'batch_no'], 'uq_hist_crew_batches_company_batch_no');
            $table->unique(['company_id', 'idempotency_key'], 'uq_hist_crew_batches_company_idem');
            $table->index(['company_id', 'created_at'], 'idx_hist_crew_batches_company_created');
        });
    }

    private function ensureRowsTable(): void
    {
        if (Schema::hasTable('historical_crew_import_rows')) {
            return;
        }

        Schema::create('historical_crew_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('historical_crew_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('employee_no', 64)->nullable();
            $table->string('employee_name', 255)->nullable();
            $table->string('vessel_name', 255)->nullable();
            $table->string('rank_name', 255)->nullable();
            $table->string('status', 40);
            $table->unsignedBigInteger('crew_assignment_id')->nullable();
            $table->string('assignment_no', 32)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->foreign('historical_crew_import_batch_id', 'fk_hist_crew_rows_batch')
                ->references('id')
                ->on('historical_crew_import_batches')
                ->cascadeOnDelete();

            $table->foreign('crew_assignment_id', 'fk_hist_crew_rows_assignment')
                ->references('id')
                ->on('crew_assignments')
                ->nullOnDelete();

            $table->unique(['historical_crew_import_batch_id', 'row_number'], 'uq_hist_crew_import_rows_batch_row');
            $table->index(['historical_crew_import_batch_id', 'status'], 'idx_hist_crew_import_rows_batch_status');
        });
    }

    private function ensureCrewAssignmentColumn(): void
    {
        if (Schema::hasColumn('crew_assignments', 'historical_import_batch_id')) {
            return;
        }

        Schema::table('crew_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('historical_import_batch_id')
                ->nullable()
                ->after('source');
        });
    }

    private function ensureBatchProgressColumns(): void
    {
        if (! Schema::hasTable('historical_crew_import_batches')) {
            return;
        }

        Schema::table('historical_crew_import_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('historical_crew_import_batches', 'workbook_hash')) {
                $table->string('workbook_hash', 64)->nullable()->after('idempotency_key');
            }

            if (! Schema::hasColumn('historical_crew_import_batches', 'last_progress_at')) {
                $table->timestamp('last_progress_at')->nullable()->after('started_at');
            }
        });
    }

    private function ensureConstraintsAndIndexes(): void
    {
        if (Schema::hasTable('historical_crew_import_batches')) {
            $this->ensureUniqueIndex(
                'historical_crew_import_batches',
                ['company_id', 'batch_no'],
                'uq_hist_crew_batches_company_batch_no',
            );
            $this->ensureUniqueIndex(
                'historical_crew_import_batches',
                ['company_id', 'idempotency_key'],
                'uq_hist_crew_batches_company_idem',
            );
            $this->ensureIndex(
                'historical_crew_import_batches',
                ['company_id', 'created_at'],
                'idx_hist_crew_batches_company_created',
            );
        }

        if (Schema::hasTable('historical_crew_import_rows')) {
            $this->ensureForeignKey(
                table: 'historical_crew_import_rows',
                columns: ['historical_crew_import_batch_id'],
                referencedTable: 'historical_crew_import_batches',
                referencedColumns: ['id'],
                name: 'fk_hist_crew_rows_batch',
                onDelete: 'cascade',
            );
            $this->ensureForeignKey(
                table: 'historical_crew_import_rows',
                columns: ['crew_assignment_id'],
                referencedTable: 'crew_assignments',
                referencedColumns: ['id'],
                name: 'fk_hist_crew_rows_assignment',
                onDelete: 'set null',
            );
            $this->ensureUniqueIndex(
                'historical_crew_import_rows',
                ['historical_crew_import_batch_id', 'row_number'],
                'uq_hist_crew_import_rows_batch_row',
            );
            $this->ensureIndex(
                'historical_crew_import_rows',
                ['historical_crew_import_batch_id', 'status'],
                'idx_hist_crew_import_rows_batch_status',
            );
        }

        if (Schema::hasColumn('crew_assignments', 'historical_import_batch_id')) {
            $this->ensureForeignKey(
                table: 'crew_assignments',
                columns: ['historical_import_batch_id'],
                referencedTable: 'historical_crew_import_batches',
                referencedColumns: ['id'],
                name: 'fk_crew_assignment_hist_batch',
                onDelete: 'set null',
            );
            $this->ensureIndex(
                'crew_assignments',
                ['company_id', 'historical_import_batch_id'],
                'idx_crew_assignments_company_hist_batch',
            );
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    private function ensureForeignKey(
        string $table,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $name,
        string $onDelete,
    ): void {
        if ($this->hasForeignKeyOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $referencedTable, $referencedColumns, $name, $onDelete): void {
            $foreign = $blueprint->foreign($columns, $name)
                ->references($referencedColumns)
                ->on($referencedTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKeyOnColumns(string $table, array $columns): bool
    {
        $wanted = array_map('strtolower', $columns);

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $actual = array_map('strtolower', $foreignKey['columns'] ?? []);

            if ($actual === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUniqueIndex(string $table, array $columns, string $name): void
    {
        if ($this->hasIndexCoveringColumns($table, $columns, unique: true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->unique($columns, $name);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if ($this->hasIndexCoveringColumns($table, $columns, unique: false)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexCoveringColumns(string $table, array $columns, bool $unique): bool
    {
        $wanted = array_map('strtolower', $columns);
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                $name = $index->name ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $isUnique = (bool) ($index->unique ?? false);
                if ($unique && ! $isUnique) {
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
            $actual = array_map('strtolower', $index['columns'] ?? []);
            $isUnique = (bool) ($index['unique'] ?? false);

            if ($actual === $wanted && (! $unique || $isUnique)) {
                return true;
            }
        }

        return false;
    }
};
