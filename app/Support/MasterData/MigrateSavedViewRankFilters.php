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

        if ($existingPositionId !== null && $existingPositionId !== '') {
            unset($filters['rank_id']);
            $view->forceFill(['filters' => $filters])->save();

            return 'converted';
        }

        $rankId = (int) $legacyRankId;

        if ($rankId < 1) {
            throw new RuntimeException(
                "Saved view #{$view->id} has an invalid rank_id filter that cannot be migrated."
            );
        }

        if (! Schema::hasTable('rank_position_mappings')) {
            throw new RuntimeException(
                "Saved view #{$view->id} (company #{$view->company_id}) has rank_id={$rankId} but rank_position_mappings is absent."
            );
        }

        $mappedPositionId = DB::table('rank_position_mappings')
            ->where('company_id', (int) $view->company_id)
            ->where('rank_id', $rankId)
            ->value('position_id');

        if ($mappedPositionId === null) {
            throw new RuntimeException(
                "Saved view #{$view->id} (company #{$view->company_id}) has rank_id={$rankId} with no tenant Rank→Position mapping."
            );
        }

        $filters['position_id'] = (int) $mappedPositionId;
        unset($filters['rank_id']);

        $view->forceFill(['filters' => $filters])->save();

        return 'converted';
    }
}
