<?php

namespace App\Support\Employees\Actions;

use App\Models\Employee;

final class SyncEmployeeWorkAssignments
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function sync(Employee $employee, array $validated): void
    {
        if (array_key_exists('approval_location_ids', $validated)) {
            $employee->approvalLocations()->sync(
                collect($validated['approval_location_ids'] ?? [])
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            );
        }

        if (array_key_exists('sssa_option_ids', $validated)) {
            $employee->sssaOptions()->sync(
                collect($validated['sssa_option_ids'] ?? [])
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            );
        }
    }
}
