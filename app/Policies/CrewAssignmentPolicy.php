<?php

namespace App\Policies;

use App\Models\CrewAssignment;
use App\Models\User;

class CrewAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crew_operations.assignments.view');
    }

    public function view(User $user, CrewAssignment $assignment): bool
    {
        return $user->can('crew_operations.assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crew_operations.assignments.create');
    }

    public function update(User $user, CrewAssignment $assignment): bool
    {
        return $user->can('crew_operations.assignments.update');
    }

    public function performMovement(User $user, CrewAssignment $assignment): bool
    {
        return $user->can('crew_operations.movements.perform');
    }

    public function cancel(User $user, CrewAssignment $assignment): bool
    {
        return $user->can('crew_operations.assignments.cancel');
    }

    public function viewAudit(User $user): bool
    {
        return $user->can('audit.view');
    }
}
