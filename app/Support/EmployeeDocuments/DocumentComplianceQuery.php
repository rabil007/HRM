<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DocumentComplianceQuery
{
    public function __construct(
        private readonly DocumentRequirementResolver $resolver = new DocumentRequirementResolver,
    ) {}

    /**
     * @return array{required: int, valid: int, expiring: int, expired: int, missing: int}
     */
    public function summary(int $companyId, string $departmentId = ''): array
    {
        $row = DB::query()
            ->fromSub($this->statusQuery($companyId, $departmentId), 'compliance')
            ->selectRaw('COUNT(*) as required_count')
            ->selectRaw("SUM(CASE WHEN compliance_status = 'valid' THEN 1 ELSE 0 END) as valid_count")
            ->selectRaw("SUM(CASE WHEN compliance_status = 'expiring' THEN 1 ELSE 0 END) as expiring_count")
            ->selectRaw("SUM(CASE WHEN compliance_status = 'expired' THEN 1 ELSE 0 END) as expired_count")
            ->selectRaw("SUM(CASE WHEN compliance_status = 'missing' THEN 1 ELSE 0 END) as missing_count")
            ->first();

        return [
            'required' => (int) ($row->required_count ?? 0),
            'valid' => (int) ($row->valid_count ?? 0),
            'expiring' => (int) ($row->expiring_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'missing' => (int) ($row->missing_count ?? 0),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $companyId,
        string $statusFilter,
        ?string $search = null,
        int $perPage = 25,
        string $departmentId = '',
    ): LengthAwarePaginator {
        $search = $search !== null ? trim($search) : '';
        $status = $statusFilter === 'required' ? null : $statusFilter;

        $query = DB::query()
            ->fromSub(
                $this->statusQuery($companyId, $departmentId, $search !== '' ? $search : null),
                'compliance',
            )
            ->when(
                $status !== null && DocumentRequirementComplianceStatus::isValidFilter($status),
                fn (Builder $inner) => $inner->where('compliance_status', $status),
            )
            ->orderBy('employee_name')
            ->orderBy('document_type_title')
            ->orderBy('employee_id')
            ->orderBy('document_type_id');

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row): array => $this->mapRow($row));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsForEmployee(Employee $employee): array
    {
        $requirements = $this->resolver->requirementsForEmployee($employee);

        if ($requirements->isEmpty()) {
            return [];
        }

        $latestByType = EmployeeDocument::query()
            ->forCompany((int) $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('document_type_id', $requirements->pluck('document_type_id'))
            ->with(['documentType:id,title'])
            ->latestUpload()
            ->get()
            ->unique('document_type_id')
            ->keyBy('document_type_id');

        return $requirements
            ->map(function ($requirement) use ($latestByType): array {
                $document = $latestByType->get($requirement->document_type_id);
                $expiry = $document !== null
                    ? DocumentExpiry::resolve($document->expiry_date)
                    : null;
                $status = $document === null
                    ? DocumentRequirementComplianceStatus::Missing
                    : DocumentRequirementComplianceStatus::fromExpiry($expiry);

                return [
                    'document_type_id' => (int) $requirement->document_type_id,
                    'document_type' => $requirement->documentType?->title ?? 'Document',
                    'status' => $status->value,
                    'expiry_status' => $expiry?->value,
                    'expiry_label' => $document !== null
                        ? DocumentExpiry::humanLabel($document->expiry_date)
                        : 'Missing',
                    'document_id' => $document?->id,
                    'require_issue_date' => (bool) $requirement->require_issue_date,
                    'require_expiry_date' => (bool) $requirement->require_expiry_date,
                    'require_document_number' => (bool) $requirement->require_document_number,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $expiryDate = $row->expiry_date ?? null;
        $expiry = $expiryDate !== null ? DocumentExpiry::resolve($expiryDate) : null;
        $status = DocumentRequirementComplianceStatus::from($row->compliance_status);

        return [
            'employee_id' => (int) $row->employee_id,
            'employee_name' => (string) $row->employee_name,
            'employee_no' => (string) ($row->employee_no ?? ''),
            'department_name' => $row->department_name !== null ? (string) $row->department_name : null,
            'document_type_id' => (int) $row->document_type_id,
            'document_type' => (string) $row->document_type_title,
            'status' => $status->value,
            'expiry_status' => $expiry?->value,
            'expiry_label' => $status === DocumentRequirementComplianceStatus::Missing
                ? 'Missing'
                : DocumentExpiry::humanLabel($expiryDate),
            'expiry_date' => $expiryDate,
            'document_id' => $row->document_id !== null ? (int) $row->document_id : null,
            'document_name' => $row->original_filename !== null
                ? (string) $row->original_filename
                : (string) $row->document_type_title,
        ];
    }

    private function statusQuery(int $companyId, string $departmentId = '', ?string $search = null): Builder
    {
        $today = now()->toDateString();
        $in30 = now()->addDays(30)->toDateString();

        $latestDocuments = EmployeeDocument::query()
            ->forCompany($companyId)
            ->select('employee_id', 'document_type_id')
            ->selectRaw('MAX(id) as id')
            ->groupBy('employee_id', 'document_type_id');

        return DB::query()
            ->fromSub($this->pairsQuery($companyId, $departmentId, $search), 'pairs')
            ->leftJoinSub($latestDocuments, 'latest_docs', function ($join): void {
                $join->on('latest_docs.employee_id', '=', 'pairs.employee_id')
                    ->on('latest_docs.document_type_id', '=', 'pairs.document_type_id');
            })
            ->leftJoin('employee_documents', 'employee_documents.id', '=', 'latest_docs.id')
            ->leftJoin('departments', 'departments.id', '=', 'pairs.department_id')
            ->select([
                'pairs.employee_id',
                'pairs.employee_name',
                'pairs.employee_no',
                'pairs.document_type_id',
                'pairs.document_type_title',
                'departments.name as department_name',
                'employee_documents.id as document_id',
                'employee_documents.original_filename',
                'employee_documents.expiry_date',
            ])
            ->selectRaw(
                "CASE
                    WHEN employee_documents.id IS NULL THEN 'missing'
                    WHEN employee_documents.expiry_date IS NOT NULL AND employee_documents.expiry_date < ? THEN 'expired'
                    WHEN employee_documents.expiry_date IS NOT NULL AND employee_documents.expiry_date <= ? THEN 'expiring'
                    ELSE 'valid'
                END as compliance_status",
                [$today, $in30],
            );
    }

    private function pairsQuery(int $companyId, string $departmentId = '', ?string $search = null): Builder
    {
        $employees = Employee::query();
        EmployeeDirectoryQuery::applyAttributeFilters(
            $employees,
            $companyId,
            new EmployeeDirectoryFilters(departmentId: $departmentId),
            exceptPosition: true,
        );

        $search = $search !== null ? trim($search) : '';

        return DB::query()
            ->fromSub(
                $employees->select([
                    'employees.id as employee_id',
                    'employees.name as employee_name',
                    'employees.employee_no as employee_no',
                    'employees.department_id as department_id',
                    'employees.position_id as position_id',
                    'employees.rank_id as rank_id',
                    'employees.company_id as company_id',
                ]),
                'employees',
            )
            ->join('document_requirements', function ($join) use ($companyId): void {
                $join->on('document_requirements.company_id', '=', 'employees.company_id')
                    ->where('document_requirements.company_id', $companyId)
                    ->where('document_requirements.is_active', true);
            })
            ->join('document_types', function ($join): void {
                $join->on('document_types.id', '=', 'document_requirements.document_type_id')
                    ->where('document_types.is_active', true)
                    ->whereNull('document_types.deleted_at');
            })
            ->where(function (Builder $query): void {
                $query->where('document_requirements.required_for_all', true)
                    ->orWhereExists(function (Builder $sub): void {
                        $sub->from('document_requirement_department')
                            ->whereColumn('document_requirement_department.document_requirement_id', 'document_requirements.id')
                            ->whereColumn('document_requirement_department.department_id', 'employees.department_id');
                    })
                    ->orWhereExists(function (Builder $sub): void {
                        $sub->from('document_requirement_position')
                            ->whereColumn('document_requirement_position.document_requirement_id', 'document_requirements.id')
                            ->whereColumn('document_requirement_position.position_id', 'employees.position_id');
                    })
                    ->orWhereExists(function (Builder $sub): void {
                        $sub->from('document_requirement_rank')
                            ->whereColumn('document_requirement_rank.document_requirement_id', 'document_requirements.id')
                            ->whereColumn('document_requirement_rank.rank_id', 'employees.rank_id');
                    });
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('employees.employee_name', 'like', $like)
                        ->orWhere('employees.employee_no', 'like', $like)
                        ->orWhere('document_types.title', 'like', $like);
                });
            })
            ->select([
                'employees.employee_id',
                'employees.employee_name',
                'employees.employee_no',
                'employees.department_id',
                'document_requirements.document_type_id',
                'document_types.title as document_type_title',
            ]);
    }
}
