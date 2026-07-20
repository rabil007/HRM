<?php

namespace Database\Factories;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewTimesheetPreparationLine>
 */
class CrewTimesheetPreparationLineFactory extends Factory
{
    protected $model = CrewTimesheetPreparationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromDate = now()->startOfMonth()->toDateString();
        $toDate = now()->startOfMonth()->addDays(4)->toDateString();

        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via forPreparation()');
            },
            'crew_timesheet_preparation_id' => static function (): int {
                throw new \InvalidArgumentException('crew_timesheet_preparation_id must be set via forPreparation()');
            },
            'employee_id' => static function (): int {
                throw new \InvalidArgumentException('employee_id must be set explicitly');
            },
            'crew_assignment_id' => static function (): int {
                throw new \InvalidArgumentException('crew_assignment_id must be set explicitly');
            },
            'crew_assignment_phase_id' => null,
            'phase_code' => CrewPhaseCode::OnVessel,
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'days' => 5,
            'source_actual_start_at' => null,
            'source_actual_end_at' => null,
            'warning_code' => null,
            'remarks' => null,
        ];
    }

    public function forPreparation(CrewTimesheetPreparation $preparation): static
    {
        return $this->state(fn () => [
            'company_id' => $preparation->company_id,
            'crew_timesheet_preparation_id' => $preparation->id,
        ]);
    }

    public function forAssignment(CrewAssignment $assignment, ?CrewAssignmentPhase $phase = null): static
    {
        return $this->state(fn () => [
            'company_id' => $assignment->company_id,
            'employee_id' => $assignment->employee_id,
            'crew_assignment_id' => $assignment->id,
            'crew_assignment_phase_id' => $phase?->id,
            'phase_code' => $phase?->phase_code ?? CrewPhaseCode::OnVessel,
        ]);
    }
}
