<?php

namespace App\Support\MasterData;

use App\Models\Employee;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Guards Project client reassignment so Employee Client↔Project pairs stay consistent.
 */
final class GuardProjectClientChange
{
    /**
     * Whether changing the Project's Client would leave Employees inconsistent.
     *
     * Employees with a non-null client_id that differs from the destination Client
     * (while still referencing this Project) block the change.
     *
     * Employees with project_id set and client_id null are legacy rows and do not
     * block re-parenting — they remain historically unscoped until edited.
     */
    public static function wouldBreakEmployeeConsistency(Project $project, ?int $newClientId): bool
    {
        $currentClientId = $project->client_id !== null ? (int) $project->client_id : null;
        $destinationClientId = $newClientId !== null && $newClientId > 0 ? $newClientId : null;

        if ($currentClientId === $destinationClientId) {
            return false;
        }

        // Unassigning is blocked separately; treat as a consistency break here too.
        if ($currentClientId !== null && $destinationClientId === null) {
            return true;
        }

        // First-time assignment (null → Client) never conflicts with a stored Client A.
        if ($currentClientId === null) {
            return false;
        }

        // Destination Client is set and differs from current.
        return Employee::query()
            ->where('project_id', $project->id)
            ->whereNotNull('client_id')
            ->where('client_id', '!=', $destinationClientId)
            ->exists();
    }

    public static function assertCanChangeClient(Project $project, ?int $newClientId): void
    {
        if (! self::wouldBreakEmployeeConsistency($project, $newClientId)) {
            return;
        }

        throw ValidationException::withMessages([
            'client_id' => 'This project cannot be moved to another client because employees are currently assigned to it.',
        ]);
    }

    public static function canChangeClient(Project $project, ?int $newClientId): bool
    {
        return ! self::wouldBreakEmployeeConsistency($project, $newClientId);
    }
}
