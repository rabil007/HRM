<?php

namespace App\Support\MasterData;

use App\Models\SavedView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Tenant-scoped conversion of persisted saved-view rank_id filters to position_id.
 */
final class MigrateSavedViewRankFilters
{
    /**
     * @return array{converted: int, skipped: int}
     */
    public function apply(): array
    {
        $converted = 0;
        $skipped = 0;

        SavedView::query()
            ->orderBy('id')
            ->chunkById(100, function ($views) use (&$converted, &$skipped): void {
                foreach ($views as $view) {
                    $result = $this->convertView($view);

                    if ($result === 'converted') {
                        $converted++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    }
                }
            });

        return [
            'converted' => $converted,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return 'converted'|'skipped'|'unchanged'
     */
    private function convertView(SavedView $view): string
    {
        $filters = is_array($view->filters) ? $view->filters : [];

        if (! array_key_exists('rank_id', $filters)) {
            return 'unchanged';
        }

        $legacyRankId = $filters['rank_id'];
        $existingPositionId = $filters['position_id'] ?? null;
        $rankId = (int) $legacyRankId;
        $companyId = (int) $view->company_id;

        if ($rankId < 1) {
            throw new RuntimeException(
                "Saved view #{$view->id} has an invalid rank_id filter that cannot be migrated."
            );
        }

        if (! Schema::hasTable('rank_position_mappings')) {
            throw new RuntimeException(
                "Saved view #{$view->id} (company #{$companyId}) has rank_id={$rankId} but rank_position_mappings is absent."
            );
        }

        $mappedPositionId = DB::table('rank_position_mappings')
            ->where('company_id', $companyId)
            ->where('rank_id', $rankId)
            ->value('position_id');

        if ($mappedPositionId === null) {
            throw new RuntimeException(
                "Saved view #{$view->id} (company #{$companyId}) has rank_id={$rankId} with no tenant Rank→Position mapping."
            );
        }

        $mappedPositionId = (int) $mappedPositionId;

        if ($existingPositionId !== null && $existingPositionId !== '') {
            $existingPositionId = (int) $existingPositionId;

            if ($existingPositionId !== $mappedPositionId) {
                throw new RuntimeException(
                    "Saved view #{$view->id} (company #{$companyId}) has conflicting filters: "
                    ."rank_id={$rankId} maps to position_id={$mappedPositionId} but filters already contain position_id={$existingPositionId}."
                );
            }

            $this->assertUsableCompanyPosition($view->id, $companyId, $existingPositionId);

            unset($filters['rank_id']);
            $view->forceFill(['filters' => $filters])->save();

            return 'converted';
        }

        $this->assertUsableCompanyPosition($view->id, $companyId, $mappedPositionId);

        $filters['position_id'] = $mappedPositionId;
        unset($filters['rank_id']);

        $view->forceFill(['filters' => $filters])->save();

        return 'converted';
    }

    private function assertUsableCompanyPosition(int $viewId, int $companyId, int $positionId): void
    {
        $position = DB::table('positions')->where('id', $positionId)->first();

        if ($position === null) {
            throw new RuntimeException(
                "Saved view #{$viewId} (company #{$companyId}) references missing position_id={$positionId}."
            );
        }

        if ((int) $position->company_id !== $companyId) {
            throw new RuntimeException(
                "Saved view #{$viewId} (company #{$companyId}) references cross-company position_id={$positionId}."
            );
        }

        if ($position->deleted_at !== null) {
            throw new RuntimeException(
                "Saved view #{$viewId} (company #{$companyId}) references soft-deleted position_id={$positionId}."
            );
        }
    }
}
