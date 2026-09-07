<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeTraining;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Synchronizes EmployeeTraining rows from completed Training (P2B) phases.
 *
 * Gated by company setting {@see CrewOperationsSettings::CONFIG_SYNC_TRAINING_TO_EMPLOYEE_TRAINING}
 * (`crew_operations.sync_training_to_employee_training`).
 */
final class SyncCrewTrainingToEmployeeTraining
{
    public function isEnabled(int $companyId): bool
    {
        return CrewOperationsSettings::syncTrainingToEmployeeTrainingEnabled($companyId);
    }

    public function syncFromPhase(CrewAssignmentPhase $phase, ?int $courseId = null): ?EmployeeTraining
    {
        $phase->loadMissing(['assignment.employee']);

        /** @var CrewAssignment|null $assignment */
        $assignment = $phase->assignment;
        if ($assignment === null) {
            return null;
        }

        $companyId = (int) $assignment->company_id;

        if ($companyId <= 0 || ! $this->isEnabled($companyId)) {
            return EmployeeTraining::query()
                ->where('source_crew_assignment_phase_id', $phase->id)
                ->first();
        }

        if (! $this->canSync($phase)) {
            return null;
        }

        $courseId = $courseId ?? (is_array($phase->details) ? ($phase->details['course_id'] ?? null) : null);
        if ($courseId === null || ! is_numeric($courseId)) {
            throw new InvalidArgumentException('Select a Course before adding this training to the employee\'s Training record.');
        }
        $courseId = (int) $courseId;

        $course = Course::query()->whereKey($courseId)->where('is_active', true)->first();
        if ($course === null) {
            throw new InvalidArgumentException('The selected Course is inactive or does not exist.');
        }

        if ((int) ($assignment->employee?->company_id ?? 0) !== $companyId) {
            throw new InvalidArgumentException('Employee does not belong to the assignment company.');
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $completionDate = Carbon::parse($phase->actual_end_at)->timezone($timezone)->toDateString();
        $provider = is_array($phase->details) ? (string) ($phase->details['provider'] ?? '') : '';

        $existing = EmployeeTraining::query()
            ->where('source_crew_assignment_phase_id', $phase->id)
            ->first();

        $syncOwnedAttributes = [
            'course_id' => $courseId,
            'issue_date' => $completionDate,
            'institute_center' => $provider,
        ];

        if ($existing !== null) {
            $existing->update($syncOwnedAttributes);

            return $existing->fresh();
        }

        $maxSort = EmployeeTraining::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        return EmployeeTraining::query()->create([
            'company_id' => $companyId,
            'employee_id' => $assignment->employee_id,
            ...$syncOwnedAttributes,
            'expiry_date' => null,
            'country_id' => null,
            'certificate_path' => null,
            'source_crew_assignment_phase_id' => $phase->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
        ]);
    }

    private function canSync(CrewAssignmentPhase $phase): bool
    {
        if ($phase->phase_code !== CrewPhaseCode::Training) {
            return false;
        }

        if ($phase->status !== CrewPhaseStatus::Completed) {
            return false;
        }

        if ($phase->actual_end_at === null) {
            return false;
        }

        return $phase->assignment !== null;
    }
}
