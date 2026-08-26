<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentRequirement;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class DocumentRequirementResolver
{
    /**
     * @return Collection<int, DocumentRequirement>
     */
    public function activeRequirementsForCompany(int $companyId): Collection
    {
        return DocumentRequirement::query()
            ->forCompany($companyId)
            ->active()
            ->whereHas('documentType', fn ($query) => $query->where('is_active', true))
            ->with([
                'documentType:id,title,is_active',
                'departments:id,name',
                'positions:id,title',
                'ranks:id,name',
            ])
            ->get()
            ->unique('document_type_id')
            ->values();
    }

    /**
     * @return Collection<int, DocumentRequirement>
     */
    public function requirementsForEmployee(Employee $employee): Collection
    {
        return $this->activeRequirementsForCompany((int) $employee->company_id)
            ->filter(fn (DocumentRequirement $requirement): bool => $this->matches($employee, $requirement))
            ->unique('document_type_id')
            ->values();
    }

    public function matches(Employee $employee, DocumentRequirement $requirement): bool
    {
        if ((int) $requirement->company_id !== (int) $employee->company_id) {
            return false;
        }

        if (! $requirement->is_active) {
            return false;
        }

        if ($requirement->required_for_all) {
            return true;
        }

        if ($employee->department_id !== null && $requirement->departments->contains('id', $employee->department_id)) {
            return true;
        }

        if ($employee->position_id !== null && $requirement->positions->contains('id', $employee->position_id)) {
            return true;
        }

        if ($employee->rank_id !== null && $requirement->ranks->contains('id', $employee->rank_id)) {
            return true;
        }

        return false;
    }
}
