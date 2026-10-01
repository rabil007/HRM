<?php

namespace App\Support\MasterData\Migrations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent migration infrastructure: Rank→Position mapping + operational backfill.
 *
 * Retained so historical migrations (and fresh installs that still run them) remain
 * executable after the Rank catalog is removed from the application runtime.
 */
final class BackfillRankToPositionBeforeRemoval
{
    /**
     * @var list<string>
     */
    private const OPERATIONAL_TABLES = [
        'employees',
        'crew_assignments',
        'crew_planning_assignments',
        'employee_sea_services',
        'vessel_manning',
    ];

    /**
     * @return array{companies_processed: int, mappings_created: int, rows_backfilled: int, document_pivots_copied: int}
     */
    public function run(): array
    {
        if (! Schema::hasTable('ranks') || ! Schema::hasTable('rank_position_mappings')) {
            return [
                'companies_processed' => 0,
                'mappings_created' => 0,
                'rows_backfilled' => 0,
                'document_pivots_copied' => 0,
            ];
        }

        $summary = [
            'companies_processed' => 0,
            'mappings_created' => 0,
            'rows_backfilled' => 0,
            'document_pivots_copied' => 0,
        ];

        $companyIds = DB::table('companies')->orderBy('id')->pluck('id');

        foreach ($companyIds as $companyId) {
            $companySummary = DB::transaction(fn (): array => $this->processCompany((int) $companyId));
            $summary['companies_processed']++;
            $summary['mappings_created'] += $companySummary['mappings_created'];
            $summary['rows_backfilled'] += $companySummary['rows_backfilled'];
            $summary['document_pivots_copied'] += $companySummary['document_pivots_copied'];
        }

        return $summary;
    }

    /**
     * @return array{mappings_created: int, rows_backfilled: int, document_pivots_copied: int}
     */
    private function processCompany(int $companyId): array
    {
        $mappingsCreated = 0;
        $rowsBackfilled = 0;
        $documentPivotsCopied = 0;

        /** @var array<int, int> $rankToPosition */
        $rankToPosition = [];
        /** @var array<int, true> $skipRankIds */
        $skipRankIds = [];

        $existingMappings = DB::table('rank_position_mappings')
            ->where('company_id', $companyId)
            ->get(['id', 'rank_id', 'position_id']);

        foreach ($existingMappings as $mapping) {
            $rankId = (int) $mapping->rank_id;
            $positionId = (int) $mapping->position_id;
            $position = $this->findPosition($positionId);

            if ($position === null
                || (int) $position->company_id !== $companyId
                || $position->deleted_at !== null
            ) {
                $skipRankIds[$rankId] = true;

                continue;
            }

            $rankToPosition[$rankId] = $positionId;
        }

        $positionsByNormalized = $this->positionsByNormalizedTitle($companyId);

        foreach ($this->ranksNeededForCompany($companyId) as $rank) {
            $rankId = (int) $rank->id;

            if (isset($skipRankIds[$rankId]) || isset($rankToPosition[$rankId])) {
                continue;
            }

            $normalized = $this->normalizeTitle((string) $rank->name);
            $candidates = $positionsByNormalized->get($normalized, collect());

            if ($candidates->count() > 1) {
                // Ambiguous exact-normalized match — leave unmapped for readiness to report.
                continue;
            }

            if ($candidates->count() === 1) {
                $position = $candidates->first();
                $positionId = (int) $position->id;

                $this->ensurePositionCrewMetadata($position, $rank);
                $this->ensureMapping($companyId, $rankId, $positionId, 'exact');
                $rankToPosition[$rankId] = $positionId;
                $mappingsCreated++;
                $positionsByNormalized = $this->positionsByNormalizedTitle($companyId);

                continue;
            }

            $positionId = $this->createPositionFromRank($companyId, $rank);
            $this->ensureMapping($companyId, $rankId, $positionId, 'created');
            $rankToPosition[$rankId] = $positionId;
            $mappingsCreated++;
            $positionsByNormalized = $this->positionsByNormalizedTitle($companyId);
        }

        foreach (self::OPERATIONAL_TABLES as $table) {
            $rowsBackfilled += $this->backfillOperationalTable($table, $companyId, $rankToPosition);
        }

        $documentPivotsCopied += $this->backfillDocumentRequirements($companyId, $rankToPosition);

        return [
            'mappings_created' => $mappingsCreated,
            'rows_backfilled' => $rowsBackfilled,
            'document_pivots_copied' => $documentPivotsCopied,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function ranksNeededForCompany(int $companyId): Collection
    {
        $referencedRankIds = collect();

        foreach (self::OPERATIONAL_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rank_id')) {
                continue;
            }

            $referencedRankIds = $referencedRankIds->merge(
                DB::table($table)
                    ->where('company_id', $companyId)
                    ->whereNotNull('rank_id')
                    ->distinct()
                    ->pluck('rank_id')
            );
        }

        if (Schema::hasTable('document_requirement_rank') && Schema::hasTable('document_requirements')) {
            $referencedRankIds = $referencedRankIds->merge(
                DB::table('document_requirement_rank as drr')
                    ->join('document_requirements as dr', 'dr.id', '=', 'drr.document_requirement_id')
                    ->where('dr.company_id', $companyId)
                    ->distinct()
                    ->pluck('drr.rank_id')
            );
        }

        if (Schema::hasTable('saved_views')) {
            $savedViewRankIds = DB::table('saved_views')
                ->where('company_id', $companyId)
                ->get(['filters'])
                ->map(function (object $view): ?int {
                    $filters = is_string($view->filters)
                        ? json_decode($view->filters, true)
                        : $view->filters;

                    if (! is_array($filters) || ! array_key_exists('rank_id', $filters)) {
                        return null;
                    }

                    $rankId = (int) $filters['rank_id'];

                    return $rankId > 0 ? $rankId : null;
                })
                ->filter();

            $referencedRankIds = $referencedRankIds->merge($savedViewRankIds);
        }

        $referencedRankIds = $referencedRankIds
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $query = DB::table('ranks')->orderBy('id');

        $query->where(function ($builder) use ($referencedRankIds): void {
            $builder->where(function ($active): void {
                $active->where('is_active', true);

                if (Schema::hasColumn('ranks', 'deleted_at')) {
                    $active->whereNull('deleted_at');
                }
            });

            if ($referencedRankIds->isNotEmpty()) {
                $builder->orWhereIn('id', $referencedRankIds->all());
            }
        });

        return $query->get();
    }

    /**
     * @return Collection<string, Collection<int, object>>
     */
    private function positionsByNormalizedTitle(int $companyId): Collection
    {
        $query = DB::table('positions')
            ->where('company_id', $companyId)
            ->select(['id', 'company_id', 'title', 'status', 'is_crew_position', 'max_tour_of_duty_days', 'deleted_at']);

        if (Schema::hasColumn('positions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->get()
            ->groupBy(fn (object $position): string => $this->normalizeTitle((string) $position->title));
    }

    private function ensurePositionCrewMetadata(object $position, object $rank): void
    {
        $updates = [];

        if (! (bool) ($position->is_crew_position ?? false)) {
            $updates['is_crew_position'] = true;
        }

        $rankTod = $rank->max_tour_of_duty_days ?? null;
        $positionTod = $position->max_tour_of_duty_days ?? null;

        if ($positionTod === null && $rankTod !== null) {
            $updates['max_tour_of_duty_days'] = (int) $rankTod;
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('positions')
            ->where('id', (int) $position->id)
            ->where('company_id', (int) $position->company_id)
            ->update($updates);
    }

    private function createPositionFromRank(int $companyId, object $rank): int
    {
        $rankDeleted = Schema::hasColumn('ranks', 'deleted_at') && ($rank->deleted_at ?? null) !== null;
        $isSelectable = ! $rankDeleted && (bool) ($rank->is_active ?? true);

        return (int) DB::table('positions')->insertGetId([
            'company_id' => $companyId,
            'department_id' => null,
            'title' => (string) $rank->name,
            'status' => $isSelectable ? 'active' : 'inactive',
            'is_crew_position' => true,
            'max_tour_of_duty_days' => $rank->max_tour_of_duty_days ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureMapping(int $companyId, int $rankId, int $positionId, string $matchType): void
    {
        $existing = DB::table('rank_position_mappings')
            ->where('company_id', $companyId)
            ->where('rank_id', $rankId)
            ->first();

        if ($existing !== null) {
            return;
        }

        DB::table('rank_position_mappings')->insert([
            'company_id' => $companyId,
            'rank_id' => $rankId,
            'position_id' => $positionId,
            'match_type' => $matchType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, int>  $rankToPosition
     */
    private function backfillOperationalTable(string $table, int $companyId, array $rankToPosition): int
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'rank_id')
            || ! Schema::hasColumn($table, 'position_id')
        ) {
            return 0;
        }

        $backfilled = 0;

        $rows = DB::table($table)
            ->where('company_id', $companyId)
            ->whereNotNull('rank_id')
            ->whereNull('position_id')
            ->get(['id', 'rank_id']);

        foreach ($rows as $row) {
            $mappedPositionId = $rankToPosition[(int) $row->rank_id] ?? null;

            if ($mappedPositionId === null) {
                continue;
            }

            DB::table($table)
                ->where('id', (int) $row->id)
                ->whereNull('position_id')
                ->update([
                    'position_id' => $mappedPositionId,
                    'updated_at' => now(),
                ]);

            $backfilled++;
        }

        return $backfilled;
    }

    /**
     * @param  array<int, int>  $rankToPosition
     */
    private function backfillDocumentRequirements(int $companyId, array $rankToPosition): int
    {
        if (! Schema::hasTable('document_requirement_rank')
            || ! Schema::hasTable('document_requirement_position')
            || ! Schema::hasTable('document_requirements')
        ) {
            return 0;
        }

        $copied = 0;

        $rows = DB::table('document_requirement_rank as drr')
            ->join('document_requirements as dr', 'dr.id', '=', 'drr.document_requirement_id')
            ->where('dr.company_id', $companyId)
            ->select(['drr.document_requirement_id', 'drr.rank_id'])
            ->get();

        foreach ($rows as $row) {
            $mappedPositionId = $rankToPosition[(int) $row->rank_id] ?? null;

            if ($mappedPositionId === null) {
                continue;
            }

            $exists = DB::table('document_requirement_position')
                ->where('document_requirement_id', (int) $row->document_requirement_id)
                ->where('position_id', $mappedPositionId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('document_requirement_position')->insert([
                'document_requirement_id' => (int) $row->document_requirement_id,
                'position_id' => $mappedPositionId,
            ]);

            $copied++;
        }

        return $copied;
    }

    private function findPosition(int $positionId): ?object
    {
        return DB::table('positions')->where('id', $positionId)->first();
    }

    public function normalizeTitle(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($collapsed);
    }
}
