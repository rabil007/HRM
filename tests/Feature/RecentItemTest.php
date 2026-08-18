<?php

use App\Enums\RecentItemType;
use App\Models\PayrollPeriod;
use App\Models\RecentItem;
use App\Models\User;
use App\Support\RecentItems\RecordRecentItem;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

function recentItemsAs(User $user, int $companyId, array $extra = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->getJson(route('recent-items', $extra));
}

function visitShow(User $user, int $companyId, string $route, mixed $parameters)
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->get(route($route, $parameters));
}

function forgetRecentItemPermissionState(User $user): void
{
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('guests cannot list recent items', function () {
    $this->getJson(route('recent-items'))->assertUnauthorized();
});

test('client cannot supply user or company identifiers when listing recents', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    recentItemsAs($user, $company->id, [
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => 'employee',
        'record_id' => 1,
    ])->assertUnprocessable();
});

test('successful employee show creates a recent item', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    visitShow($user, $company->id, 'organization.employees.show', $employee)->assertOk();

    expect($user->recentItems()->count())->toBe(1)
        ->and($user->recentItems()->first()?->record_type)->toBe(RecentItemType::Employee)
        ->and($user->recentItems()->first()?->record_id)->toBe($employee->id)
        ->and($user->recentItems()->first()?->company_id)->toBe($company->id);
});

test('employee index does not create a recent item', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    visitShow($user, $company->id, 'organization.employees', [])->assertOk();

    expect($user->recentItems()->count())->toBe(0);
});

test('reopening an employee updates last_viewed_at without duplicating', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    visitShow($user, $company->id, 'organization.employees.show', $employee)->assertOk();
    $firstViewed = $user->recentItems()->first()?->last_viewed_at;
    $this->travel(2)->seconds();
    visitShow($user, $company->id, 'organization.employees.show', $employee)->assertOk();

    expect($user->recentItems()->count())->toBe(1)
        ->and($user->recentItems()->first()?->last_viewed_at?->gt($firstViewed))->toBeTrue();
});

test('document show creates a recent item', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);
    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "documents/{$company->id}/passport.pdf",
        'passport.pdf',
    );

    visitShow($user, $company->id, 'organization.documents.employee.files.show', [
        'employee' => $employee,
        'document' => $document,
    ])->assertOk();

    expect($user->recentItems()->pluck('record_type')->all())->toBe([RecentItemType::Document])
        ->and($user->recentItems()->first()?->record_id)->toBe($document->id);
});

test('crew assignment show creates a recent item', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.view']);
    $vessel = makeCrewMovementVessel('Horizon', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    visitShow($user, $company->id, 'organization.crew-assignments.show', $assignment)->assertOk();

    expect($user->recentItems()->pluck('record_type')->all())->toBe([RecentItemType::CrewAssignment])
        ->and($user->recentItems()->first()?->record_id)->toBe($assignment->id);
});

test('vessel show creates a recent item', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.vessels.view']);
    $vessel = makeCrewMovementVessel('Horizon', $company);

    visitShow($user, $company->id, 'organization.vessels.show', $vessel)->assertOk();

    expect($user->recentItems()->pluck('record_type')->all())->toBe([RecentItemType::Vessel])
        ->and($user->recentItems()->first()?->record_id)->toBe($vessel->id);
});

test('payroll period show creates a recent item', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);
    $period = PayrollPeriod::factory()->for($company)->create([
        'name' => 'August 2026',
    ]);

    visitShow($user, $company->id, 'payroll.show', $period)->assertOk();

    expect($user->recentItems()->pluck('record_type')->all())->toBe([RecentItemType::PayrollPeriod])
        ->and($user->recentItems()->first()?->record_id)->toBe($period->id);
});

test('unauthorized show attempts do not create recent rows', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['departments.view']);

    visitShow($user, $company->id, 'organization.employees.show', $employee)->assertForbidden();

    expect($user->recentItems()->count())->toBe(0);
});

test('foreign-company show attempts do not create recent rows', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['employees.view']);
    grantCompanyPermissions($user, $companyB, ['employees.view']);

    visitShow($user, $companyA->id, 'organization.employees.show', $employeeB)->assertNotFound();

    expect($user->recentItems()->count())->toBe(0);
});

test('listed recents stay scoped to the authenticated user and active company', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    ['company' => $companyA, 'employee' => $employeeA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();
    grantCompanyPermissions($owner, $companyA, ['employees.view']);
    grantCompanyPermissions($owner, $companyB, ['employees.view']);
    grantCompanyPermissions($stranger, $companyA, ['employees.view']);

    RecentItem::factory()->create([
        'user_id' => $owner->id,
        'company_id' => $companyA->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employeeA->id,
        'last_viewed_at' => now()->subMinute(),
    ]);
    RecentItem::factory()->create([
        'user_id' => $owner->id,
        'company_id' => $companyB->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employeeB->id,
        'last_viewed_at' => now(),
    ]);

    $payloadA = recentItemsAs($owner, $companyA->id)->assertOk()->json();
    expect(collect($payloadA['items'])->pluck('id')->all())->toBe(['employee:'.$employeeA->id]);

    forgetRecentItemPermissionState($owner);

    $payloadB = recentItemsAs($owner, $companyB->id)->assertOk()->json();
    expect(collect($payloadB['items'])->pluck('id')->all())->toBe(['employee:'.$employeeB->id]);

    forgetRecentItemPermissionState($owner);

    $payloadAAgain = recentItemsAs($owner, $companyA->id)->assertOk()->json();
    expect(collect($payloadAAgain['items'])->pluck('id')->all())->toBe(['employee:'.$employeeA->id])
        ->and($owner->recentItems()->count())->toBe(2);

    $strangerPayload = recentItemsAs($stranger, $companyA->id)->assertOk()->json();
    expect($strangerPayload['items'])->toBe([]);
});

test('platform access does not reveal tenant recents without domain permission', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['departments.view']);
    grantPlatformAccess($user, 'view');

    RecentItem::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employee->id,
        'last_viewed_at' => now(),
    ]);

    $payload = recentItemsAs($user, $company->id)->assertOk()->json();

    expect($payload['items'])->toBe([])
        ->and($user->recentItems()->count())->toBe(1);
});

test('permission loss hides a stored recent and restoring it reveals the same row', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    RecentItem::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employee->id,
        'last_viewed_at' => now(),
    ]);

    expect(collect(recentItemsAs($user, $company->id)->assertOk()->json('items'))->pluck('id')->all())
        ->toBe(['employee:'.$employee->id]);

    grantCompanyPermissions($user, $company, ['departments.view']);
    forgetRecentItemPermissionState($user);

    expect(recentItemsAs($user, $company->id)->assertOk()->json('items'))->toBe([])
        ->and($user->recentItems()->count())->toBe(1);

    grantCompanyPermissions($user, $company, ['employees.view']);
    forgetRecentItemPermissionState($user);

    expect(collect(recentItemsAs($user, $company->id)->assertOk()->json('items'))->pluck('id')->all())
        ->toBe(['employee:'.$employee->id]);
});

test('deleted records are omitted and stale rows are cleaned', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    RecentItem::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employee->id,
        'last_viewed_at' => now(),
    ]);

    $employee->delete();

    $payload = recentItemsAs($user, $company->id)->assertOk()->json();

    expect($payload['items'])->toBe([])
        ->and($user->recentItems()->count())->toBe(0);
});

test('history is capped per user and company by dropping the oldest rows', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $oldest = RecentItem::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => 1,
        'last_viewed_at' => now()->subDays(30),
    ]);

    foreach (range(2, RecentItem::MAX_PER_USER_COMPANY) as $recordId) {
        RecentItem::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'record_type' => RecentItemType::Employee,
            'record_id' => $recordId,
            'last_viewed_at' => now()->subDays(RecentItem::MAX_PER_USER_COMPANY - $recordId),
        ]);
    }

    app(RecordRecentItem::class)->handle($user, $company->id, RecentItemType::Employee, 999);

    expect($user->recentItems()->count())->toBe(RecentItem::MAX_PER_USER_COMPANY)
        ->and(RecentItem::query()->whereKey($oldest->id)->exists())->toBeFalse()
        ->and($user->recentItems()->where('record_id', 999)->exists())->toBeTrue();
});

test('list resolves display data in bounded queries and does not snapshot names', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    RecentItem::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'record_type' => RecentItemType::Employee,
        'record_id' => $employee->id,
        'last_viewed_at' => now(),
    ]);

    $employee->update(['name' => 'Mohammed Rabil', 'employee_no' => 'EMP-0012']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $payload = recentItemsAs($user, $company->id)->assertOk()->json();

    $recentQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'recent_items'))
        ->values();

    expect($payload['items'][0]['title'] ?? null)->toBe('Mohammed Rabil')
        ->and($payload['items'][0]['type_label'] ?? null)->toBe('Employee')
        ->and($payload['items'][0]['href'] ?? null)->toBe(route('organization.employees.show', $employee))
        ->and(json_encode($payload))->not->toContain('Test Employee')
        ->and($recentQueries)->toHaveCount(1);
});
