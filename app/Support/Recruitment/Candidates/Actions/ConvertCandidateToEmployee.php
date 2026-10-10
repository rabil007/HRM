<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Employees\Actions\CreateEmployee;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConvertCandidateToEmployee
{
    /**
     * Convert a confirmed Joined candidate into an employee record.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        array $validated,
        int $companyId,
        ?UploadedFile $image = null,
    ): Employee {
        self::assertCanConvert($actor);

        if ((int) $candidate->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'candidate' => 'Candidate does not belong to the active company.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $validated, $companyId, $image): Employee {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];
            $requirement = $graph['requirement'];
            $line = $graph['line'];

            self::assertCanConvert($actor);

            if ((int) $locked->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'candidate' => 'Candidate does not belong to the active company.',
                ]);
            }

            if ($locked->stage !== CandidateStage::Joined) {
                throw ValidationException::withMessages([
                    'candidate' => 'Only confirmed Joined candidates can be converted to employees.',
                ]);
            }

            if ($locked->employee_id !== null) {
                throw ValidationException::withMessages([
                    'candidate' => 'This candidate has already been converted to an employee.',
                ]);
            }

            // Verify parent consistency
            if ($requirement !== null && (int) $requirement->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'candidate' => 'The parent requirement belongs to a different company.',
                ]);
            }

            if ($line !== null && $requirement !== null && (int) $line->recruitment_requirement_id !== (int) $requirement->id) {
                throw ValidationException::withMessages([
                    'candidate' => 'The candidate line is inconsistent with the parent requirement.',
                ]);
            }

            if (isset($validated['candidate_lock_version'])) {
                CandidateWorkflowAuthorization::assertExpectedLock(
                    $locked,
                    (int) $validated['candidate_lock_version'],
                    CandidateStage::Joined->value,
                    null,
                );
            }

            // Enforce department visibility
            $departmentId = $validated['department_id'] ?? null;
            $allowedDepartmentIds = EmployeeVisibilityScope::allowedDepartmentIds($actor, $companyId);
            if ($allowedDepartmentIds !== null) {
                if ($departmentId === null || $departmentId === '' || ! in_array((int) $departmentId, $allowedDepartmentIds, true)) {
                    throw ValidationException::withMessages([
                        'department_id' => 'The selected department is not accessible under your visibility restrictions.',
                    ]);
                }
            }

            // Atomically create the employee through CreateEmployee
            unset($validated['candidate_id'], $validated['candidate_lock_version']);
            $createEmployee = app(CreateEmployee::class);
            $employee = $createEmployee->handle($validated, $companyId, $actor->id, $image);

            // Link candidate to employee
            $locked->fill([
                'employee_id' => $employee->id,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::EmployeeConverted,
                CandidateStage::Joined,
                CandidateStage::Joined,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                "Candidate converted to employee #{$employee->employee_no}",
                [
                    'employee_id' => $employee->id,
                    'employee_no' => $employee->employee_no,
                ],
            );

            return $employee;
        });
    }

    public static function canConvert(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.view')
            && $user->can('employees.create')
            && $user->can('recruitment.candidates.convert')
            && $candidate->stage === CandidateStage::Joined
            && $candidate->employee_id === null;
    }

    public static function assertCanConvert(User $user): void
    {
        if (
            ! $user->can('recruitment.candidates.view') ||
            ! $user->can('employees.create') ||
            ! $user->can('recruitment.candidates.convert')
        ) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to convert candidates to employees.',
            ]);
        }
    }
}
