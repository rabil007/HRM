<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Support\CrewOperations\CrewOperationsSettings;
use Illuminate\Support\Facades\DB;

function failNextCrewAlertSentLedgerPersists(string $table, int $times): void
{
    $remaining = $times;
    $pdo = DB::connection()->getPdo();

    expect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
    expect($pdo->sqliteCreateFunction(
        'crew_alert_fail_sent_persist',
        function () use (&$remaining): int {
            if ($remaining < 1) {
                return 0;
            }

            $remaining--;

            return 1;
        },
    ))->toBeTrue();

    DB::unprepared('DROP TRIGGER IF EXISTS fail_crew_alert_sent_persist');
    DB::unprepared("
        CREATE TRIGGER fail_crew_alert_sent_persist
        BEFORE UPDATE ON {$table}
        WHEN NEW.status = 'sent'
         AND OLD.status = 'queued'
         AND crew_alert_fail_sent_persist() = 1
        BEGIN
            SELECT RAISE(ABORT, 'ledger persist failed');
        END
    ");
}

function dropCrewAlertSentLedgerPersistTrigger(): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS fail_crew_alert_sent_persist');
}

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
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-01 00:00:00',
        ],
    );
}

function createWarningApproachingSignoffAssignmentForAlerts(array $fixtures): mixed
{
    return makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Approaching Vessel '.fake()->unique()->numerify('###')),
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-15 00:00:00',
        ],
    );
}
