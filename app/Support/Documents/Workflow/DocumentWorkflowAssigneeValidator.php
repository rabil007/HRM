<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DocumentWorkflowAssigneeValidator
{
    public function __construct(
        private readonly ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
        private readonly DocumentWorkflowCompanyPermissions $workflowPermissions = new DocumentWorkflowCompanyPermissions,
    ) {}

    /**
     * @param  list<array{action: string, completion_rule: string, assignee_user_ids: list<int>}>  $stages
     * @return array<int, User> keyed by user id
     *
     * @throws ValidationException
     */
    public function validateStages(int $companyId, array $stages, ?int $requesterUserId = null): array
    {
        $validator = Validator::make(
            ['stages' => $stages],
            [
                'stages' => ['required', 'array', 'min:1'],
                'stages.*.action' => ['required', 'in:review,approve'],
                'stages.*.completion_rule' => ['required', 'in:all,any'],
                'stages.*.assignee_user_ids' => ['required', 'array', 'min:1'],
                'stages.*.assignee_user_ids.*' => ['integer'],
            ],
        );

        $validator->after(function ($validator) use ($stages): void {
            if ($stages === []) {
                return;
            }

            $lastIndex = count($stages) - 1;
            $lastAction = $stages[$lastIndex]['action'] ?? null;

            if ($lastAction !== 'approve') {
                $validator->errors()->add('stages', 'The final workflow stage must be an approval stage.');
            }

            foreach ($stages as $index => $stage) {
                $assigneeIds = $stage['assignee_user_ids'] ?? [];
                if (count($assigneeIds) !== count(array_unique($assigneeIds))) {
                    $validator->errors()->add("stages.{$index}.assignee_user_ids", 'Duplicate assignees are not allowed within the same stage.');
                }
            }
        });

        $validator->validate();

        $allUserIds = [];
        foreach ($stages as $stage) {
            foreach ($stage['assignee_user_ids'] as $userId) {
                $allUserIds[] = (int) $userId;
            }
        }

        $allUserIds = array_values(array_unique($allUserIds));

        if ($allUserIds === []) {
            throw ValidationException::withMessages([
                'stages' => ['At least one assignee is required.'],
            ]);
        }

        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId($companyId, $allUserIds);

        $invalidIds = [];
        foreach ($allUserIds as $userId) {
            if (! ($membershipByUserId[$userId] ?? false)) {
                $invalidIds[] = $userId;
            }
        }

        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'stages' => ['One or more assignees are not valid users for this company.'],
            ]);
        }

        $users = User::query()
            ->whereIn('id', $allUserIds)
            ->where('status', 'active')
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($allUserIds as $userId) {
            if (! $users->has($userId)) {
                throw ValidationException::withMessages([
                    'stages' => ['One or more assignees are inactive or unknown.'],
                ]);
            }
        }

        if ($requesterUserId !== null) {
            foreach ($stages as $stage) {
                foreach ($stage['assignee_user_ids'] as $userId) {
                    if ((int) $userId === $requesterUserId) {
                        throw ValidationException::withMessages([
                            'stages' => ['The request creator cannot be assigned as a reviewer or approver.'],
                        ]);
                    }
                }
            }
        }

        foreach ($stages as $index => $stage) {
            $action = $stage['action'] ?? null;

            foreach ($stage['assignee_user_ids'] as $userId) {
                $user = $users->get((int) $userId);

                if ($user === null) {
                    continue;
                }

                $allowed = match ($action) {
                    'review' => $this->workflowPermissions->canReview($user, $companyId),
                    'approve' => $this->workflowPermissions->canApprove($user, $companyId),
                    default => false,
                };

                if (! $allowed) {
                    throw ValidationException::withMessages([
                        "stages.{$index}.assignee_user_ids" => ['One or more assignees cannot perform this stage action.'],
                    ]);
                }
            }
        }

        /** @var array<int, User> */
        return $users->all();
    }
}
