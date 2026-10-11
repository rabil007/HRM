<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class FindCandidateDuplicateEmployees
{
    /**
     * Locate existing employees matching reliable identifiers of the candidate,
     * scoped strictly by tenant and the authenticated user's employee visibility.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     employee_no: string,
     *     department_name: ?string,
     *     position_title: ?string,
     *     matched_on: list<string>,
     * }>
     */
    public static function find(RecruitmentCandidate $candidate, User $user, int $companyId): array
    {
        if (! $user->can('employees.view')) {
            return [];
        }

        $hasEmail = filled($candidate->email);
        $hasPhone = filled($candidate->phone);
        $hasName = filled($candidate->name);

        if (! $hasEmail && ! $hasPhone && ! $hasName) {
            return [];
        }

        $emailNormalized = mb_strtolower(trim((string) $candidate->email));
        $phoneTrimmed = trim((string) $candidate->phone);
        $nameTrimmed = mb_strtolower(trim((string) $candidate->name));

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->with(['department:id,name', 'position:id,title']);

        // Strictly apply department / employee visibility scoping
        EmployeeVisibilityScope::apply($query, $user, $companyId);

        $query->where(function (Builder $q) use ($hasEmail, $emailNormalized, $hasPhone, $phoneTrimmed, $hasName, $nameTrimmed): void {
            if ($hasEmail && $emailNormalized !== '') {
                $q->orWhereRaw('LOWER(personal_email) = ?', [$emailNormalized])
                    ->orWhereRaw('LOWER(work_email) = ?', [$emailNormalized]);
            }

            if ($hasPhone && $phoneTrimmed !== '') {
                $q->orWhere('phone', $phoneTrimmed)
                    ->orWhere('emergency_phone', $phoneTrimmed)
                    ->orWhere('phone_home_country', $phoneTrimmed);
            }

            if ($hasName && $nameTrimmed !== '') {
                $q->orWhereRaw('LOWER(name) = ?', [$nameTrimmed]);
            }
        });

        /** @var Collection<int, Employee> $employees */
        $employees = $query->take(10)->get();

        $results = [];

        foreach ($employees as $employee) {
            $matchedOn = [];

            if ($hasEmail && $emailNormalized !== '') {
                $personal = mb_strtolower(trim((string) $employee->personal_email));
                $work = mb_strtolower(trim((string) $employee->work_email));
                if ($personal === $emailNormalized || $work === $emailNormalized) {
                    $matchedOn[] = 'Email';
                }
            }

            if ($hasPhone && $phoneTrimmed !== '') {
                $empPhone = trim((string) $employee->phone);
                $empEmerg = trim((string) $employee->emergency_phone);
                $empHome = trim((string) $employee->phone_home_country);
                if ($empPhone === $phoneTrimmed || $empEmerg === $phoneTrimmed || $empHome === $phoneTrimmed) {
                    $matchedOn[] = 'Phone';
                }
            }

            if ($hasName && $nameTrimmed !== '' && mb_strtolower(trim((string) $employee->name)) === $nameTrimmed) {
                $matchedOn[] = 'Name';
            }

            if ($matchedOn === []) {
                $matchedOn[] = 'Identifier';
            }

            $results[] = [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => (string) $employee->employee_no,
                'department_name' => $employee->department?->name,
                'position_title' => $employee->position?->title,
                'matched_on' => $matchedOn,
            ];
        }

        return $results;
    }
}
