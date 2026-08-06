<?php

use App\Models\CrewRankPolicy;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->user = $this->fixtures['user'];
    $this->company = $this->fixtures['company'];
    $this->rank = $this->fixtures['rank'];
    $this->rank->update(['max_tour_of_duty_days' => 90]);
    $this->user->update(['current_company_id' => $this->company->id]);
});

it('allows authorized users to manage company rank policies', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.view',
        'crew_operations.rank_policies.update',
    ]);

    $this->actingAs($this->user)
        ->get(route('organization.crew-operations.rank-policies.index'))
        ->assertOk();

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $this->rank->id,
            'tour_of_duty_days' => 120,
        ])
        ->assertRedirect(route('organization.crew-operations.rank-policies.index'));

    $policy = CrewRankPolicy::query()
        ->where('company_id', $this->company->id)
        ->where('rank_id', $this->rank->id)
        ->first();

    expect($policy)->not->toBeNull()
        ->and($policy->tour_of_duty_days)->toBe(120);

    $this->actingAs($this->user)
        ->delete(route('organization.crew-operations.rank-policies.destroy', $policy))
        ->assertRedirect(route('organization.crew-operations.rank-policies.index'));

    expect(CrewRankPolicy::query()->whereKey($policy->id)->exists())->toBeFalse()
        ->and(CrewRankPolicy::withTrashed()->whereKey($policy->id)->exists())->toBeTrue();
});

it('restores a soft-deleted policy when recreating the same company and rank', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.view',
        'crew_operations.rank_policies.update',
    ]);

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $this->rank->id,
            'tour_of_duty_days' => 90,
        ])
        ->assertRedirect();

    $original = CrewRankPolicy::query()
        ->where('company_id', $this->company->id)
        ->where('rank_id', $this->rank->id)
        ->firstOrFail();

    $this->actingAs($this->user)
        ->delete(route('organization.crew-operations.rank-policies.destroy', $original))
        ->assertRedirect();

    expect(CrewRankPolicy::query()->whereKey($original->id)->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $this->rank->id,
            'tour_of_duty_days' => 120,
        ])
        ->assertRedirect();

    $restored = CrewRankPolicy::query()
        ->where('company_id', $this->company->id)
        ->where('rank_id', $this->rank->id)
        ->get();

    expect($restored)->toHaveCount(1)
        ->and($restored->first()->id)->toBe($original->id)
        ->and($restored->first()->tour_of_duty_days)->toBe(120)
        ->and($restored->first()->is_active)->toBeTrue()
        ->and($restored->first()->trashed())->toBeFalse()
        ->and(CrewRankPolicy::withTrashed()
            ->where('company_id', $this->company->id)
            ->where('rank_id', $this->rank->id)
            ->count())->toBe(1);

    expect(Activity::query()
        ->where('subject_type', CrewRankPolicy::class)
        ->where('subject_id', $original->id)
        ->where('company_id', $this->company->id)
        ->exists())->toBeTrue();
});

it('never restores another company deleted policy for the same rank', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.update',
    ]);

    $other = makeCrewAssignmentFixtures();
    $sharedRank = $this->rank;

    $foreign = CrewRankPolicy::query()->create([
        'company_id' => $other['company']->id,
        'rank_id' => $sharedRank->id,
        'tour_of_duty_days' => 200,
        'is_active' => true,
    ]);
    $foreign->delete();

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $sharedRank->id,
            'tour_of_duty_days' => 75,
        ])
        ->assertRedirect();

    expect(CrewRankPolicy::withTrashed()->whereKey($foreign->id)->first()?->trashed())->toBeTrue()
        ->and(CrewRankPolicy::query()
            ->where('company_id', $this->company->id)
            ->where('rank_id', $sharedRank->id)
            ->value('tour_of_duty_days'))->toBe(75)
        ->and(CrewRankPolicy::withTrashed()
            ->where('rank_id', $sharedRank->id)
            ->count())->toBe(2);
});

it('forbids users without rank policy permissions', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.overview.view',
    ]);

    $this->actingAs($this->user)
        ->get(route('organization.crew-operations.rank-policies.index'))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $this->rank->id,
            'tour_of_duty_days' => 100,
        ])
        ->assertForbidden();
});

it('rejects cross-company policy deletion', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.view',
        'crew_operations.rank_policies.update',
    ]);

    $other = makeCrewAssignmentFixtures();
    $policy = CrewRankPolicy::query()->create([
        'company_id' => $other['company']->id,
        'rank_id' => $other['rank']->id,
        'tour_of_duty_days' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->delete(route('organization.crew-operations.rank-policies.destroy', $policy))
        ->assertNotFound();
});

it('validates tour of duty day range', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.update',
    ]);

    $this->actingAs($this->user)
        ->put(route('organization.crew-operations.rank-policies.upsert'), [
            'rank_id' => $this->rank->id,
            'tour_of_duty_days' => 400,
        ])
        ->assertSessionHasErrors('tour_of_duty_days');
});

it('grants rank policy permissions to existing roles based on planning capabilities', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    $manager = Role::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Crew Settings Manager',
        'guard_name' => 'web',
    ]);
    $manager->syncPermissions([
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $viewer = Role::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Crew Overview Viewer',
        'guard_name' => 'web',
    ]);
    $viewer->syncPermissions([
        'crew_operations.overview.view',
    ]);

    $unrelated = Role::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Payroll Only',
        'guard_name' => 'web',
    ]);
    $unrelated->syncPermissions([
        'payroll.overview.view',
    ]);

    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $manager->load('permissions');
    $viewer->load('permissions');
    $unrelated->load('permissions');

    expect($manager->permissions->pluck('name'))
        ->toContain('crew_operations.rank_policies.view')
        ->toContain('crew_operations.rank_policies.update')
        ->and($viewer->permissions->pluck('name'))
        ->toContain('crew_operations.rank_policies.view')
        ->not->toContain('crew_operations.rank_policies.update')
        ->and($unrelated->permissions->pluck('name'))
        ->not->toContain('crew_operations.rank_policies.view')
        ->not->toContain('crew_operations.rank_policies.update');

    expect(Permission::query()->where('name', 'crew_operations.rank_policies.view')->exists())->toBeTrue();

    // Idempotent: second seed does not duplicate pivots.
    $managerCount = $manager->permissions()->where('name', 'crew_operations.rank_policies.update')->count();
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);
    expect($manager->permissions()->where('name', 'crew_operations.rank_policies.update')->count())->toBe($managerCount);
});

it('does not grant company a access in company b', function () {
    grantCompanyPermissions($this->user, $this->company, [
        'crew_operations.rank_policies.view',
        'crew_operations.rank_policies.update',
    ]);

    $other = makeCrewAssignmentFixtures();

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $other['company']->id, 'user_id' => $this->user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->user->update(['current_company_id' => $other['company']->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($other['company']->id);

    expect($this->user->fresh()->can('crew_operations.rank_policies.view'))->toBeFalse();

    $this->actingAs($this->user)
        ->withSession(['current_company_id' => $other['company']->id])
        ->get(route('organization.crew-operations.rank-policies.index'))
        ->assertForbidden();
});
