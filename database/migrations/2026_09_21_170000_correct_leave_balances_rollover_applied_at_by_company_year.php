<?php

use App\Models\Company;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correct Phase 1 rollover_applied_at backfill to use each company's business year.
 *
 * Existing balances for year <= company local business year are already-open years
 * and must not look provisional. Future years remain eligible for later rollover.
 * Does not recalculate carried_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_balances', 'rollover_applied_at')) {
            return;
        }

        $now = now();

        Company::query()
            ->select(['id', 'timezone'])
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($now): void {
                foreach ($companies as $company) {
                    $businessYear = (int) now(CompanyTimezone::forCompany($company))->year;

                    DB::table('leave_balances')
                        ->where('company_id', $company->id)
                        ->whereNull('rollover_applied_at')
                        ->whereNull('deleted_at')
                        ->where('year', '<=', $businessYear)
                        ->update(['rollover_applied_at' => $now]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data correction: cannot distinguish rows opened by this
        // migration from rows opened by legitimate rollover without recalculating carry.
    }
};
