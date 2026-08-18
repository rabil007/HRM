<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Support\Platform\PlatformAuthorization;

final class NavigationDestinationCatalog
{
    /**
     * Stable favoritable navigation destinations.
     *
     * Keep keys and hrefs in sync with `resources/js/lib/navigation-favorites.ts`
     * and Phase 3A `nav-visibility.ts` / `getSidebarData()`.
     *
     * @return list<array{key: string, label: string, href: string, group: string, permissions: list<string>, platform: string|null}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/dashboard', 'group' => 'General', 'permissions' => [], 'platform' => null],
            ['key' => 'organization.companies', 'label' => 'Companies', 'href' => '/organization/companies', 'group' => 'Organization', 'permissions' => ['companies.view'], 'platform' => null],
            ['key' => 'organization.branches', 'label' => 'Branches', 'href' => '/organization/branches', 'group' => 'Organization', 'permissions' => ['branches.view'], 'platform' => null],
            ['key' => 'organization.announcements', 'label' => 'Announcements', 'href' => '/organization/announcements', 'group' => 'Organization', 'permissions' => ['announcements.view'], 'platform' => null],
            ['key' => 'organization.departments', 'label' => 'Departments', 'href' => '/organization/departments', 'group' => 'Organization', 'permissions' => ['departments.view'], 'platform' => null],
            ['key' => 'organization.positions', 'label' => 'Positions', 'href' => '/organization/positions', 'group' => 'Organization', 'permissions' => ['positions.view'], 'platform' => null],
            ['key' => 'organization.activity-logs', 'label' => 'Activity logs', 'href' => '/organization/activity-logs', 'group' => 'Organization', 'permissions' => ['audit.view'], 'platform' => null],
            ['key' => 'organization.roles', 'label' => 'Roles & permissions', 'href' => '/organization/roles', 'group' => 'Organization', 'permissions' => ['roles.view'], 'platform' => null],
            ['key' => 'organization.users', 'label' => 'Users', 'href' => '/organization/users', 'group' => 'Organization', 'permissions' => ['users.view'], 'platform' => null],
            ['key' => 'organization.employee-templates', 'label' => 'Employee templates', 'href' => '/organization/templates/employee-profile', 'group' => 'Organization', 'permissions' => ['employee_profile_templates.view'], 'platform' => null],
            ['key' => 'employees', 'label' => 'Employees', 'href' => '/organization/employees', 'group' => 'Employees', 'permissions' => ['employees.view'], 'platform' => null],
            ['key' => 'documents', 'label' => 'Documents', 'href' => '/organization/documents', 'group' => 'Employees', 'permissions' => ['documents.view'], 'platform' => null],
            ['key' => 'documents.bulk', 'label' => 'Bulk generate', 'href' => '/organization/documents/bulk', 'group' => 'Employees', 'permissions' => ['bulk_documents.view'], 'platform' => null],
            ['key' => 'contracts', 'label' => 'Contracts', 'href' => '/organization/contracts', 'group' => 'Employees', 'permissions' => ['contracts.view'], 'platform' => null],
            ['key' => 'bank-accounts', 'label' => 'Bank Accounts', 'href' => '/organization/bank-accounts', 'group' => 'Employees', 'permissions' => ['bank_accounts.view'], 'platform' => null],
            ['key' => 'training', 'label' => 'Training', 'href' => '/organization/training', 'group' => 'Employees', 'permissions' => ['training.view'], 'platform' => null],
            ['key' => 'sea-services', 'label' => 'Sea Service', 'href' => '/organization/sea-services', 'group' => 'Employees', 'permissions' => ['sea_services.view'], 'platform' => null],
            ['key' => 'crew.overview', 'label' => 'Overview', 'href' => '/organization/crew-operations', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.overview.view'], 'platform' => null],
            ['key' => 'crew.current', 'label' => 'Crew Assignments', 'href' => '/organization/crew', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.assignments.view'], 'platform' => null],
            ['key' => 'crew.planning', 'label' => 'Planning', 'href' => '/organization/crew-planning', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.planning.view'], 'platform' => null],
            ['key' => 'crew.vessels', 'label' => 'Vessels', 'href' => '/organization/vessels', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.vessels.view', 'crew_operations.vessel_manning.view'], 'platform' => null],
            ['key' => 'crew.corrections', 'label' => 'Movement Corrections', 'href' => '/organization/crew-movement-corrections', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.corrections.view'], 'platform' => null],
            ['key' => 'crew.settings', 'label' => 'Settings', 'href' => '/organization/crew-operations/settings', 'group' => 'Crew Operations', 'permissions' => ['crew_operations.planning.view'], 'platform' => null],
            ['key' => 'reports.crew-movement-history', 'label' => 'Crew Movement History', 'href' => '/organization/reports/crew-movement-history', 'group' => 'Reports', 'permissions' => ['reports.crew_movement_history.view'], 'platform' => null],
            ['key' => 'hikvision.persons', 'label' => 'Persons', 'href' => '/hikvision/persons', 'group' => 'Hikvision', 'permissions' => ['hikvision.persons.view'], 'platform' => null],
            ['key' => 'hikvision.access-events', 'label' => 'Access Events', 'href' => '/hikvision/access-events', 'group' => 'Hikvision', 'permissions' => ['hikvision.events.view'], 'platform' => null],
            ['key' => 'attendance.overview', 'label' => 'Overview', 'href' => '/attendance/overview', 'group' => 'Attendance', 'permissions' => ['attendance.overview.view'], 'platform' => null],
            ['key' => 'attendance.calendar', 'label' => 'Calendar', 'href' => '/attendance/calendar', 'group' => 'Attendance', 'permissions' => ['attendance.leave-requests.view'], 'platform' => null],
            ['key' => 'leave.requests', 'label' => 'Leave requests', 'href' => '/attendance/leave-requests', 'group' => 'Attendance', 'permissions' => ['attendance.leave-requests.view'], 'platform' => null],
            ['key' => 'attendance.records', 'label' => 'Attendance records', 'href' => '/attendance/records', 'group' => 'Attendance', 'permissions' => ['attendance.records.view'], 'platform' => null],
            ['key' => 'attendance.types', 'label' => 'Types', 'href' => '/attendance/types', 'group' => 'Attendance', 'permissions' => ['attendance.types.view'], 'platform' => null],
            ['key' => 'attendance.approval-policies', 'label' => 'Approval policies', 'href' => '/attendance/leave-approval-policies', 'group' => 'Attendance', 'permissions' => ['attendance.leave-approval-policies.view'], 'platform' => null],
            ['key' => 'payroll.overview', 'label' => 'Overview', 'href' => '/payroll/overview', 'group' => 'Payroll', 'permissions' => ['payroll.overview.view'], 'platform' => null],
            ['key' => 'payroll', 'label' => 'Payroll', 'href' => '/payroll', 'group' => 'Payroll', 'permissions' => ['payroll.periods.view', 'payroll.crew_timesheets.view'], 'platform' => null],
            ['key' => 'payroll.records', 'label' => 'Payroll records', 'href' => '/payroll/records', 'group' => 'Payroll', 'permissions' => ['payroll.records.view'], 'platform' => null],
            ['key' => 'payroll.salary-inputs', 'label' => 'Salary inputs', 'href' => '/payroll/salary-inputs', 'group' => 'Payroll', 'permissions' => ['payroll.salary_inputs.view', 'payroll.periods.update'], 'platform' => null],
            ['key' => 'platform.logs', 'label' => 'Logs', 'href' => '/log', 'group' => 'Platform', 'permissions' => [], 'platform' => 'view'],
            ['key' => 'platform.jobs', 'label' => 'Jobs', 'href' => '/jobs', 'group' => 'Platform', 'permissions' => [], 'platform' => 'view'],
            ['key' => 'platform.database', 'label' => 'Database', 'href' => '/mysql', 'group' => 'Platform', 'permissions' => [], 'platform' => 'database'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @return array{key: string, label: string, href: string, group: string, permissions: list<string>, platform: string|null}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $destination) {
            if ($destination['key'] === $key) {
                return $destination;
            }
        }

        return null;
    }

    public static function contains(string $key): bool
    {
        return self::find($key) !== null;
    }

    /**
     * @param  array{key: string, label: string, href: string, group: string, permissions: list<string>, platform: string|null}  $destination
     */
    public static function isAccessible(User $user, array $destination): bool
    {
        $platform = $destination['platform'];

        if ($platform === 'view') {
            return PlatformAuthorization::canView($user);
        }

        if ($platform === 'database') {
            return PlatformAuthorization::canViewDatabase($user);
        }

        $permissions = $destination['permissions'];

        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function isAccessibleKey(User $user, string $key): bool
    {
        $destination = self::find($key);

        if ($destination === null) {
            return false;
        }

        return self::isAccessible($user, $destination);
    }
}
