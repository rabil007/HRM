<?php

use App\Enums\SavedViewPage;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\SavedView;
use App\Models\User;
use App\Support\SavedViews\SavedViewCatalog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function savedViewUser(array $permissions = ['employees.view']): array
{
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, $permissions);

    return [$user, $company];
}

function saveView(User $user, int $companyId, array $payload, string $from = '/organization/employees')
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->from($from)
        ->post(route('saved-views.store'), $payload);
}

function updateView(User $user, int $companyId, SavedView $view, array $payload, string $from = '/organization/employees')
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->from($from)
        ->put(route('saved-views.update', $view), $payload);
}

function deleteView(User $user, int $companyId, SavedView $view, array $payload = [], string $from = '/organization/employees')
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->from($from)
        ->delete(route('saved-views.destroy', $view), $payload);
}

function visitList(User $user, int $companyId, string $route, array $query = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->get(route($route, $query));
}

function forgetSavedViewPermissionState(User $user): void
{
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('guests cannot manage saved views', function () {
    $this->post(route('saved-views.store'), [
        'page_key' => 'employees',
        'name' => 'Active',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();
});

test('authenticated users can create rename and delete their saved views', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active marine',
        'filters' => ['status' => 'active', 'search' => 'marine'],
    ])->assertRedirect('/organization/employees');

    $view = $user->savedViews()->first();

    expect($view)->not->toBeNull()
        ->and($view?->company_id)->toBe($company->id)
        ->and($view?->page_key)->toBe(SavedViewPage::Employees)
        ->and($view?->filters)->toBe(['search' => 'marine', 'status' => 'active']);

    updateView($user, $company->id, $view, ['name' => 'Active crew'])->assertRedirect();
    expect($view->refresh()->name)->toBe('Active crew');

    deleteView($user, $company->id, $view)->assertRedirect();
    expect($user->savedViews()->count())->toBe(0);
});

test('duplicate names on the same page are rejected', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active',
        'filters' => ['status' => 'inactive'],
    ])->assertRedirect()->assertSessionHasErrors('name');

    expect($user->savedViews()->count())->toBe(1);
});

test('users cannot exceed the per page cap', function () {
    [$user, $company] = savedViewUser();

    for ($i = 1; $i <= SavedView::MAX_PER_USER_COMPANY_PAGE; $i++) {
        SavedView::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'page_key' => SavedViewPage::Employees,
            'name' => 'View '.$i,
            'filters' => ['status' => 'active'],
        ]);
    }

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Overflow',
        'filters' => ['status' => 'inactive'],
    ])->assertRedirect()->assertSessionHasErrors('name');
});

test('company a views are hidden in company b and restored when returning to a', function () {
    [$user, $companyA] = savedViewUser(['employees.view']);
    ['company' => $companyB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyB, ['employees.view']);

    saveView($user, $companyA->id, [
        'page_key' => 'employees',
        'name' => 'Company A active',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();

    visitList($user, $companyB->id, 'organization.employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('saved_views', [])
        );

    visitList($user, $companyA->id, 'organization.employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('saved_views', 1)
            ->where('saved_views.0.name', 'Company A active')
        );
});

test('users cannot update or delete another users saved view', function () {
    [$owner, $company] = savedViewUser();
    saveView($owner, $company->id, [
        'page_key' => 'employees',
        'name' => 'Owner view',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();
    $view = $owner->savedViews()->first();

    $stranger = User::factory()->create();
    grantCompanyPermissions($stranger, $company, ['employees.view']);

    updateView($stranger, $company->id, $view, ['name' => 'Hijacked'])->assertNotFound();
    deleteView($stranger, $company->id, $view)->assertNotFound();

    expect($view->refresh()->name)->toBe('Owner view');
});

test('company a cannot mutate a company b view even for the same user', function () {
    [$user, $companyA] = savedViewUser(['employees.view']);
    ['company' => $companyB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyB, ['employees.view']);

    $view = SavedView::factory()->create([
        'user_id' => $user->id,
        'company_id' => $companyB->id,
        'page_key' => SavedViewPage::Employees,
        'name' => 'B view',
        'filters' => ['status' => 'inactive'],
    ]);

    updateView($user, $companyA->id, $view, ['name' => 'Stolen'])->assertNotFound();
    deleteView($user, $companyA->id, $view)->assertNotFound();
    expect($view->refresh()->name)->toBe('B view');
});

test('client supplied user and company identifiers are rejected', function () {
    [$user, $company] = savedViewUser();
    $other = User::factory()->create();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Injected',
        'filters' => ['status' => 'active'],
        'user_id' => $other->id,
        'company_id' => 999,
        'url' => '/organization/employees?sort=salary',
        'href' => 'https://evil.example',
    ])->assertRedirect()->assertSessionHasErrors(['user_id', 'company_id', 'url', 'href']);

    expect($user->savedViews()->count())->toBe(0);
});

test('unsupported page keys and arbitrary filters are rejected', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'branches',
        'name' => 'Bad page',
        'filters' => ['status' => 'active'],
    ])->assertRedirect()->assertSessionHasErrors('page_key');

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Bad filter',
        'filters' => ['status' => 'active', 'sort' => 'salary', 'old_filter' => 'x'],
    ])->assertRedirect()->assertSessionHasErrors('filters');

    expect($user->savedViews()->count())->toBe(0);
});

test('foreign company department and vessel ids are rejected', function () {
    [$user, $companyA] = savedViewUser(['employees.view', 'crew_operations.assignments.view']);
    ['company' => $companyB] = makeDocumentFixtures();
    $foreignDepartment = Department::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Foreign Marine',
        'code' => 'FM1',
        'status' => 'active',
    ]);
    $foreignVessel = makeCrewMovementVessel('Foreign Horizon', $companyB);

    saveView($user, $companyA->id, [
        'page_key' => 'employees',
        'name' => 'Foreign dept',
        'filters' => ['department_id' => (string) $foreignDepartment->id],
    ])->assertRedirect()->assertSessionHasErrors('filters.department_id');

    saveView($user, $companyA->id, [
        'page_key' => 'crew',
        'name' => 'Foreign vessel',
        'filters' => ['vessel_id' => (string) $foreignVessel->id],
    ], '/organization/crew')->assertRedirect()->assertSessionHasErrors('filters.vessel_id');
});

test('platform access does not grant tenant saved views', function () {
    [$user, $company] = savedViewUser(['departments.view']);
    grantPlatformAccess($user, 'view');

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Should fail',
        'filters' => ['status' => 'active'],
    ])->assertForbidden();
});

test('employee saved views apply through the existing index filters', function () {
    [$user, $company] = savedViewUser();
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
    ]);

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active Marine Crew',
        'filters' => [
            'status' => 'active',
            'department_id' => (string) $department->id,
            'page' => 9,
        ],
    ])->assertRedirect()->assertSessionHasErrors('filters');

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active Marine Crew',
        'filters' => [
            'status' => 'active',
            'department_id' => (string) $department->id,
        ],
    ])->assertRedirect();

    visitList($user, $company->id, 'organization.employees', [
        'status' => 'active',
        'department_id' => $department->id,
    ])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.status', 'active')
        ->where('filters.department_id', (string) $department->id)
        ->has('saved_views', 1)
    );
});

test('document crew leave and payroll pages save and list supported filters', function () {
    [$user, $company] = savedViewUser([
        'documents.view',
        'crew_operations.assignments.view',
        'attendance.leave-requests.view',
        'payroll.periods.view',
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Docs',
        'code' => 'DOC',
        'status' => 'active',
    ]);
    $vessel = makeCrewMovementVessel('Horizon', $company);
    $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);

    saveView($user, $company->id, [
        'page_key' => 'documents',
        'name' => 'Expiring in 30 days',
        'filters' => ['expiry' => 'expiring_30', 'department_id' => (string) $department->id],
    ], '/organization/documents')->assertRedirect();

    saveView($user, $company->id, [
        'page_key' => 'crew',
        'name' => 'Horizon onboard',
        'filters' => ['vessel_id' => (string) $vessel->id, 'phase' => 'p4'],
    ], '/organization/crew')->assertRedirect();

    saveView($user, $company->id, [
        'page_key' => 'leave',
        'name' => 'Pending approvals',
        'filters' => ['status' => 'pending'],
    ], '/attendance/leave-requests')->assertRedirect();

    saveView($user, $company->id, [
        'page_key' => 'payroll',
        'name' => 'Open payroll periods',
        'filters' => ['status' => 'draft', 'net_pay' => '9000'],
    ], '/payroll')->assertRedirect()->assertSessionHasErrors('filters');

    saveView($user, $company->id, [
        'page_key' => 'payroll',
        'name' => 'Open payroll periods',
        'filters' => ['status' => 'draft'],
    ], '/payroll')->assertRedirect();

    visitList($user, $company->id, 'organization.documents', ['expiry' => 'expiring_30'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('expiry', 'expiring_30')
            ->has('saved_views', 1)
        );

    visitList($user, $company->id, 'organization.crew-assignments.index', [
        'vessel_id' => $vessel->id,
        'phase' => 'p4',
    ])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.phase', 'p4')
        ->where('filters.vessel_id', (string) $vessel->id)
    );

    visitList($user, $company->id, 'attendance.leave-requests.index', ['status' => 'pending'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'pending'));

    visitList($user, $company->id, 'payroll.index', ['status' => 'draft'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'draft')
            ->has('saved_views', 1)
            ->where('saved_views.0.filters', ['status' => 'draft'])
        );

    expect($user->savedViews()->pluck('page_key')->map->value->sort()->values()->all())
        ->toBe(['crew', 'documents', 'leave', 'payroll']);
});

test('stale stored filter keys are ignored when applying', function () {
    [$user, $company] = savedViewUser();

    SavedView::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'page_key' => SavedViewPage::Employees,
        'name' => 'Legacy',
        'filters' => ['status' => 'active', 'old_filter' => 'x'],
        'is_default' => true,
    ]);

    visitList($user, $company->id, 'organization.employees')
        ->assertRedirect(route('organization.employees', ['status' => 'active']));

    visitList($user, $company->id, 'organization.employees', ['status' => 'active'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('saved_views.0.filters', ['status' => 'active'])
            ->missing('saved_views.0.filters.old_filter')
        );
});

test('deleted related records do not crash apply', function () {
    [$user, $company] = savedViewUser(['crew_operations.assignments.view']);
    $vessel = makeCrewMovementVessel('Temp Horizon', $company);

    saveView($user, $company->id, [
        'page_key' => 'crew',
        'name' => 'Temp vessel',
        'filters' => ['vessel_id' => (string) $vessel->id],
    ], '/organization/crew')->assertRedirect();

    $vessel->delete();

    visitList($user, $company->id, 'organization.crew-assignments.index', [
        'vessel_id' => $vessel->id,
    ])->assertOk();
});

test('removed list permission hides actionable views without deleting them', function () {
    [$user, $company] = savedViewUser(['employees.view']);

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();

    $view = $user->savedViews()->first();
    $user->syncRoles([]);
    forgetSavedViewPermissionState($user);
    grantCompanyPermissions($user, $company, ['departments.view']);
    forgetSavedViewPermissionState($user);

    visitList($user, $company->id, 'organization.employees')->assertForbidden();
    updateView($user, $company->id, $view, ['name' => 'Still there'])->assertForbidden();
    expect($user->savedViews()->count())->toBe(1);
});

test('explicit url filters win over a default saved view', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Active Employees',
        'filters' => ['status' => 'active'],
        'is_default' => true,
    ])->assertRedirect();

    visitList($user, $company->id, 'organization.employees', ['status' => 'inactive'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'inactive'));
});

test('only one default is kept per user company and page', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'First',
        'filters' => ['status' => 'active'],
        'is_default' => true,
    ])->assertRedirect();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Second',
        'filters' => ['status' => 'inactive'],
        'is_default' => true,
    ])->assertRedirect();

    expect($user->savedViews()->where('is_default', true)->count())->toBe(1)
        ->and($user->savedViews()->where('is_default', true)->value('name'))->toBe('Second');
});

test('malformed filter payloads are rejected', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Bad json',
        'filters' => 'status=active',
    ])->assertRedirect()->assertSessionHasErrors('filters');

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Nested',
        'filters' => ['status' => ['active']],
    ])->assertRedirect()->assertSessionHasErrors('filters.status');
});

test('creating a saved view is not written to the activity log', function () {
    [$user, $company] = savedViewUser();

    saveView($user, $company->id, [
        'page_key' => 'employees',
        'name' => 'Quiet',
        'filters' => ['status' => 'active'],
    ])->assertRedirect();

    expect(DB::table('activity_log')->where('subject_type', SavedView::class)->count())->toBe(0);
});

test('catalog apply strips unknown keys without requiring current company ids', function () {
    $filters = SavedViewCatalog::forApply(SavedViewPage::Crew, [
        'phase' => 'p4',
        'sort' => 'assignments.created_at',
        'old_filter' => 'x',
        'vessel_id' => '15',
    ]);

    expect($filters)->toBe([
        'phase' => 'p4',
        'vessel_id' => '15',
    ]);
});
