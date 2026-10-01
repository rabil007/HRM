<?php

use App\Support\MasterData\Migrations\BackfillRankToPositionBeforeRemoval;
use Illuminate\Database\Migrations\Migration;

/**
 * Automatic Rank→Position mapping + operational data backfill.
 *
 * Runs after Phase 1 schema migrations and before saved-view conversion /
 * destructive Rank removal so a never-prepared production database can migrate
 * safely through this single PR.
 *
 * Non-destructive: never overwrites existing position_id values and never drops
 * Rank schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new BackfillRankToPositionBeforeRemoval)->run();
    }

    public function down(): void
    {
        // Intentionally empty: mappings and backfilled position_id values are retained.
    }
};
