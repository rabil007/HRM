<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;

class CrewAssignmentAccess
{
    public static function findForCompany(int $companyId, int $id): ?CrewAssignment
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->with([
                'employee',
                'rank',
                'client',
                'vessel',
                'companyVisaType',
                'currentPhase',
                'phases',
                'planningAssignment',
            ])
            ->first();
    }

    public static function assertInCompany(CrewAssignment $assignment, int $companyId): void
    {
        abort_unless($assignment->company_id === $companyId, 404);
    }
}
