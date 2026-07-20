<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;

final class CrewPhasePayCategoryResolver
{
    public function resolve(CrewPhaseCode $phaseCode): CrewTimesheetPayCategory
    {
        return match ($phaseCode) {
            CrewPhaseCode::PreMobilisation => CrewTimesheetPayCategory::Excluded,
            CrewPhaseCode::TravelIn => CrewTimesheetPayCategory::Excluded,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training,
            CrewPhaseCode::ReadyToJoin => CrewTimesheetPayCategory::SignOnStandby,
            CrewPhaseCode::OnVessel => CrewTimesheetPayCategory::Onsite,
            CrewPhaseCode::DemobStandby => CrewTimesheetPayCategory::SignOffStandby,
            CrewPhaseCode::HomeRedeploy => CrewTimesheetPayCategory::Excluded,
        };
    }

    public function priority(CrewTimesheetPayCategory $category): int
    {
        return match ($category) {
            CrewTimesheetPayCategory::Onsite => 400,
            CrewTimesheetPayCategory::SignOffStandby => 300,
            CrewTimesheetPayCategory::SignOnStandby => 200,
            CrewTimesheetPayCategory::Excluded => 100,
        };
    }
}
