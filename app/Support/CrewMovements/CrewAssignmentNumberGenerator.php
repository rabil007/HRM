<?php

namespace App\Support\CrewMovements;

use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CrewAssignmentNumberGenerator
{
    public function next(int $companyId, ?int $year = null): string
    {
        $year ??= (int) now($this->companyTimezone($companyId))->year;
        $prefix = sprintf('CA-%d-', $year);

        return DB::transaction(function () use ($companyId, $prefix, $year): string {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $latest = CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->where('assignment_no', 'like', $prefix.'%')
                    ->lockForUpdate()
                    ->orderByDesc('assignment_no')
                    ->value('assignment_no');

                $next = 1;

                if (is_string($latest) && preg_match('/^CA-\d{4}-(\d+)$/', $latest, $matches) === 1) {
                    $next = ((int) $matches[1]) + 1;
                }

                $candidate = sprintf('CA-%d-%06d', $year, $next);

                $exists = CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->where('assignment_no', $candidate)
                    ->lockForUpdate()
                    ->exists();

                if (! $exists) {
                    return $candidate;
                }
            }

            throw CrewMovementException::make(
                'Unable to allocate a unique assignment number.',
                'assignment_number_race',
            );
        });
    }

    public function legacyForDeployment(int $employeeDeploymentId): string
    {
        return 'LEGACY-'.$employeeDeploymentId;
    }

    public function assertCreatable(int $companyId, string $assignmentNo): void
    {
        try {
            $exists = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->where('assignment_no', $assignmentNo)
                ->exists();
        } catch (QueryException $e) {
            throw CrewMovementException::make(
                'Failed to validate assignment number uniqueness.',
                'assignment_number_check_failed',
                previous: $e,
            );
        }

        if ($exists) {
            throw CrewMovementException::make(
                sprintf('Assignment number %s already exists for this company.', $assignmentNo),
                'assignment_number_duplicate',
            );
        }
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }
}
