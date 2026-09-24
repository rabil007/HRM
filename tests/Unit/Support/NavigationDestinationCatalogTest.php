<?php

use App\Models\User;
use App\Support\Navigation\NavigationDestinationCatalog;

test('destination keys and hrefs are unique', function () {
    $destinations = NavigationDestinationCatalog::all();
    $keys = array_column($destinations, 'key');
    $hrefs = array_column($destinations, 'href');

    expect($keys)->toHaveCount(count(array_unique($keys)))
        ->and($hrefs)->toHaveCount(count(array_unique($hrefs)));
});

test('unknown keys are not accessible', function () {
    $user = User::factory()->create();

    expect(NavigationDestinationCatalog::contains('employee.record.1'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'employees'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'not-a-key'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'dashboard'))->toBeTrue();
});

test('vessel manning cannot unlock the vessels destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessel_manning.view']);

    expect(NavigationDestinationCatalog::contains('crew.vessel-manning'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessels'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessel-manning'))->toBeFalse();
});

test('documents destinations form one unified group without a standalone bulk generate href', function () {
    $documents = array_values(array_filter(
        NavigationDestinationCatalog::all(),
        fn (array $destination): bool => $destination['group'] === 'Documents',
    ));

    expect(array_column($documents, 'label'))->toBe([
        'Overview',
        'Library',
        'Templates',
        'Generate & Track',
        'My Tasks',
        'Document Types',
        'Activity',
    ])
        ->and(array_column($documents, 'href'))->not->toContain('/organization/documents/bulk')
        ->and(array_column($documents, 'label'))->not->toContain('Bulk generate');
});

test('templates destination is accessible through any current templates-bridge permission', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.templates'))->toBeFalse();

    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.templates'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.bulk'))->toBeFalse();
});

test('platform view does not unlock the templates destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);
    grantPlatformAccess($user);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.templates'))->toBeFalse();
});

test('vessels.view cannot unlock a removed vessel manning destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessels.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessels'))->toBeTrue()
        ->and(NavigationDestinationCatalog::contains('crew.vessel-manning'))->toBeFalse();
});

test('planning view cannot unlock crew operations settings destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.settings'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.planning'))->toBeTrue();
});

test('settings view unlocks crew operations settings destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.settings.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.settings'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.planning'))->toBeFalse();
});

test('leave and crew report destinations are grouped under Attendance and Crew Operations', function () {
    $destinations = NavigationDestinationCatalog::all();
    $byKey = collect($destinations)->keyBy('key');

    expect($byKey->get('reports.leave'))->toMatchArray([
        'href' => '/organization/reports/leave',
        'group' => 'Attendance',
        'permissions' => ['reports.leave.view'],
    ])
        ->and($byKey->get('reports.leave_balance'))->toMatchArray([
            'href' => '/organization/reports/leave-balances',
            'group' => 'Attendance',
            'permissions' => ['reports.leave_balance.view'],
        ])
        ->and($byKey->get('reports.crew-movement-history'))->toMatchArray([
            'href' => '/organization/reports/crew-movement-history',
            'group' => 'Crew Operations',
            'permissions' => ['reports.crew_movement_history.view'],
        ])
        ->and(array_column($destinations, 'group'))->not->toContain('Reports');

    $attendanceLabels = array_column(array_values(array_filter(
        $destinations,
        fn (array $destination): bool => $destination['group'] === 'Attendance',
    )), 'label');

    expect($attendanceLabels)->toBe([
        'Calendar',
        'My leave',
        'Approvals',
        'Attendance records',
        'Types',
        'Approval policies',
        'Leave Report',
        'Leave Balance Report',
    ]);

    $crewLabels = array_column(array_values(array_filter(
        $destinations,
        fn (array $destination): bool => $destination['group'] === 'Crew Operations',
    )), 'label');

    expect($crewLabels)->toBe([
        'Overview',
        'Crew Assignments',
        'Planning',
        'Vessels',
        'Movement Corrections',
        'Crew Movement History',
        'Settings',
    ]);
});

test('report destination keys remain gated by report permissions not module permissions', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.overview.view',
        'crew_operations.overview.view',
    ]);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.leave'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.leave_balance'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.crew-movement-history'))->toBeFalse();

    grantCompanyPermissions($user, $company, ['reports.leave.view'], 'leave-report-role');

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.leave'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.leave_balance'))->toBeFalse();

    grantCompanyPermissions($user, $company, ['reports.leave_balance.view'], 'leave-balance-role');

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.leave_balance'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.crew-movement-history'))->toBeFalse();

    grantCompanyPermissions($user, $company, ['reports.crew_movement_history.view'], 'crew-history-role');

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'reports.crew-movement-history'))->toBeTrue();
});
