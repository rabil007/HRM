<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the retired Joining candidate stage into Offer/JOL.
 *
 * Active candidates previously in `joining` are normalized to `offer_jol`.
 * Accepted offer status, readiness fields, expected/actual joining dates, and
 * employee links are preserved. Historical stage transition rows that record
 * `joining` are intentionally left unchanged for audit compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('recruitment_candidates')
            ->where('stage', 'joining')
            ->update(['stage' => 'offer_jol']);
    }

    public function down(): void
    {
        // Irreversible: cannot distinguish which Offer/JOL rows were formerly Joining.
    }
};
