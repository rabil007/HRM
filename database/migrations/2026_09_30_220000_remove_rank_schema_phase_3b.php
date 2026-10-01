<?php

use App\Support\MasterData\RankRemovalGuards;
use App\Support\MasterData\RankRemovalReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3B Migration B — destructive Rank schema removal.
 *
 * Irreversible. Production rollback requires restoring a database backup and
 * redeploying the previous application version. Do not invent synthetic Rank rows.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $rankPermissionNames = [
        'settings.master-data.ranks.view',
        'settings.master-data.ranks.create',
        'settings.master-data.ranks.update',
        'settings.master-data.ranks.delete',
    ];

    /**
     * @var list<string>
     */
    private array $rankIdTables = [
        'employees',
        'crew_assignments',
        'crew_planning_assignments',
        'employee_sea_services',
        'vessel_manning',
    ];

    public function up(): void
    {
        RankRemovalGuards::assertReadyOrFail();

        $this->assertNoOrphanRankColumns();

        Schema::withoutForeignKeyConstraints(function (): void {
            if (Schema::hasTable('document_requirement_rank')) {
                Schema::drop('document_requirement_rank');
            }

            foreach ($this->rankIdTables as $table) {
                $this->dropRankIdFromTable($table);
            }

            if (Schema::hasTable('rank_position_mappings')) {
                Schema::drop('rank_position_mappings');
            }

            if (Schema::hasTable('ranks')) {
                Schema::drop('ranks');
            }
        });

        $this->removeRankPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Phase 3B Rank removal is irreversible. Restore a database backup and redeploy the previous application version.'
        );
    }

    private function assertNoOrphanRankColumns(): void
    {
        foreach ($this->rankIdTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rank_id')) {
                continue;
            }

            if (! Schema::hasColumn($table, 'position_id')) {
                throw new RuntimeException(
                    "Rank removal blocked — {$table} still has rank_id but no position_id column."
                );
            }

            // Include soft-deleted historical rows — SoftDeletes must not hide orphans.
            $orphanCount = DB::table($table)
                ->whereNotNull('rank_id')
                ->whereNull('position_id')
                ->count();

            if ($orphanCount > 0) {
                throw new RuntimeException(
                    "Rank removal blocked — {$table} has {$orphanCount} row(s) with rank_id and null position_id."
                );
            }
        }
    }

    private function dropRankIdFromTable(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rank_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($table === 'vessel_manning') {
            $this->replaceVesselManningUniqueIndex($driver);
        }

        if ($table === 'crew_planning_assignments') {
            $this->dropIndexIfExists($table, 'cpa_company_vessel_rank', ['company_id', 'vessel_id', 'rank_id']);
        }

        if ($driver === 'sqlite') {
            $this->dropRankIdSqlite($table);
        } else {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $driver): void {
                $this->dropForeignForColumn($blueprint, $driver, $table.'_rank_id_foreign', 'rank_id');

                if (Schema::hasColumn($table, 'rank_id')) {
                    $blueprint->dropColumn('rank_id');
                }
            });
        }

        if ($table === 'crew_planning_assignments' && Schema::hasColumn($table, 'position_id')) {
            $this->ensureIndex($table, 'cpa_company_vessel_position', ['company_id', 'vessel_id', 'position_id']);
        }
    }

    /**
     * SQLite cannot ALTER-DROP a column that still appears in an FK definition.
     * Fresh installs use dropConstrainedForeignId; upgrade-path tests may re-add
     * rank_id without an FK, in which case a plain dropColumn is required.
     */
    private function dropRankIdSqlite(string $table): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('rank_id');
            });
        } catch (Throwable) {
            if (! Schema::hasColumn($table, 'rank_id')) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('rank_id');
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexIfExists(string $table, string $indexName, array $columns): void
    {
        $sm = Schema::getConnection()->getSchemaBuilder();
        $indexes = method_exists($sm, 'getIndexes')
            ? collect($sm->getIndexes($table))->pluck('name')->all()
            : [];

        if ($indexes !== [] && ! in_array($indexName, $indexes, true)) {
            // Named index absent — also skip column-based drop when we can enumerate.
            $hasColumnIndex = collect(method_exists($sm, 'getIndexes') ? $sm->getIndexes($table) : [])
                ->contains(function (array $index) use ($columns): bool {
                    $indexColumns = $index['columns'] ?? [];

                    return $indexColumns === $columns;
                });

            if (! $hasColumnIndex) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        } catch (Throwable) {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                    $blueprint->dropIndex($columns);
                });
            } catch (Throwable) {
                // Index already absent.
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        $sm = Schema::getConnection()->getSchemaBuilder();
        $indexes = method_exists($sm, 'getIndexes')
            ? collect($sm->getIndexes($table))->pluck('name')->all()
            : [];

        if (in_array($indexName, $indexes, true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $columns): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function replaceVesselManningUniqueIndex(string $driver): void
    {
        // Defense in depth: never drop the Rank unique / create Position unique
        // while duplicate (company, vessel, position) rows exist.
        $this->assertNoVesselManningPositionCollisions();

        try {
            Schema::table('vessel_manning', function (Blueprint $table): void {
                $table->dropUnique('uq_vessel_manning_company_vessel_rank');
            });
        } catch (Throwable) {
            try {
                Schema::table('vessel_manning', function (Blueprint $table): void {
                    $table->dropUnique(['company_id', 'vessel_id', 'rank_id']);
                });
            } catch (Throwable) {
                // Unique may already be absent on some environments.
            }
        }

        if (Schema::hasColumn('vessel_manning', 'position_id')) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = method_exists($sm, 'getIndexes')
                ? collect($sm->getIndexes('vessel_manning'))->pluck('name')->all()
                : [];

            if (! in_array('uq_vessel_manning_company_vessel_position', $indexes, true)) {
                Schema::table('vessel_manning', function (Blueprint $table): void {
                    $table->unique(
                        ['company_id', 'vessel_id', 'position_id'],
                        'uq_vessel_manning_company_vessel_position',
                    );
                });
            }
        }
    }

    private function assertNoVesselManningPositionCollisions(): void
    {
        $report = (new RankRemovalReadiness)->report();
        $collisions = (int) ($report['totals']['vessel_manning_position_collisions'] ?? 0);

        if ($collisions > 0) {
            throw new RuntimeException(
                "Rank removal blocked — vessel_manning_position_collisions={$collisions}. "
                .'Resolve duplicate (company, vessel, position) Vessel Manning rows before replacing the unique index.'
            );
        }
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

    private function removeRankPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = Permission::query()
            ->whereIn('name', $this->rankPermissionNames)
            ->where('guard_name', 'web')
            ->get();

        if ($permissions->isEmpty()) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        foreach ($permissions as $permission) {
            DB::table($roleHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->delete();

            DB::table($modelHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->delete();

            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
