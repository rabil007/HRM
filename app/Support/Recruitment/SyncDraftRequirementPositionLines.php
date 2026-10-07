<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Support\Settings\CompanyCurrency;

/**
 * Synchronize Draft/Returned position lines from requester preparation payloads.
 *
 * Submitted lines represent the desired current state (add, edit, remove).
 */
final class SyncDraftRequirementPositionLines
{
    /**
     * @param  list<array<string, mixed>>  $lineInputs
     */
    public static function sync(
        RecruitmentRequirement $requirement,
        array $lineInputs,
        int $companyId,
    ): void {
        if (! $requirement->status->isEditable()) {
            return;
        }

        $existingLines = $requirement->lines()->lockForUpdate()->get();
        $companyCurrency = CompanyCurrency::codeForCompany($companyId);
        $keptLineIds = [];

        foreach ($lineInputs as $lineInput) {
            if (! is_array($lineInput) || ! filled($lineInput['position_id'] ?? null)) {
                continue;
            }

            $positionId = (int) $lineInput['position_id'];
            $headcount = (int) ($lineInput['required_headcount'] ?? 1);

            $hasSalary = (isset($lineInput['salary_min']) && $lineInput['salary_min'] !== null && $lineInput['salary_min'] !== '')
                || (isset($lineInput['salary_max']) && $lineInput['salary_max'] !== null && $lineInput['salary_max'] !== '');

            /** @var RecruitmentRequirementLine|null $targetLine */
            $targetLine = null;
            if (! empty($lineInput['id'])) {
                $targetLine = $existingLines->firstWhere('id', (int) $lineInput['id']);
            }

            if ($targetLine === null) {
                $targetLine = $existingLines
                    ->filter(fn (RecruitmentRequirementLine $line): bool => ! in_array((int) $line->id, $keptLineIds, true))
                    ->firstWhere('position_id', $positionId);
            }

            if ($targetLine !== null) {
                $targetLine->update([
                    'position_id' => $positionId,
                    'required_headcount' => $headcount,
                    'line_notes' => $lineInput['line_notes'] ?? null,
                    'salary_min' => isset($lineInput['salary_min']) && $lineInput['salary_min'] !== '' ? $lineInput['salary_min'] : null,
                    'salary_max' => isset($lineInput['salary_max']) && $lineInput['salary_max'] !== '' ? $lineInput['salary_max'] : null,
                    'salary_currency_code' => $hasSalary ? $companyCurrency : null,
                ]);
                $keptLineIds[] = (int) $targetLine->id;

                continue;
            }

            $created = RecruitmentRequirementLine::create([
                'company_id' => $companyId,
                'recruitment_requirement_id' => $requirement->id,
                'position_id' => $positionId,
                'required_headcount' => $headcount,
                'line_notes' => $lineInput['line_notes'] ?? null,
                'status' => RequirementLineStatus::Open,
                'salary_min' => isset($lineInput['salary_min']) && $lineInput['salary_min'] !== '' ? $lineInput['salary_min'] : null,
                'salary_max' => isset($lineInput['salary_max']) && $lineInput['salary_max'] !== '' ? $lineInput['salary_max'] : null,
                'salary_currency_code' => $hasSalary ? $companyCurrency : null,
            ]);
            $keptLineIds[] = (int) $created->id;
            $existingLines->push($created);
        }

        foreach ($existingLines as $line) {
            if (in_array((int) $line->id, $keptLineIds, true)) {
                continue;
            }

            if ($line->status === RequirementLineStatus::Open) {
                $line->delete();
            }
        }
    }
}
