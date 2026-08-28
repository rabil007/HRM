<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowTargetType;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class DocumentWorkflowPresetValidator
{
    public function __construct(
        private readonly ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
        private readonly DocumentWorkflowAssigneeEligibility $eligibility = new DocumentWorkflowAssigneeEligibility,
    ) {}

    /**
     * @param  list<array{action: string, completion_rule: string, targets: list<array{target_type: string, target_user_id?: int|null, target_role_id?: int|null}>}>  $stages
     *
     * @throws ValidationException
     */
    public function validateStages(int $companyId, array $stages): void
    {
        $validator = Validator::make(
            ['stages' => $stages],
            [
                'stages' => ['required', 'array', 'min:1'],
                'stages.*.action' => ['required', 'in:review,approve'],
                'stages.*.completion_rule' => ['required', 'in:all,any'],
                'stages.*.targets' => ['required', 'array', 'min:1'],
                'stages.*.targets.*.target_type' => ['required', 'in:'.implode(',', DocumentWorkflowTargetType::values())],
                'stages.*.targets.*.target_user_id' => ['nullable', 'integer'],
                'stages.*.targets.*.target_role_id' => ['nullable', 'integer'],
            ],
        );

        $validator->after(function ($validator) use ($stages, $companyId): void {
            if ($stages === []) {
                return;
            }

            $lastIndex = count($stages) - 1;
            if (($stages[$lastIndex]['action'] ?? null) !== 'approve') {
                $validator->errors()->add('stages', 'The final workflow stage must be an approval stage.');
            }

            foreach ($stages as $stageIndex => $stage) {
                $targets = $stage['targets'] ?? [];
                $seen = [];

                foreach ($targets as $targetIndex => $target) {
                    $type = $target['target_type'] ?? null;
                    $userId = isset($target['target_user_id']) ? (int) $target['target_user_id'] : null;
                    $roleId = isset($target['target_role_id']) ? (int) $target['target_role_id'] : null;

                    $key = implode(':', [$type, $userId ?? '', $roleId ?? '']);

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            "stages.{$stageIndex}.targets",
                            'Duplicate targets are not allowed within the same stage.',
                        );
                    }

                    $seen[$key] = true;

                    try {
                        $this->validateTargetFields($companyId, $type, $userId, $roleId, $stage['action'] ?? null, $stageIndex, $targetIndex);
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $field => $messages) {
                            foreach ($messages as $message) {
                                $validator->errors()->add($field, $message);
                            }
                        }
                    }
                }
            }
        });

        $validator->validate();
    }

    /**
     * @throws ValidationException
     */
    private function validateTargetFields(
        int $companyId,
        ?string $type,
        ?int $userId,
        ?int $roleId,
        ?string $stageAction,
        int $stageIndex,
        int $targetIndex,
    ): void {
        $fieldPrefix = "stages.{$stageIndex}.targets.{$targetIndex}";

        match ($type) {
            DocumentWorkflowTargetType::SpecificUser->value => $this->validateSpecificUserTarget($companyId, $userId, $stageAction, $fieldPrefix),
            DocumentWorkflowTargetType::CompanyRole->value => $this->validateCompanyRoleTarget($companyId, $roleId, $fieldPrefix),
            DocumentWorkflowTargetType::DepartmentManager->value,
            DocumentWorkflowTargetType::ParentManager->value => $this->validateManagerTarget($userId, $roleId, $fieldPrefix),
            default => throw ValidationException::withMessages([
                "{$fieldPrefix}.target_type" => ['Invalid target type.'],
            ]),
        };
    }

    /**
     * @throws ValidationException
     */
    private function validateSpecificUserTarget(int $companyId, ?int $userId, ?string $stageAction, string $fieldPrefix): void
    {
        if ($userId === null) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_user_id" => ['A specific user must be selected.'],
            ]);
        }

        $user = User::query()->whereKey($userId)->first(['id', 'status']);

        if ($user === null || ! $this->companyAccess->hasAccessibleMembership($user, $companyId)) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_user_id" => ['The selected user does not belong to this company.'],
            ]);
        }

        if ($stageAction !== null && ! $this->eligibility->isActionable($user, $companyId, $stageAction)) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_user_id" => ['The selected user does not have the required permissions for this stage.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateCompanyRoleTarget(int $companyId, ?int $roleId, string $fieldPrefix): void
    {
        if ($roleId === null) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_role_id" => ['A company role must be selected.'],
            ]);
        }

        $exists = Role::query()
            ->whereKey($roleId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_role_id" => ['The selected role does not belong to this company.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateManagerTarget(?int $userId, ?int $roleId, string $fieldPrefix): void
    {
        if ($userId !== null || $roleId !== null) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}.target_type" => ['Department and parent manager targets cannot include user or role selections.'],
            ]);
        }
    }
}
