<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationsSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Departments\BuildDepartmentTree;
use App\Support\Employees\DepartmentDescendantIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrewOperationsSettings
{
    /**
     * Company setting key for Sea Service synchronization from crew assignments.
     */
    public const CONFIG_SYNC_SEA_SERVICE = 'crew_operations.sync_sea_service';

    /**
     * @return list<int>
     */
    public static function poolDepartmentIds(int $companyId): array
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting === null || $setting->pool_department_ids === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            intval(...),
            array_filter($setting->pool_department_ids, fn ($id) => is_numeric($id)),
        )));
    }

    public static function maxHomeDays(int $companyId): int
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        return $setting?->max_home_days ?? 30;
    }

    /**
     * Defaults to enabled when the company has no settings row yet.
     */
    public static function syncSeaServiceEnabled(int $companyId): bool
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting === null) {
            return true;
        }

        return (bool) $setting->sync_sea_service;
    }

    /**
     * Defaults to OFF when the company has no settings row yet.
     */
    public static function notificationsEnabled(int $companyId): bool
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        return (bool) ($setting?->notifications_enabled ?? false);
    }

    /**
     * @return array{
     *     notifications_enabled: bool,
     *     notification_recipient_user_ids: list<int>,
     *     alert_signoff_overdue: bool,
     *     alert_signoff_no_relief: bool,
     *     alert_relief_not_ready: bool,
     *     alert_current_manning_gap: bool,
     *     alert_projected_manning_gap: bool
     * }
     */
    public static function notificationSettings(int $companyId): array
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        return [
            'notifications_enabled' => (bool) ($setting?->notifications_enabled ?? false),
            'notification_recipient_user_ids' => self::notificationRecipientUserIds($companyId, $setting),
            'alert_signoff_overdue' => (bool) ($setting?->alert_signoff_overdue ?? true),
            'alert_signoff_no_relief' => (bool) ($setting?->alert_signoff_no_relief ?? true),
            'alert_relief_not_ready' => (bool) ($setting?->alert_relief_not_ready ?? true),
            'alert_current_manning_gap' => (bool) ($setting?->alert_current_manning_gap ?? true),
            'alert_projected_manning_gap' => (bool) ($setting?->alert_projected_manning_gap ?? true),
        ];
    }

    /**
     * @return list<CrewOperationalAlertType>
     */
    public static function enabledAlertTypes(int $companyId): array
    {
        $settings = self::notificationSettings($companyId);
        $types = [];

        foreach (CrewOperationalAlertType::cases() as $type) {
            if ($settings[$type->settingsColumn()] ?? false) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Active company users with active membership for the recipient picker.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function notificationRecipientOptions(int $companyId): array
    {
        return self::activeCompanyUsers($companyId)
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $departmentIds
     * @param  array{
     *     notifications_enabled?: bool,
     *     notification_recipient_user_ids?: list<int>,
     *     alert_signoff_overdue?: bool,
     *     alert_signoff_no_relief?: bool,
     *     alert_relief_not_ready?: bool,
     *     alert_current_manning_gap?: bool,
     *     alert_projected_manning_gap?: bool,
     *     actor_id?: int|null
     * }  $options
     */
    public static function saveSettings(
        int $companyId,
        array $departmentIds,
        int $maxHomeDays,
        bool $syncSeaService = true,
        array $options = [],
    ): CrewOperationsSetting {
        $normalized = array_values(array_unique(array_map(intval(...), $departmentIds)));

        $setting = DB::transaction(function () use (
            $companyId,
            $normalized,
            $maxHomeDays,
            $syncSeaService,
            $options,
        ): CrewOperationsSetting {
            $existing = CrewOperationsSetting::query()
                ->where('company_id', $companyId)
                ->first();

            $previousSync = $existing === null
                ? true
                : (bool) $existing->sync_sea_service;

            $recipientIds = array_key_exists('notification_recipient_user_ids', $options)
                ? self::normalizeRecipientUserIds(
                    $companyId,
                    $options['notification_recipient_user_ids'] ?? [],
                )
                : self::notificationRecipientUserIds($companyId, $existing);

            $setting = CrewOperationsSetting::query()->updateOrCreate(
                ['company_id' => $companyId],
                [
                    'pool_department_ids' => $normalized === [] ? null : $normalized,
                    'max_home_days' => $maxHomeDays,
                    'sync_sea_service' => $syncSeaService,
                    'notifications_enabled' => array_key_exists('notifications_enabled', $options)
                        ? (bool) $options['notifications_enabled']
                        : (bool) ($existing?->notifications_enabled ?? false),
                    'notification_recipient_user_ids' => $recipientIds === [] ? null : $recipientIds,
                    'alert_signoff_overdue' => array_key_exists('alert_signoff_overdue', $options)
                        ? (bool) $options['alert_signoff_overdue']
                        : (bool) ($existing?->alert_signoff_overdue ?? true),
                    'alert_signoff_no_relief' => array_key_exists('alert_signoff_no_relief', $options)
                        ? (bool) $options['alert_signoff_no_relief']
                        : (bool) ($existing?->alert_signoff_no_relief ?? true),
                    'alert_relief_not_ready' => array_key_exists('alert_relief_not_ready', $options)
                        ? (bool) $options['alert_relief_not_ready']
                        : (bool) ($existing?->alert_relief_not_ready ?? true),
                    'alert_current_manning_gap' => array_key_exists('alert_current_manning_gap', $options)
                        ? (bool) $options['alert_current_manning_gap']
                        : (bool) ($existing?->alert_current_manning_gap ?? true),
                    'alert_projected_manning_gap' => array_key_exists('alert_projected_manning_gap', $options)
                        ? (bool) $options['alert_projected_manning_gap']
                        : (bool) ($existing?->alert_projected_manning_gap ?? true),
                ],
            );

            if ($previousSync !== $syncSeaService) {
                $activity = activity()
                    ->performedOn($setting)
                    ->withProperties([
                        'company_id' => $companyId,
                        'setting_key' => self::CONFIG_SYNC_SEA_SERVICE,
                        'old' => ['sync_sea_service' => $previousSync],
                        'attributes' => ['sync_sea_service' => $syncSeaService],
                        'old_values' => ['sync_sea_service' => $previousSync],
                        'new_values' => ['sync_sea_service' => $syncSeaService],
                    ]);

                $actorId = $options['actor_id'] ?? null;

                if (is_int($actorId) && $actorId > 0) {
                    $activity->causedBy($actorId);
                }

                $activity->log('updated crew operations sea service sync setting');
            }

            return $setting;
        });

        if ((bool) $setting->notifications_enabled) {
            $activeAlertIds = CrewOperationalAlert::query()
                ->where('company_id', $companyId)
                ->where('status', CrewOperationalAlertStatus::Active)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            app(SyncCrewOperationalAlertRecipients::class)->forCompany($companyId, $activeAlertIds);
            app(QueueCrewOperationalAlertPushes::class)->forAlerts($companyId, $activeAlertIds);
        }

        return $setting;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public static function normalizeRecipientUserIds(int $companyId, array $userIds): array
    {
        $normalized = array_values(array_unique(array_map(intval(...), $userIds)));

        if ($normalized === []) {
            return [];
        }

        $allowed = self::activeCompanyUsers($companyId)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        $invalid = array_values(array_diff($normalized, $allowed));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'notification_recipient_user_ids' => 'One or more selected recipients are not active members of this company.',
            ]);
        }

        return $normalized;
    }

    /**
     * @return Collection<int, User>
     */
    public static function activeCompanyUsers(int $companyId)
    {
        $access = app(ResolveCompanyAccess::class);

        return User::query()
            ->whereNull('deleted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereHas('companies', function (Builder $membership) use ($companyId): void {
                    $membership->where('companies.id', $companyId)
                        ->where('companies.status', 'active')
                        ->where('company_user.status', 'active');
                })->orWhere(function (Builder $legacy) use ($companyId): void {
                    $legacy->where('company_id', $companyId)
                        ->whereDoesntHave('companies', function (Builder $membership) use ($companyId): void {
                            $membership->where('companies.id', $companyId);
                        });
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status', 'company_id', 'deleted_at'])
            ->filter(fn (User $user): bool => $access->hasAccessibleMembership($user, $companyId))
            ->values();
    }

    /**
     * @return list<int>
     */
    private static function notificationRecipientUserIds(
        int $companyId,
        ?CrewOperationsSetting $setting = null,
    ): array {
        $setting ??= CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting === null || $setting->notification_recipient_user_ids === null) {
            return [];
        }

        $stored = array_values(array_unique(array_map(
            intval(...),
            array_filter($setting->notification_recipient_user_ids, fn ($id) => is_numeric($id)),
        )));

        if ($stored === []) {
            return [];
        }

        $allowed = self::activeCompanyUsers($companyId)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_intersect($stored, $allowed));
    }

    /**
     * Expands configured pool departments to include all descendant departments.
     *
     * @return list<int>
     */
    public static function expandedPoolDepartmentIds(int $companyId): array
    {
        $selected = self::poolDepartmentIds($companyId);

        if ($selected === []) {
            return [];
        }

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'parent_id'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'parent_id' => $department->parent_id,
            ])
            ->all();

        $expanded = [];

        foreach ($selected as $departmentId) {
            $expanded = array_merge(
                $expanded,
                DepartmentDescendantIds::includingSelf($departmentId, $departments),
            );
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<array{id: int, name: string, children: list<mixed>}>
     */
    public static function activeDepartmentTree(int $companyId): array
    {
        return BuildDepartmentTree::forCompany($companyId);
    }

    /**
     * @return list<int>
     */
    public static function allActiveDepartmentIds(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function activeDepartments(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * All active ranked employees for the planning crew sidebar and assign picker.
     *
     * Filtered by configured pool departments (including descendants) when set.
     * Does not exclude employees based on deployment or crew availability status.
     *
     * @return list<array{id: int, name: string, rank_id: int, rank_name: string}>
     */
    public static function poolEmployees(int $companyId): array
    {
        $departmentIds = self::expandedPoolDepartmentIds($companyId);

        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->active()
            ->whereNull('employees.termination_date')
            ->whereNotNull('employees.rank_id')
            ->when($departmentIds !== [], fn (Builder $q) => $q->whereIn('employees.department_id', $departmentIds))
            ->join('ranks', 'employees.rank_id', '=', 'ranks.id')
            ->whereNull('ranks.deleted_at')
            ->where('ranks.is_active', true)
            ->orderBy('employees.name')
            ->get([
                'employees.id',
                'employees.name',
                'employees.rank_id',
                'ranks.name as rank_name',
            ])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'rank_id' => (int) $employee->rank_id,
                'rank_name' => (string) $employee->rank_name,
            ])
            ->all();
    }
}
