<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function crewNotificationSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'pool_department_ids' => [],
        'max_home_days' => 30,
        'sync_sea_service' => true,
        'notifications_enabled' => false,
        'notification_recipient_user_ids' => [],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notify_in_app' => true,
        'notify_browser_push' => true,
        'notify_email' => false,
    ], $overrides);
}

function enableCrewNotifications(int $companyId, array $overrides = []): void
{
    CrewOperationsSettings::saveSettings(
        $companyId,
        [],
        30,
        true,
        array_merge([
            'notifications_enabled' => true,
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
        ], $overrides),
    );
}

test('crew notifications default off when no settings row exists', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    expect(CrewOperationsSettings::notificationsEnabled((int) $company->id))->toBeFalse()
        ->and(CrewOperationsSettings::notificationSettings((int) $company->id)['notifications_enabled'])->toBeFalse();
});

test('settings page exposes notification defaults and recipient options', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-operations.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('crew_settings.notifications_enabled', false)
            ->where('crew_settings.alert_signoff_overdue', true)
            ->where('crew_settings.notify_email', false)
            ->has('notification_users')
            ->where('notification_users.0.id', $fixtures['user']->id)
        );
});

test('authorized user can enable notifications recipients and alert types', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $this->actingAs($fixtures['user'])
        ->put(route('organization.crew-operations.settings.update'), crewNotificationSettingsPayload([
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [$fixtures['user']->id],
            'alert_signoff_overdue' => true,
            'alert_projected_manning_gap' => false,
            'notify_email' => true,
        ]))
        ->assertRedirect();

    $setting = CrewOperationsSetting::query()->where('company_id', $fixtures['company']->id)->first();

    expect($setting)->not->toBeNull()
        ->and($setting->notifications_enabled)->toBeTrue()
        ->and($setting->notification_recipient_user_ids)->toBe([$fixtures['user']->id])
        ->and($setting->alert_projected_manning_gap)->toBeFalse()
        ->and($setting->notify_email)->toBeTrue();
});

test('settings reject recipients from another company', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $other = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $this->actingAs($fixtures['user'])
        ->put(route('organization.crew-operations.settings.update'), crewNotificationSettingsPayload([
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [$other['user']->id],
        ]))
        ->assertSessionHasErrors(['notification_recipient_user_ids']);
});

test('inactive membership users are rejected as recipients', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $inactive = User::factory()->create();
    $inactive->companies()->attach($fixtures['company']->id, ['status' => 'inactive']);

    $this->actingAs($fixtures['user'])
        ->put(route('organization.crew-operations.settings.update'), crewNotificationSettingsPayload([
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [$inactive->id],
        ]))
        ->assertSessionHasErrors(['notification_recipient_user_ids']);
});

test('signoff overdue creates a company scoped alert', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotifications($companyId);

    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Overdue Alert Vessel'),
        [
            'tour_of_duty_days' => 90,
            'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-01 00:00:00',
        ],
    );

    $result = app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect($result['created'])->toBe(1)
        ->and(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(1);

    $alert = CrewOperationalAlert::query()->where('company_id', $companyId)->first();

    expect($alert->type)->toBe(CrewOperationalAlertType::SignoffOverdue)
        ->and($alert->status)->toBe(CrewOperationalAlertStatus::Active)
        ->and($alert->dedupe_key)->toBe('signoff_overdue:assignment:'.$assignment->id)
        ->and($alert->context['assignment_id'])->toBe($assignment->id);

    CarbonImmutable::setTestNow();
});

test('signoff no relief and relief not ready create distinct alerts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotifications($companyId);

    $noRelief = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('No Relief Alert'),
        ['planned_signoff_at' => '2026-08-12 00:00:00'],
    );

    $readyEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $readyEmployee,
        $fixtures['rank'],
        makeCrewMovementVessel('Not Ready Source'),
        ['planned_signoff_at' => '2026-08-10 00:00:00'],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $companyId,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-08-10',
        'planned_leave_date' => '2026-11-10',
    ]);
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::TravelIn,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlert::query()
        ->where('company_id', $companyId)
        ->where('type', CrewOperationalAlertType::SignoffNoRelief)
        ->where('dedupe_key', 'signoff_no_relief:assignment:'.$noRelief->id)
        ->exists())->toBeTrue()
        ->and(CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('type', CrewOperationalAlertType::ReliefNotReady)
            ->where('dedupe_key', 'relief_not_ready:assignment:'.$source->id)
            ->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('current and projected manning gaps create alerts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotifications($companyId);

    $currentVessel = makeCrewMovementVessel('Current Gap Alert Vessel');
    VesselManning::query()->create([
        'company_id' => $companyId,
        'vessel_id' => $currentVessel->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 1,
    ]);

    $futureVessel = makeCrewMovementVessel('Future Gap Alert Vessel');
    VesselManning::query()->create([
        'company_id' => $companyId,
        'vessel_id' => $futureVessel->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 1,
    ]);
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $futureVessel,
        ['planned_signoff_at' => '2026-08-10 00:00:00'],
    );

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect(CrewOperationalAlert::query()
        ->where('company_id', $companyId)
        ->where('type', CrewOperationalAlertType::CurrentManningGap)
        ->where('dedupe_key', 'current_manning_gap:vessel:'.$currentVessel->id.':rank:'.$fixtures['rank']->id)
        ->exists())->toBeTrue()
        ->and(CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('type', CrewOperationalAlertType::ProjectedManningGap)
            ->where('dedupe_key', 'projected_manning_gap:vessel:'.$futureVessel->id.':rank:'.$fixtures['rank']->id)
            ->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('disabled alert type and notifications off create no alerts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Disabled Type Vessel'),
        ['planned_signoff_at' => '2026-08-01 00:00:00'],
    );

    enableCrewNotifications($companyId, ['alert_signoff_overdue' => false]);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(0);

    enableCrewNotifications($companyId, [
        'notifications_enabled' => false,
        'alert_signoff_overdue' => true,
    ]);
    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    expect(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('reconciliation is idempotent and resolves fixed conditions', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotifications($companyId);

    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Idempotent Vessel'),
        ['planned_signoff_at' => '2026-08-01 00:00:00'],
    );

    $first = app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);
    $second = app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(1)
        ->and(CrewOperationalAlert::query()->where('company_id', $companyId)->count())->toBe(1);

    $assignment->update(['planned_signoff_at' => '2026-09-01 00:00:00']);
    $third = app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    expect($third['resolved'])->toBe(1)
        ->and(CrewOperationalAlert::query()
            ->where('company_id', $companyId)
            ->where('status', CrewOperationalAlertStatus::Resolved)
            ->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('company reconciliation is isolated and command is idempotent', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));
    $companyA = makeCrewAssignmentFixtures();
    $companyB = makeCrewAssignmentFixtures();

    enableCrewNotifications((int) $companyA['company']->id);
    enableCrewNotifications((int) $companyB['company']->id);

    makeActiveOnVesselAssignment(
        $companyA['company'],
        $companyA['employee'],
        $companyA['rank'],
        makeCrewMovementVessel('Company A Alert'),
        ['planned_signoff_at' => '2026-08-01 00:00:00'],
    );
    makeActiveOnVesselAssignment(
        $companyB['company'],
        $companyB['employee'],
        $companyB['rank'],
        makeCrewMovementVessel('Company B Alert'),
        ['planned_signoff_at' => '2026-08-01 00:00:00'],
    );

    app(ReconcileCrewOperationalAlerts::class)->forCompany((int) $companyA['company']->id);

    expect(CrewOperationalAlert::query()->where('company_id', $companyA['company']->id)->count())->toBe(1)
        ->and(CrewOperationalAlert::query()->where('company_id', $companyB['company']->id)->count())->toBe(0);

    $this->artisan('crew:reconcile-operational-alerts')
        ->assertSuccessful();

    expect(CrewOperationalAlert::query()->where('company_id', $companyA['company']->id)->count())->toBe(1)
        ->and(CrewOperationalAlert::query()->where('company_id', $companyB['company']->id)->count())->toBe(1);

    $this->artisan('crew:reconcile-operational-alerts')
        ->assertSuccessful();

    expect(CrewOperationalAlert::query()->where('status', CrewOperationalAlertStatus::Active)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('company local dates are respected for overdue detection', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Pacific/Kiritimati']);
    $companyId = (int) $fixtures['company']->id;
    enableCrewNotifications($companyId);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 10:00:00', 'UTC'));

    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('TZ Overdue Vessel'),
        ['planned_signoff_at' => '2026-08-07 00:00:00'],
    );

    app(ReconcileCrewOperationalAlerts::class)->forCompany($companyId);

    // Kiritimati is UTC+14, so 10:00 UTC is already 2026-08-08 locally → overdue.
    expect(CrewOperationalAlert::query()
        ->where('company_id', $companyId)
        ->where('type', CrewOperationalAlertType::SignoffOverdue)
        ->count())->toBe(1);

    CarbonImmutable::setTestNow();
});
