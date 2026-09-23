<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Support\Recruitment\GenerateRequirementNumber;
use Illuminate\Support\Facades\DB;

final class RepeatRequirementAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        RecruitmentRequirement $sourceRequirement,
        int $userId,
        array $data,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($sourceRequirement, $userId, $data): RecruitmentRequirement {
            $companyId = (int) $sourceRequirement->company_id;
            $newNumber = GenerateRequirementNumber::next($companyId);

            $newRequirement = RecruitmentRequirement::create([
                'company_id' => $companyId,
                'requirement_number' => $newNumber,
                'client_id' => $sourceRequirement->client_id,
                'project_id' => $sourceRequirement->project_id,
                'client_reference_number' => $sourceRequirement->client_reference_number,
                'request_received_date' => $data['request_received_date'],
                'required_by_date' => $data['required_by_date'],
                'location' => $data['location'] ?? $sourceRequirement->location,
                'priority' => $data['priority'] ?? $sourceRequirement->priority,
                'assigned_to' => $data['assigned_to'] ?? $sourceRequirement->assigned_to,
                'notes' => $data['notes'] ?? null,
                'status' => RequirementStatus::Draft,
                'repeated_from_id' => $sourceRequirement->id,
                'opened_at' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            foreach ($data['lines'] as $lineInput) {
                RecruitmentRequirementLine::create([
                    'company_id' => $companyId,
                    'recruitment_requirement_id' => $newRequirement->id,
                    'position_id' => $lineInput['position_id'],
                    'required_headcount' => $lineInput['required_headcount'],
                    'line_notes' => $lineInput['line_notes'] ?? null,
                    'status' => RequirementLineStatus::Open,
                ]);
            }

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($newRequirement)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $newNumber,
                    'repeated_from_id' => $sourceRequirement->id,
                    'repeated_from_number' => $sourceRequirement->requirement_number,
                ])
                ->log("Requirement {$newNumber} repeated from {$sourceRequirement->requirement_number}.");

            return $newRequirement->load(['lines.position', 'client', 'project']);
        });
    }
}
