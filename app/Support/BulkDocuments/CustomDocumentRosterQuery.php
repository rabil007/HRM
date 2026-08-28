<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CustomDocumentRosterQuery
{
    /**
     * @param  list<int>|null  $employeeIds
     * @return array{targeted: int, generated: int, not_generated: int, pending_review: int, awaiting_signature: int, approved: int}
     */
    public static function counts(
        int $companyId,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): array {
        $query = BulkDocumentRosterQuery::employeeQuery($companyId, $filters, $employeeIds);
        $targeted = (clone $query)->count();

        $generated = (clone $query)->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
            $instanceQuery->where('company_id', $companyId)
                ->where('document_generation_template_version_id', $version->id);
        })->count();

        $notGenerated = max(0, $targeted - $generated);

        return [
            'targeted' => $targeted,
            'generated' => $generated,
            'not_generated' => $notGenerated,
            'pending_review' => 0,
            'awaiting_signature' => 0,
            'approved' => 0,
        ];
    }

    public static function paginate(
        int $companyId,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        int $perPage,
        string $generationFilter = 'all',
    ): LengthAwarePaginator {
        $query = BulkDocumentRosterQuery::employeeQuery($companyId, $filters)
            ->with([
                'department:id,name',
                'position:id,title',
            ]);

        if ($generationFilter === 'missing') {
            $query->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id);
            });
        } elseif ($generationFilter === 'generated') {
            $query->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id);
            });
        }

        $paginator = $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $employeeIdList = $paginator->getCollection()->pluck('id')->all();

        $instancesByEmployee = DocumentInstance::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_version_id', $version->id)
            ->whereIn('employee_id', $employeeIdList)
            ->with('employeeDocument')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        return $paginator->through(function (Employee $employee) use ($instancesByEmployee): array {
            /** @var DocumentInstance|null $instance */
            $instance = $instancesByEmployee->get($employee->id);
            $doc = $instance?->employeeDocument;

            return [
                'id' => $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->avatar_url,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'email' => $employee->work_email,
                'status' => $employee->status,
                'document' => $doc !== null ? [
                    'id' => $doc->id,
                    'created_at' => $instance?->generated_at?->toIso8601String(),
                ] : null,
                'email_sent_at' => null,
                'signature_status' => null,
                'signature_request' => null,
            ];
        });
    }
}
