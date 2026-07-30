<?php

namespace Database\Factories;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewTimesheetSegment>
 */
class CrewTimesheetSegmentFactory extends Factory
{
    protected $model = CrewTimesheetSegment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->startOfMonth()->addDays(10)->toDateString();

        return [
            'company_id' => fn (array $attributes) => CrewTimesheet::query()
                ->whereKey($attributes['crew_timesheet_id'])
                ->value('company_id'),
            'crew_timesheet_id' => CrewTimesheet::factory(),
            'sequence' => 1,
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => $from,
            'to_date' => $to,
            'days' => 11,
            'source' => CrewTimesheetSource::Manual,
            'crew_assignment_id' => null,
            'crew_assignment_phase_id' => null,
            'crew_timesheet_preparation_line_id' => null,
            'vessel_id' => null,
            'client_id' => null,
            'rank_id' => null,
            'remarks' => null,
        ];
    }
}
