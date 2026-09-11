<?php

namespace App\Support\MasterData;

use App\Enums\CrewPhaseCode;
use App\Models\ApprovalLocation;
use App\Models\Bank;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Course;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentInstance;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVaccination;
use App\Models\Gender;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\SssaOption;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use App\Models\VisaType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MasterDataUsage
{
    /**
     * @param  iterable<int, Model|array<string, mixed>>  $items
     * @param  class-string<Model>|null  $modelClass
     * @return list<Model|array<string, mixed>>
     */
    public static function decorate(
        iterable $items,
        bool $canDeletePermission,
        ?int $companyId = null,
        ?string $modelClass = null,
    ): array {
        $rows = collect($items)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $first = $rows->first();
        $resolvedClass = $modelClass;

        if ($first instanceof Model) {
            $resolvedClass = $first::class;
        }

        if ($resolvedClass === null) {
            throw new InvalidArgumentException('A model class is required to decorate master-data usage for array items.');
        }

        $ids = $rows->map(function (Model|array $item): int {
            if ($item instanceof Model) {
                return (int) $item->getKey();
            }

            return (int) ($item['id'] ?? 0);
        })->filter(fn (int $id): bool => $id > 0)->all();

        $summaries = self::summariesForIds($resolvedClass, $ids, $companyId);

        return $rows->map(function (Model|array $item) use ($summaries, $canDeletePermission, $companyId): Model|array {
            $id = $item instanceof Model
                ? (int) $item->getKey()
                : (int) ($item['id'] ?? 0);

            $flags = ($summaries[$id] ?? MasterDataUsageSummary::none())->flags($canDeletePermission, $companyId);

            if ($item instanceof Model) {
                foreach ($flags as $key => $value) {
                    $item->setAttribute($key, $value);
                }

                return $item;
            }

            return [...$item, ...$flags];
        })->all();
    }

    /**
     * @return array{
     *     is_in_use: bool,
     *     can_delete: bool,
     *     usage_count: int|null,
     *     usage_label: string|null
     * }
     */
    public static function flagsFor(Model $record, bool $canDeletePermission, ?int $companyId = null): array
    {
        return self::summary($record, $companyId)->flags($canDeletePermission, $companyId);
    }

    public static function summary(Model $record, ?int $companyId = null): MasterDataUsageSummary
    {
        $summaries = self::summariesForIds($record::class, [(int) $record->getKey()], $companyId);

        return $summaries[(int) $record->getKey()] ?? MasterDataUsageSummary::none();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $ids
     * @return array<int, MasterDataUsageSummary>
     */
    public static function summariesForIds(string $modelClass, array $ids, ?int $companyId = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $tenantScoped = self::isTenantScoped($modelClass);
        $globalTotals = [];
        $globalLabels = [];
        $scopedTotals = [];
        $scopedLabels = [];

        foreach (self::sourcesFor($modelClass) as $source) {
            foreach (self::countsById($source, $ids, null) as $id => $count) {
                if ($count < 1) {
                    continue;
                }

                $globalTotals[$id] = ($globalTotals[$id] ?? 0) + $count;
                $globalLabels[$id][$source->label] = true;
            }

            if ($companyId !== null) {
                foreach (self::countsById($source, $ids, $companyId) as $id => $count) {
                    if ($count < 1) {
                        continue;
                    }

                    $scopedTotals[$id] = ($scopedTotals[$id] ?? 0) + $count;
                    $scopedLabels[$id][$source->label] = true;
                }
            }
        }

        $summaries = [];

        foreach ($ids as $id) {
            $globalCount = $globalTotals[$id] ?? 0;
            $scopedCount = $scopedTotals[$id] ?? 0;
            $labelSource = $scopedLabels[$id] ?? [];
            $scopedLabel = count($labelSource) === 1 ? array_key_first($labelSource) : null;

            $summaries[$id] = new MasterDataUsageSummary(
                globalUsageCount: $globalCount,
                scopedUsageCount: $scopedCount,
                scopedUsageLabel: $scopedLabel,
                tenantScoped: $tenantScoped,
            );
        }

        return $summaries;
    }

    public static function assertDeletable(Model $record, ?int $companyId = null): void
    {
        $summary = self::summary($record, $companyId);

        if (! $summary->isInUse()) {
            return;
        }

        throw ValidationException::withMessages([
            'record' => $summary->blockingMessage(self::displayName($record), $companyId),
        ]);
    }

    public static function denyDeleteRedirect(Model $record, string $routeName, ?int $companyId = null): ?RedirectResponse
    {
        $summary = self::summary($record, $companyId);

        if (! $summary->isInUse()) {
            return null;
        }

        return redirect()
            ->route($routeName)
            ->withErrors([
                'record' => $summary->blockingMessage(self::displayName($record), $companyId),
            ]);
    }

    public static function displayName(Model $record): string
    {
        foreach (['name', 'title', 'code'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $record->getTable().' #'.$record->getKey();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function isTenantScoped(string $modelClass): bool
    {
        return $modelClass === Vessel::class;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<MasterDataUsageSource>
     */
    public static function sourcesFor(string $modelClass): array
    {
        $employee = new Employee;
        $requirement = new DocumentRequirement;

        return match ($modelClass) {
            // Profile-style employee references ignore soft-deleted employees.
            // Historical/operational records keep soft-deleted rows to protect restore/audit integrity.
            Country::class => [
                MasterDataUsageSource::model('companies', Company::class, 'country_id', 'id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('employees', Employee::class, 'nationality_id', 'company_id'),
                MasterDataUsageSource::model('banks', Bank::class, 'country_id'),
                MasterDataUsageSource::model('education records', EmployeeEducationQualification::class, 'country_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('trainings', EmployeeTraining::class, 'country_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('vaccinations', EmployeeVaccination::class, 'country_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::table('recruitment candidates', 'candidates', 'nationality_id', 'company_id'),
            ],
            Currency::class => [
                MasterDataUsageSource::model('companies', Company::class, 'currency_id', 'id', includeSoftDeletedReferences: true),
            ],
            VisaType::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'visa_type_id', 'company_id'),
            ],
            CompanyVisaType::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'company_visa_type_id', 'company_id'),
                MasterDataUsageSource::model('contracts', EmployeeContract::class, 'company_visa_type_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('crew assignments', CrewAssignment::class, 'company_visa_type_id', 'company_id', includeSoftDeletedReferences: true),
            ],
            ApprovalLocation::class => [
                MasterDataUsageSource::pivot(
                    'employees',
                    $employee->approvalLocations()->getTable(),
                    'approval_location_id',
                    Employee::class,
                    'employee_id',
                ),
            ],
            SssaOption::class => [
                MasterDataUsageSource::pivot(
                    'employees',
                    $employee->sssaOptions()->getTable(),
                    'sssa_option_id',
                    Employee::class,
                    'employee_id',
                ),
            ],
            Religion::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'religion_id', 'company_id'),
            ],
            Gender::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'gender_id', 'company_id'),
            ],
            Course::class => [
                MasterDataUsageSource::model('employee trainings', EmployeeTraining::class, 'course_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::json(
                    'crew training phases',
                    CrewAssignmentPhase::class,
                    'details',
                    'course_id',
                    'company_id',
                    includeSoftDeletedReferences: true,
                    whereColumn: 'phase_code',
                    whereValue: CrewPhaseCode::Training->value,
                ),
            ],
            Bank::class => [
                MasterDataUsageSource::model('employee bank accounts', EmployeeBankAccount::class, 'bank_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('pay run records', PayrollRecord::class, 'bank_id', 'company_id', includeSoftDeletedReferences: true),
            ],
            VesselType::class => [
                MasterDataUsageSource::model('vessels', Vessel::class, 'vessel_type_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('sea service records', EmployeeSeaService::class, 'vessel_type_id', 'company_id', includeSoftDeletedReferences: true),
            ],
            Vessel::class => [
                MasterDataUsageSource::model('sea service records', EmployeeSeaService::class, 'vessel_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('crew assignments', CrewAssignment::class, 'vessel_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('vessel manning', VesselManning::class, 'vessel_id', 'company_id'),
                MasterDataUsageSource::model('crew planning', CrewPlanningAssignment::class, 'vessel_id', 'company_id', includeSoftDeletedReferences: true),
            ],
            Rank::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'rank_id', 'company_id'),
                MasterDataUsageSource::model('sea service records', EmployeeSeaService::class, 'rank_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('crew assignments', CrewAssignment::class, 'rank_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('crew planning', CrewPlanningAssignment::class, 'rank_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('vessel manning', VesselManning::class, 'rank_id', 'company_id'),
                MasterDataUsageSource::pivot(
                    'document requirements',
                    $requirement->ranks()->getTable(),
                    'rank_id',
                    DocumentRequirement::class,
                    'document_requirement_id',
                ),
            ],
            Client::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'client_id', 'company_id'),
                MasterDataUsageSource::model('sea service records', EmployeeSeaService::class, 'client_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('crew assignments', CrewAssignment::class, 'client_id', 'company_id', includeSoftDeletedReferences: true),
            ],
            DocumentType::class => [
                MasterDataUsageSource::model('employee documents', EmployeeDocument::class, 'document_type_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('company documents', CompanyDocument::class, 'document_type_id', 'company_id', includeSoftDeletedReferences: true),
                MasterDataUsageSource::model('document requirements', DocumentRequirement::class, 'document_type_id', 'company_id'),
                MasterDataUsageSource::model('document templates', DocumentGenerationTemplate::class, 'document_type_id', 'company_id'),
                MasterDataUsageSource::model('generated documents', DocumentInstance::class, 'document_type_id', 'company_id'),
            ],
            Project::class => [
                MasterDataUsageSource::model('employees', Employee::class, 'project_id', 'company_id'),
                MasterDataUsageSource::pivot(
                    'document requirements',
                    $requirement->projects()->getTable(),
                    'project_id',
                    DocumentRequirement::class,
                    'document_requirement_id',
                ),
            ],
            default => throw new InvalidArgumentException("Master-data usage is not defined for {$modelClass}."),
        };
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function countsById(MasterDataUsageSource $source, array $ids, ?int $companyId): array
    {
        $query = DB::table($source->table);

        if ($source->jsonPath !== null) {
            $referencedExpression = self::jsonReferencedIdExpression($source->table, $source->column, $source->jsonPath);
            $query->whereIn(DB::raw($referencedExpression), $ids);
        } else {
            $query->whereIn($source->table.'.'.$source->column, $ids);
        }

        if ($source->tableUsesSoftDeletes && ! $source->includeSoftDeletedReferences) {
            $query->whereNull($source->table.'.deleted_at');
        }

        if ($source->whereColumn !== null) {
            $query->where($source->table.'.'.$source->whereColumn, $source->whereValue);
        }

        if ($source->companyColumn !== null && $companyId !== null) {
            $query->where($source->table.'.'.$source->companyColumn, $companyId);
        }

        if ($source->relatedTable !== null && $source->relatedLocalKey !== null) {
            $query->join(
                $source->relatedTable,
                $source->relatedTable.'.'.$source->relatedForeignKey,
                '=',
                $source->table.'.'.$source->relatedLocalKey,
            );

            if ($source->relatedUsesSoftDeletes && ! $source->includeSoftDeletedRelatedReferences) {
                $query->whereNull($source->relatedTable.'.deleted_at');
            }

            if ($source->relatedCompanyColumn !== null && $companyId !== null) {
                $query->where($source->relatedTable.'.'.$source->relatedCompanyColumn, $companyId);
            }
        }

        $referencedExpression = $source->jsonPath !== null
            ? self::jsonReferencedIdExpression($source->table, $source->column, $source->jsonPath)
            : $source->table.'.'.$source->column;

        return $query
            ->selectRaw("{$referencedExpression} as referenced_id")
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy(DB::raw($referencedExpression))
            ->pluck('aggregate_count', 'referenced_id')
            ->mapWithKeys(fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    private static function jsonReferencedIdExpression(string $table, string $jsonColumn, string $jsonPath): string
    {
        $qualifiedColumn = "{$table}.{$jsonColumn}";

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(json_extract({$qualifiedColumn}, '$.{$jsonPath}') AS INTEGER)";
        }

        return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$qualifiedColumn}, '$.{$jsonPath}')) AS UNSIGNED)";
    }
}
