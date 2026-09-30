<?php

use App\Support\MasterData\MigrateSavedViewRankFilters;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 3B Migration A — convert persisted saved-view rank_id filters to position_id.
 *
 * Irreversible: original rank_id filter values are not restored on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new MigrateSavedViewRankFilters)->apply();
    }

    public function down(): void
    {
        // Irreversible: Rank filter values cannot be losslessly reconstructed after Position conversion.
    }
};
