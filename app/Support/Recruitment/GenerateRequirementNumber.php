<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementSequence;
use Illuminate\Support\Facades\DB;

final class GenerateRequirementNumber
{
    /**
     * Generate next sequential requirement number for the company and year.
     * Guaranteed concurrency-safe via database transaction and row-level locking.
     */
    public static function next(int $companyId, ?int $year = null): string
    {
        $year ??= (int) date('Y');

        return DB::transaction(function () use ($companyId, $year): string {
            // First check or create the sequence row
            $sequence = RecruitmentRequirementSequence::query()
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                // Determine initial current_number from existing requirements in case rows were seeded/created directly
                $maxExistingNumber = 0;
                $prefix = sprintf('REQ-%d-', $year);
                $latestRequirement = RecruitmentRequirement::query()
                    ->where('company_id', $companyId)
                    ->where('requirement_number', 'like', "{$prefix}%")
                    ->orderByDesc('requirement_number')
                    ->first();

                if ($latestRequirement !== null && preg_match('/^REQ-\d{4}-(\d+)$/', (string) $latestRequirement->requirement_number, $matches)) {
                    $maxExistingNumber = (int) $matches[1];
                }

                RecruitmentRequirementSequence::query()->insertOrIgnore([
                    'company_id' => $companyId,
                    'year' => $year,
                    'current_number' => $maxExistingNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = RecruitmentRequirementSequence::query()
                    ->where('company_id', $companyId)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $nextNumber = max($sequence->current_number + 1, 1);

            // Double check it doesn't collide if there was an existing record
            while (RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->where('requirement_number', sprintf('REQ-%d-%06d', $year, $nextNumber))
                ->exists()
            ) {
                $nextNumber++;
            }

            $sequence->update(['current_number' => $nextNumber]);

            return sprintf('REQ-%d-%06d', $year, $nextNumber);
        });
    }
}
