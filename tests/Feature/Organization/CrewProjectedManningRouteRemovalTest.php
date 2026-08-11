<?php

use App\Enums\CrewOperationalAlertType;
use App\Models\CrewOperationalAlert;
use App\Models\User;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;

test('dedicated projected manning route returns 404', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.planning.view',
    ]);

    $this->actingAs($user)
        ->withHeaders(['X-Company-Id' => (string) $company->id])
        ->get('/organization/crew-operations/projected-manning')
        ->assertNotFound();
});

test('ResolveCrewOperationalAlertUrl resolves ProjectedManningGap to Crew Planning when user has planning view permission', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'dedupe_key' => 'projected_manning_gap:vessel:'.$vessel->id.':rank:'.$rank->id,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => 'warning',
        'title' => 'Projected Manning Gap',
        'message' => 'Gap detected for vessel and rank',
        'summary' => 'Gap detected',
        'context' => [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ],
        'status' => 'active',
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($user->fresh(), $alert);

    expect($url)->toBe(route('organization.crew-planning.index', [
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ]));
});

test('ResolveCrewOperationalAlertUrl resolves ProjectedManningGap to Overview when user lacks planning view but has overview view', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'dedupe_key' => 'projected_manning_gap:vessel:'.$vessel->id.':rank:'.$rank->id,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => 'warning',
        'title' => 'Projected Manning Gap',
        'message' => 'Gap detected for vessel and rank',
        'summary' => 'Gap detected',
        'context' => [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ],
        'status' => 'active',
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($user->fresh(), $alert);

    expect($url)->toBe(route('organization.crew-operations.index'));
});

test('ResolveCrewOperationalAlertUrl resolves ProjectedManningGap to vessels show when user lacks planning and overview but has vessel manning view', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'dedupe_key' => 'projected_manning_gap:vessel:'.$vessel->id.':rank:'.$rank->id,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => 'warning',
        'title' => 'Projected Manning Gap',
        'message' => 'Gap detected for vessel and rank',
        'summary' => 'Gap detected',
        'context' => [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ],
        'status' => 'active',
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($user->fresh(), $alert);

    expect($url)->toBe(route('organization.vessels.show', ['vessel' => $vessel->id]));
});

test('ResolveCrewOperationalAlertUrl resolves ProjectedManningGap to null when user has no permissions', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeCrewOperationsFixtures();
    $noPermUser = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $noPermUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $company->id,
        'dedupe_key' => 'projected_manning_gap:vessel:'.$vessel->id.':rank:'.$rank->id,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => 'warning',
        'title' => 'Projected Manning Gap',
        'message' => 'Gap detected for vessel and rank',
        'summary' => 'Gap detected',
        'context' => [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ],
        'status' => 'active',
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($noPermUser->fresh(), $alert);

    expect($url)->toBeNull();
});
