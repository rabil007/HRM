<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LinkCandidateToEmployee
{
    /**
     * Link an existing employee record to a confirmed Joined candidate with explicit confirmation.
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        int $employeeId,
        string $reason,
        int $companyId,
        bool $confirmed = false,
        ?int $lockVersion = null,
    ): RecruitmentCandidate {
        self::assertCanLink($actor);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirmed' => 'Explicit confirmation is required to link an existing employee record.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to link an existing employee record.',
            ]);
        }

        if ((int) $candidate->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'candidate' => 'Candidate does not belong to the active company.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $employeeId, $reason, $companyId, $lockVersion): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];
            $requirement = $graph['requirement'];
            $line = $graph['line'];

            self::assertCanLink($actor);

            if ((int) $locked->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'candidate' => 'Candidate does not belong to the active company.',
                ]);
            }

            if ($locked->stage !== CandidateStage::Joined) {
                throw ValidationException::withMessages([
                    'candidate' => 'Only confirmed Joined candidates can be linked to an employee.',
                ]);
            }

            if ($locked->employee_id !== null) {
                throw ValidationException::withMessages([
                    'candidate' => 'This candidate is already linked to an employee.',
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

            if ($lockVersion !== null) {
                CandidateWorkflowAuthorization::assertExpectedLock(
                    $locked,
                    $lockVersion,
                    CandidateStage::Joined->value,
                    null,
                );
            }

            // Validate the target employee
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->where('id', $employeeId)
                ->first();

            if (! $employee instanceof Employee) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The selected employee was not found in the active company.',
                ]);
            }

            if (! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
                throw ValidationException::withMessages([
                    'employee_id' => 'You do not have permission to view or select this employee.',
                ]);
            }

            // Update candidate
            $locked->fill([
                'employee_id' => $employee->id,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::EmployeeLinked,
                CandidateStage::Joined,
                CandidateStage::Joined,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                "Linked to existing employee #{$employee->employee_no}: ".trim($reason),
                [
                    'employee_id' => $employee->id,
                    'employee_no' => $employee->employee_no,
                    'reason' => trim($reason),
                ],
            );

            return $locked->refresh();
        });
    }

    public static function canLink(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.view')
            && $user->can('recruitment.candidates.convert')
            && $user->can('employees.view')
            && $candidate->stage === CandidateStage::Joined
            && $candidate->employee_id === null;
    }

    public static function assertCanLink(User $user): void
    {
        if (
            ! $user->can('recruitment.candidates.view') ||
            ! $user->can('recruitment.candidates.convert') ||
            ! $user->can('employees.view')
        ) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to link existing employees to candidates.',
            ]);
        }
    }
}
