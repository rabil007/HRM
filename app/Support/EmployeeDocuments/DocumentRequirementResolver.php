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
                'projects:id,title',
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

        // Position is canonical for occupational matching (Phase 2).
        // Legacy document_requirement_rank pivot is retained until Phase 3 but not used here.
        return $this->categoryMatches($requirement->departments, $employee->department_id)
            && $this->categoryMatches($requirement->positions, $employee->position_id)
            && $this->categoryMatches($requirement->projects, $employee->project_id);
    }

    /**
     * Empty categories impose no restriction. A selected category matches when the
     * employee value is present and equals one of the selected IDs.
     *
     * @param  Collection<int, mixed>  $selected
     */
    private function categoryMatches(Collection $selected, mixed $employeeValue): bool
    {
        if ($selected->isEmpty()) {
            return true;
        }

        if ($employeeValue === null) {
            return false;
        }

        return $selected->contains('id', (int) $employeeValue);
    }
}
