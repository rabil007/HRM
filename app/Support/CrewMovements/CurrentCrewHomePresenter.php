<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\CarbonInterface;

final class CurrentCrewHomePresenter
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function listItem(array $item, int $companyId, ?User $user = null): array
    {
        /** @var Employee $employee */
        $employee = $item['employee'];
        /** @var array<string, mixed> $resolved */
        $resolved = $item['resolved'];
        $maxHomeDays = CrewOperationsSettings::maxHomeDays($companyId);
        $daysAtHome = $item['days_at_home'] ?? null;
        $status = CurrentCrewHomeQuery::availabilityStatus(
            is_int($daysAtHome) ? $daysAtHome : null,
            $maxHomeDays,
        );
        $timezone = (string) ($item['company_timezone'] ?? config('app.timezone', 'UTC'));
        $assignment = self::resolveLatestAssignment($resolved, $companyId);

        return [
            'employee' => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
            ],
            'rank' => $employee->rank ? [
                'id' => (int) $employee->rank->id,
                'name' => (string) $employee->rank->name,
            ] : null,
            'last_vessel' => self::lastVessel($resolved, $assignment),
            'home_since' => self::formatDateTime($item['home_since'] ?? null, $timezone),
            'days_at_home' => $daysAtHome,
            'max_home_days' => $maxHomeDays,
            'availability_status' => $status,
            'availability_label' => self::availabilityLabel($status, $daysAtHome, $maxHomeDays),
            'availability_detail' => self::availabilityDetail($status, $daysAtHome, $maxHomeDays),
            'latest_assignment' => $assignment ? [
                'id' => (int) $assignment->id,
                'assignment_no' => (string) $assignment->assignment_no,
                'status' => $assignment->status->value,
                'status_label' => $assignment->status->label(),
            ] : null,
            'can' => [
                'view_employee' => $user?->can('employees.view') ?? false,
                'view_assignment' => $assignment !== null && ($user?->can('crew_operations.assignments.view') ?? false),
                'start_assignment' => $user?->can('crew_operations.assignments.create') ?? false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private static function resolveLatestAssignment(array $resolved, int $companyId): ?CrewAssignment
    {
        $assignmentId = $resolved['assignment_id'] ?? null;

        if (! is_int($assignmentId) && ! is_numeric($assignmentId)) {
            return null;
        }

        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $assignmentId)
            ->first(['id', 'assignment_no', 'status', 'vessel_id']);
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array{id: int, name: string}|null
     */
    private static function lastVessel(array $resolved, ?CrewAssignment $assignment): ?array
    {
        $vesselName = $resolved['vessel_name'] ?? $resolved['current_vessel'] ?? null;

        if (! is_string($vesselName) || $vesselName === '') {
            return null;
        }

        if ($assignment?->vessel_id !== null) {
            $assignment->loadMissing('vessel:id,name');

            if ($assignment->vessel !== null) {
                return [
                    'id' => (int) $assignment->vessel->id,
                    'name' => (string) $assignment->vessel->name,
                ];
            }
        }

        return [
            'id' => 0,
            'name' => $vesselName,
        ];
    }

    private static function availabilityLabel(string $status, ?int $daysAtHome, int $maxHomeDays): string
    {
        if ($daysAtHome === null) {
            return 'Available';
        }

        return sprintf('%d / %d days', $daysAtHome, $maxHomeDays);
    }

    private static function availabilityDetail(string $status, ?int $daysAtHome, int $maxHomeDays): ?string
    {
        if ($daysAtHome === null) {
            return null;
        }

        if ($status === 'over_limit') {
            return sprintf(
                '%d days over availability limit',
                $daysAtHome - $maxHomeDays,
            );
        }

        if ($status === 'near_limit') {
            return sprintf(
                '%d days remaining',
                max(0, $maxHomeDays - $daysAtHome),
            );
        }

        return null;
    }

    private static function formatDateTime(mixed $value, string $timezone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->timezone($timezone)->toIso8601String();
        }

        return (string) $value;
    }
}
