<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Support\CrewOperations\CrewOperationsSettings;

function enableCrewNotificationsForUser(int $companyId, int $userId, array $overrides = []): void
{
    CrewOperationsSettings::saveSettings(
        $companyId,
        [],
        30,
        true,
        array_merge([
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [$userId],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
        ], $overrides),
    );
}

function createOverdueAssignmentForAlerts(array $fixtures): mixed
{
    return makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Notify Vessel '.fake()->unique()->numerify('###')),
        [
            'tour_of_duty_days' => 90,
            'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-01 00:00:00',
        ],
    );
}
