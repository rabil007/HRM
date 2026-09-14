<?php

namespace App\Support\Activity;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class ActivityLogIntelligence
{
    /**
     * @var list<string>
     */
    private const HIDDEN_CHANGE_KEYS = [
        'id',
        'company_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
    ];

    /**
     * @var array<string, string>
     */
    private const MODULE_LABELS = [
        'organization' => 'Organization',
        'employees' => 'Employees',
        'documents' => 'Documents',
        'attendance' => 'Attendance & Leave',
        'payroll' => 'Payroll',
        'crew_operations' => 'Crew Operations',
        'users_security' => 'Users & Security',
        'settings' => 'Settings & Integrations',
        'master_data' => 'Master Data',
        'other' => 'Other',
    ];

    /**
     * @return array{key: string, label: string}
     */
    public static function moduleForType(?string $subjectType): array
    {
        $shortName = self::shortType($subjectType);

        $key = match (true) {
            in_array($shortName, ['User', 'Role', 'Permission'], true),
            Str::contains($shortName, ['Permission', 'Security']) => 'users_security',

            $shortName === 'Vessel',
            Str::startsWith($shortName, ['Crew', 'VesselManning']) => 'crew_operations',

            Str::contains($shortName, ['Payroll', 'Payslip', 'SalaryInput', 'Wps']) => 'payroll',

            Str::contains($shortName, ['Leave', 'Attendance']) => 'attendance',

            Str::contains($shortName, ['Document', 'Signing', 'Signature']) => 'documents',

            Str::startsWith($shortName, 'Employee') => 'employees',

            in_array($shortName, ['Company', 'Branch', 'Department', 'Position'], true) => 'organization',

            Str::contains($shortName, ['Setting', 'Integration', 'Hikvision', 'WhatsApp', 'EmailTemplate', 'Announcement']) => 'settings',

            in_array($shortName, [
                'Bank',
                'Client',
                'CompanyVisaType',
                'Country',
                'Course',
                'Currency',
                'Gender',
                'LeaveType',
                'Project',
                'Rank',
                'Religion',
                'SalaryInputType',
                'VesselType',
                'VisaType',
            ], true) => 'master_data',

            default => 'other',
        };

        return [
            'key' => $key,
            'label' => self::MODULE_LABELS[$key],
        ];
    }

    public static function importanceForType(?string $subjectType): string
    {
        $shortName = self::shortType($subjectType);
        $module = self::moduleForType($subjectType)['key'];

        if ($module === 'users_security') {
            return 'critical';
        }

        if (in_array($module, ['crew_operations', 'payroll'], true)) {
            return 'important';
        }

        if (in_array($shortName, [
            'Employee',
            'EmployeeBankAccount',
            'EmployeeContract',
            'EmployeeDocument',
            'EmployeeSeaService',
            'LeaveRequest',
            'DocumentRequest',
            'DocumentWorkflowRequest',
            'DocumentRecipientRequest',
        ], true)) {
            return 'important';
        }

        return 'normal';
    }

    /**
     * @param  iterable<int, string>  $subjectTypes
     * @return list<array{key: string, label: string}>
     */
    public static function moduleOptions(iterable $subjectTypes): array
    {
        $order = array_flip(array_keys(self::MODULE_LABELS));

        return collect($subjectTypes)
            ->map(fn (string $subjectType): array => self::moduleForType($subjectType))
            ->unique('key')
            ->sortBy(fn (array $module): int => $order[$module['key']] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, string>  $subjectTypes
     * @return list<string>
     */
    public static function typesForModule(iterable $subjectTypes, string $module): array
    {
        return collect($subjectTypes)
            ->filter(fn (string $subjectType): bool => self::moduleForType($subjectType)['key'] === $module)
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, string>  $subjectTypes
     * @return list<string>
     */
    public static function typesForImportance(iterable $subjectTypes, string $importance): array
    {
        return collect($subjectTypes)
            ->filter(fn (string $subjectType): bool => self::importanceForType($subjectType) === $importance)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     event: string|null,
     *     subject_type: string|null,
     *     subject_name: string,
     *     subject_id: int|string|null,
     *     subject_label: string|null,
     *     subject_type_label: string,
     *     description: string|null,
     *     causer: array{id: int, name: string, email: string}|null,
     *     old_values: mixed,
     *     new_values: mixed,
     *     module_key: string,
     *     module_label: string,
     *     importance: string,
     *     headline: string,
     *     record_url: string|null,
     *     created_at: mixed
     * }
     */
    public static function present(Activity $log, ?User $viewer): array
    {
        $presented = ActivityChangePresenter::toRecentActivityArray($log);
        $subject = $log->relationLoaded('subject') && $log->subject instanceof Model
            ? $log->subject
            : null;
        $subjectType = is_string($log->subject_type) && $log->subject_type !== ''
            ? $log->subject_type
            : null;
        $module = self::moduleForType($subjectType);
        $subjectTypeLabel = self::subjectTypeLabel($subjectType);
        $subjectLabel = self::subjectLabel($subject, $log);
        $changedKeys = self::changedKeys($presented['old_values'], $presented['new_values']);

        return [
            'id' => (int) $log->id,
            'event' => $log->event,
            'subject_type' => $subjectType,
            'subject_name' => self::shortType($subjectType),
            'subject_id' => $log->subject_id,
            'subject_label' => $subjectLabel,
            'subject_type_label' => $subjectTypeLabel,
            'description' => $log->description ?: null,
            'causer' => $presented['causer'],
            'old_values' => $presented['old_values'],
            'new_values' => $presented['new_values'],
            'module_key' => $module['key'],
            'module_label' => $module['label'],
            'importance' => self::importanceForType($subjectType),
            'headline' => self::headline(
                $log,
                $presented['causer']['name'] ?? 'User',
                $subjectTypeLabel,
                $subjectLabel,
                $changedKeys,
            ),
            'record_url' => self::recordUrl($subject, $viewer, (int) $log->company_id),
            'created_at' => $log->created_at,
        ];
    }

    private static function shortType(?string $subjectType): string
    {
        if (! is_string($subjectType) || $subjectType === '') {
            return 'Activity';
        }

        return class_basename($subjectType);
    }

    private static function subjectTypeLabel(?string $subjectType): string
    {
        $shortName = self::shortType($subjectType);

        return match ($shortName) {
            'CrewAssignment' => 'Crew assignment',
            'CrewAssignmentPhase' => 'Crew phase',
            'EmployeeBankAccount' => 'Employee bank account',
            'EmployeeContract' => 'Employee contract',
            'EmployeeDocument' => 'Employee document',
            'EmployeeSeaService' => 'Sea service',
            'PayrollPeriod' => 'Payroll period',
            default => Str::headline($shortName),
        };
    }

    private static function subjectLabel(?Model $subject, Activity $log): ?string
    {
        $label = ActivityChangePresenter::subjectLabel($subject);

        if ($label !== null) {
            return $label;
        }

        foreach (['assignment_no', 'employee_no', 'reference_no'] as $attribute) {
            $value = $subject?->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $log->subject_id !== null ? '#'.$log->subject_id : null;
    }

    /**
     * @return list<string>
     */
    private static function changedKeys(mixed $oldValues, mixed $newValues): array
    {
        if (! is_array($oldValues) && ! is_array($newValues)) {
            return [];
        }

        return collect(array_unique(array_merge(
            array_keys(is_array($oldValues) ? $oldValues : []),
            array_keys(is_array($newValues) ? $newValues : []),
        )))
            ->filter(fn (mixed $key): bool => is_string($key) && ! in_array($key, self::HIDDEN_CHANGE_KEYS, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $changedKeys
     */
    private static function headline(
        Activity $log,
        string $actor,
        string $subjectTypeLabel,
        ?string $subjectLabel,
        array $changedKeys,
    ): string {
        $target = trim($subjectTypeLabel.' '.($subjectLabel ?? ''));
        $event = strtolower(trim((string) $log->event));

        if ($event === 'updated' && count($changedKeys) === 1) {
            return $actor.' changed '.self::fieldLabel($changedKeys[0]).' on '.$target;
        }

        $verb = match ($event) {
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            'approved' => 'approved',
            'submitted' => 'submitted',
            'returned' => 'returned',
            'cancelled', 'canceled' => 'cancelled',
            'voided' => 'voided',
            'shared' => 'shared',
            'signed' => 'signed',
            'published' => 'published',
            'restored' => 'restored',
            'requested' => 'requested',
            'completed' => 'completed',
            'started' => 'started',
            'applied' => 'applied',
            'generated' => 'generated',
            'sent' => 'sent',
            'resent' => 'resent',
            'uploaded' => 'uploaded',
            'imported' => 'imported',
            'exported' => 'exported',
            default => null,
        };

        if ($verb !== null) {
            return $actor.' '.$verb.' '.$target;
        }

        $description = trim((string) $log->description);

        if ($description !== '' && strtolower($description) !== $event) {
            return $actor.': '.$description;
        }

        return $actor.' performed '.Str::lower(Str::headline($event ?: 'activity')).' on '.$target;
    }

    private static function fieldLabel(string $field): string
    {
        $label = Str::replaceEnd('_id', '', $field);

        return Str::headline($label);
    }

    private static function recordUrl(?Model $subject, ?User $viewer, int $companyId): ?string
    {
        if (! $subject || ! $viewer) {
            return null;
        }

        $subjectCompanyId = $subject->getAttribute('company_id');

        if ($subjectCompanyId !== null && (int) $subjectCompanyId !== $companyId) {
            return null;
        }

        if ($subject instanceof Employee && $viewer->can('employees.view')) {
            return route('organization.employees.show', $subject);
        }

        if ($subject instanceof EmployeeDocument && $viewer->can('documents.view')) {
            return route('organization.documents.employee.files.show', [
                'employee' => $subject->employee_id,
                'document' => $subject->id,
            ]);
        }

        if ($subject instanceof CrewAssignment && $viewer->can('crew_operations.assignments.view')) {
            return route('organization.crew-assignments.show', $subject);
        }

        if ($subject instanceof Vessel && $viewer->can('crew_operations.vessels.view')) {
            return route('organization.vessels.show', $subject);
        }

        if ($subject instanceof PayrollPeriod && $viewer->can('payroll.periods.view')) {
            return route('payroll.show', $subject);
        }

        if ($subject instanceof Company && $viewer->can('companies.view')) {
            return route('organization.companies.show', $subject);
        }

        if ($subject instanceof Branch && $viewer->can('branches.view')) {
            return route('organization.branches.show', $subject);
        }

        if ($subject instanceof Department && $viewer->can('departments.view')) {
            return route('organization.departments.show', $subject);
        }

        if ($subject instanceof Position && $viewer->can('positions.view')) {
            return route('organization.positions.show', $subject);
        }

        if ($subject instanceof User && $viewer->can('users.view')) {
            return route('organization.users.show', $subject);
        }

        $employeeId = $subject->getAttribute('employee_id');

        if ($viewer->can('employees.view') && is_numeric($employeeId) && (int) $employeeId > 0) {
            return route('organization.employees.show', ['employee' => (int) $employeeId]);
        }

        return null;
    }
}
