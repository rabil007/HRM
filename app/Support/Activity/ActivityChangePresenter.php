<?php

namespace App\Support\Activity;

use App\Enums\CrewPhaseCode;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Gender;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\SalaryInputType;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Models\VisaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class ActivityChangePresenter
{
    /**
     * Custom activity properties that are metadata, not end-user field chips.
     *
     * @var list<string>
     */
    private const HIDDEN_PROPERTY_KEYS = [
        'event',
        'company_id',
        'occurred_at',
    ];

    /**
     * @param  Collection<int, Activity>|iterable<int, Activity>  $logs
     * @return Collection<int, Activity>
     */
    public static function presentLogs(iterable $logs, int $companyId): Collection
    {
        $collection = $logs instanceof Collection ? $logs : collect($logs);

        if ($collection->isEmpty()) {
            return $collection;
        }

        $lookup = self::buildLookup($collection, $companyId);

        return $collection->map(function (Activity $log) use ($lookup, $companyId): Activity {
            // Correction events store context in properties rather than attribute_changes.
            // Delegate to the dedicated presenter so the card shows human-readable text.
            $event = $log->properties->get('event');

            if (is_string($event) && str_starts_with($event, 'correction_')) {
                $log->setAttribute(
                    'presented_new_values',
                    CrewCorrectionActivityPresenter::present($log, $companyId),
                );
                $log->setAttribute('presented_old_values', null);

                return $log;
            }

            $changes = $log->attribute_changes;
            $old = is_array($changes?->get('old')) ? $changes->get('old') : null;
            $attributes = is_array($changes?->get('attributes')) ? $changes->get('attributes') : null;

            $log->setAttribute('presented_old_values', self::presentMap($old, $lookup));
            $log->setAttribute(
                'presented_new_values',
                self::presentMap($attributes, $lookup)
                    ?? self::presentMap(self::customPropertyMap($log), $lookup),
            );

            return $log;
        });
    }

    /**
     * @return array{id: int, event: string|null, description: string|null, causer: array{id: int, name: string, email: string}|null, old_values: mixed, new_values: mixed, created_at: mixed}
     */
    public static function toRecentActivityArray(Activity $log): array
    {
        $causer = $log->relationLoaded('causer') ? $log->causer : null;

        return [
            'id' => $log->id,
            'event' => $log->event,
            'description' => $log->description,
            'causer' => $causer ? [
                'id' => $causer->id,
                'name' => $causer->name,
                'email' => $causer->email,
            ] : null,
            'old_values' => $log->getAttribute('presented_old_values')
                ?? (is_array($log->attribute_changes?->get('old')) ? $log->attribute_changes->get('old') : null),
            'new_values' => $log->getAttribute('presented_new_values')
                ?? (is_array($log->attribute_changes?->get('attributes')) ? $log->attribute_changes->get('attributes') : null),
            'created_at' => $log->created_at,
        ];
    }

    public static function subjectLabel(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        foreach (['name', 'title', 'email', 'code', 'slug', 'document_type', 'label'] as $attribute) {
            $value = data_get($subject, $attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if ($subject instanceof EmployeeContract) {
            $start = $subject->start_date;

            return $start
                ? 'Contract '.$start->format('d-m-Y')
                : 'Contract #'.$subject->id;
        }

        if ($subject instanceof PayrollPeriod && is_string($subject->name) && $subject->name !== '') {
            return $subject->name;
        }

        return null;
    }

    /**
     * @param  Collection<int, Activity>  $logs
     * @return array<string, array<int, string>>
     */
    private static function buildLookup(Collection $logs, int $companyId): array
    {
        /** @var array<string, array<int, true>> $idsByField */
        $idsByField = [];

        foreach ($logs as $log) {
            $changes = $log->attribute_changes;

            foreach (['old', 'attributes'] as $bucket) {
                $values = $changes?->get($bucket);

                if (! is_array($values)) {
                    continue;
                }

                self::collectResolvableIds($idsByField, $values);
            }

            $customProperties = self::customPropertyMap($log);

            if ($customProperties !== null) {
                self::collectResolvableIds($idsByField, $customProperties);
            }
        }

        /** @var array<string, array<int, string>> $lookup */
        $lookup = [];

        foreach ($idsByField as $field => $idMap) {
            $ids = array_keys($idMap);
            $definition = self::fieldDefinition($field);

            if ($definition === null || $ids === []) {
                continue;
            }

            $lookup[$field] = self::loadLabels(
                $definition['model'],
                $definition['attribute'],
                $ids,
                $definition['companyScoped'] ? $companyId : null,
                $definition['formatter'] ?? null,
            );
        }

        return $lookup;
    }

    /**
     * @param  array<string, array<int, true>>  $idsByField
     * @param  array<string, mixed>  $values
     */
    private static function collectResolvableIds(array &$idsByField, array $values): void
    {
        foreach ($values as $field => $value) {
            if (! is_string($field) || ! self::isResolvableField($field)) {
                continue;
            }

            $id = self::normalizeId($value);

            if ($id === null) {
                continue;
            }

            $idsByField[$field][$id] = true;
        }
    }

    /**
     * Custom activity events store context in `properties` rather than
     * Spatie's `attribute_changes` old/attributes buckets.
     *
     * @return array<string, mixed>|null
     */
    private static function customPropertyMap(Activity $log): ?array
    {
        $properties = $log->properties;

        if (! $properties instanceof Collection || $properties->isEmpty()) {
            return null;
        }

        if ($properties->has('attributes') || $properties->has('old')) {
            return null;
        }

        $map = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key) || in_array($key, self::HIDDEN_PROPERTY_KEYS, true)) {
                continue;
            }

            $map[$key] = $value;
        }

        return $map === [] ? null : $map;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @param  array<string, array<int, string>>  $lookup
     * @return array<string, mixed>|null
     */
    private static function presentMap(?array $values, array $lookup): ?array
    {
        if ($values === null) {
            return null;
        }

        $presented = [];

        foreach ($values as $field => $value) {
            $presented[$field] = self::presentValue(
                is_string($field) ? $field : (string) $field,
                $value,
                $lookup,
            );
        }

        return $presented;
    }

    /**
     * @param  array<string, array<int, string>>  $lookup
     */
    private static function presentValue(string $field, mixed $value, array $lookup): mixed
    {
        $id = self::normalizeId($value);

        if ($id === null || ! isset($lookup[$field][$id])) {
            return $value;
        }

        return $lookup[$field][$id];
    }

    private static function normalizeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    private static function isResolvableField(string $field): bool
    {
        return self::fieldDefinition($field) !== null;
    }

    /**
     * @return array{model: class-string<Model>, attribute: string, companyScoped: bool, formatter?: callable(Model): string}|null
     */
    private static function fieldDefinition(string $field): ?array
    {
        /** @var array<string, array{model: class-string<Model>, attribute: string, companyScoped: bool, formatter?: callable(Model): string}> $map */
        static $map = null;

        if ($map === null) {
            $user = [
                'model' => User::class,
                'attribute' => 'name',
                'companyScoped' => true,
            ];
            $employee = [
                'model' => Employee::class,
                'attribute' => 'name',
                'companyScoped' => true,
                'formatter' => static function (Employee $employee): string {
                    $name = (string) $employee->name;

                    if (is_string($employee->employee_no) && $employee->employee_no !== '') {
                        return $name.' ('.$employee->employee_no.')';
                    }

                    return $name;
                },
            ];
            $vessel = [
                'model' => Vessel::class,
                'attribute' => 'name',
                'companyScoped' => false,
            ];
            $client = [
                'model' => Client::class,
                'attribute' => 'name',
                'companyScoped' => false,
            ];
            $assignment = [
                'model' => CrewAssignment::class,
                'attribute' => 'assignment_no',
                'companyScoped' => true,
            ];
            $phase = [
                'model' => CrewAssignmentPhase::class,
                'attribute' => 'phase_code',
                'companyScoped' => true,
                'formatter' => static function (CrewAssignmentPhase $phase): string {
                    $code = $phase->phase_code;

                    if ($code instanceof CrewPhaseCode) {
                        return strtoupper($code->value).' '.$code->label();
                    }

                    return 'Phase #'.$phase->id;
                },
            ];

            $map = [
                'employee_id' => $employee,
                'manager_id' => $employee,
                'user_id' => $user,
                'uploaded_by' => $user,
                'replaced_by' => $user,
                'created_by' => $user,
                'approved_by' => $user,
                'prepared_by' => $user,
                'submitted_by' => $user,
                'returned_by' => $user,
                'applied_by' => $user,
                'requested_by' => $user,
                'decided_by' => $user,
                'started_by' => $user,
                'completed_by' => $user,
                'triggered_by' => $user,
                'operational_approved_by' => $user,
                'branch_id' => [
                    'model' => Branch::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'department_id' => [
                    'model' => Department::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'parent_id' => [
                    'model' => Department::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'position_id' => [
                    'model' => Position::class,
                    'attribute' => 'title',
                    'companyScoped' => true,
                ],
                'rank_id' => [
                    'model' => Rank::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'project_id' => [
                    'model' => Project::class,
                    'attribute' => 'title',
                    'companyScoped' => false,
                ],
                'client_id' => $client,
                'destination_client_id' => $client,
                'course_id' => [
                    'model' => Course::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'document_type_id' => [
                    'model' => DocumentType::class,
                    'attribute' => 'title',
                    'companyScoped' => false,
                ],
                'leave_type_id' => [
                    'model' => LeaveType::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'vessel_id' => $vessel,
                'source_vessel_id' => $vessel,
                'destination_vessel_id' => $vessel,
                'vessel_type_id' => [
                    'model' => VesselType::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'bank_id' => [
                    'model' => Bank::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'gender_id' => [
                    'model' => Gender::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'religion_id' => [
                    'model' => Religion::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'country_id' => [
                    'model' => Country::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'currency_id' => [
                    'model' => Currency::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'visa_type_id' => [
                    'model' => VisaType::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'company_visa_type_id' => [
                    'model' => CompanyVisaType::class,
                    'attribute' => 'name',
                    'companyScoped' => false,
                ],
                'period_id' => [
                    'model' => PayrollPeriod::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'salary_input_type_id' => [
                    'model' => SalaryInputType::class,
                    'attribute' => 'name',
                    'companyScoped' => true,
                ],
                'contract_id' => [
                    'model' => EmployeeContract::class,
                    'attribute' => 'id',
                    'companyScoped' => true,
                    'formatter' => static function (EmployeeContract $contract): string {
                        $start = $contract->start_date;

                        return $start
                            ? 'Contract '.$start->format('d-m-Y')
                            : 'Contract #'.$contract->id;
                    },
                ],
                'current_phase_id' => $phase,
                'crew_assignment_phase_id' => $phase,
                'crew_assignment_id' => $assignment,
                'previous_assignment_id' => $assignment,
                'source_assignment_id' => $assignment,
                'destination_assignment_id' => $assignment,
            ];
        }

        return $map[$field] ?? null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $ids
     * @param  (callable(Model): string)|null  $formatter
     * @return array<int, string>
     */
    private static function loadLabels(
        string $modelClass,
        string $attribute,
        array $ids,
        ?int $companyId,
        ?callable $formatter,
    ): array {
        /** @var Builder<Model> $query */
        $query = $modelClass::query()->whereIn('id', $ids);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        $columns = array_values(array_unique(array_filter([
            'id',
            $attribute,
            $modelClass === Employee::class ? 'employee_no' : null,
            $modelClass === EmployeeContract::class ? 'start_date' : null,
            $modelClass === CrewAssignmentPhase::class ? 'phase_code' : null,
            $modelClass === CrewAssignment::class ? 'assignment_no' : null,
        ])));

        $labels = [];

        foreach ($query->get($columns) as $model) {
            $id = (int) $model->getKey();

            if ($formatter !== null) {
                $labels[$id] = $formatter($model);

                continue;
            }

            $raw = $model->getAttribute($attribute);
            $labels[$id] = is_scalar($raw) && (string) $raw !== ''
                ? (string) $raw
                : '#'.$id;
        }

        return $labels;
    }
}
