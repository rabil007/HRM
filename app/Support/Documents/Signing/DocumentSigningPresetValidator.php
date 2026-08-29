<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningTargetType;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class DocumentSigningPresetValidator
{
    public const MAX_STEPS = 8;

    public function __construct(
        private ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    /**
     * @param  list<array{recipient_role: string, target_type?: string|null, target_user_id?: int|null, step_label?: string|null}>  $steps
     */
    public function validateSteps(int $companyId, array $steps): void
    {
        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => 'At least one signing step is required.',
            ]);
        }

        if (count($steps) > self::MAX_STEPS) {
            throw ValidationException::withMessages([
                'steps' => 'A signing preset may include at most '.self::MAX_STEPS.' steps.',
            ]);
        }

        $normalized = [];
        $seenSpecificUserIds = [];
        $seenSubject = false;
        $seenCompany = false;

        foreach (array_values($steps) as $index => $step) {
            $roleValue = (string) ($step['recipient_role'] ?? '');
            $role = DocumentRecipientRole::tryFrom($roleValue);

            if ($role === null || ! in_array($role, [
                DocumentRecipientRole::Subject,
                DocumentRecipientRole::Manager,
                DocumentRecipientRole::CompanySignatory,
            ], true)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.recipient_role" => 'Unsupported signing step role.',
                ]);
            }

            if ($role === DocumentRecipientRole::Subject) {
                if ($seenSubject) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.recipient_role" => 'The subject employee may appear only once.',
                    ]);
                }

                if ($index !== 0) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.recipient_role" => 'The first signing step must be the subject employee.',
                    ]);
                }

                $seenSubject = true;
            }

            if ($role === DocumentRecipientRole::Manager && $seenCompany) {
                throw ValidationException::withMessages([
                    "steps.{$index}.recipient_role" => 'Management signers must appear before company signatories.',
                ]);
            }

            if ($role === DocumentRecipientRole::CompanySignatory) {
                $seenCompany = true;
            }

            $expectedTarget = match ($role) {
                DocumentRecipientRole::Subject => DocumentSigningTargetType::SubjectEmployee,
                DocumentRecipientRole::Manager => DocumentSigningTargetType::DepartmentManager,
                DocumentRecipientRole::CompanySignatory => DocumentSigningTargetType::SpecificUser,
                default => null,
            };

            $targetTypeValue = (string) ($step['target_type'] ?? $expectedTarget?->value);
            $targetType = DocumentSigningTargetType::tryFrom($targetTypeValue);

            if ($expectedTarget === null || $targetType !== $expectedTarget) {
                throw ValidationException::withMessages([
                    "steps.{$index}.target_type" => 'Invalid target type for this signing role.',
                ]);
            }

            $targetUserId = isset($step['target_user_id']) ? (int) $step['target_user_id'] : null;
            $stepLabel = isset($step['step_label']) ? trim((string) $step['step_label']) : '';

            if (mb_strlen($stepLabel) > 120) {
                throw ValidationException::withMessages([
                    "steps.{$index}.step_label" => 'Step label may not be greater than 120 characters.',
                ]);
            }

            if ($role === DocumentRecipientRole::CompanySignatory) {
                if ($targetUserId === null || $targetUserId < 1) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.target_user_id" => 'A company signatory must be selected.',
                    ]);
                }

                if (isset($seenSpecificUserIds[$targetUserId])) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.target_user_id" => 'The same company signatory cannot appear twice in one preset.',
                    ]);
                }

                $this->assertEligibleCompanySignatory($companyId, $targetUserId, $index);
                $seenSpecificUserIds[$targetUserId] = true;
            } elseif ($targetUserId !== null) {
                throw ValidationException::withMessages([
                    "steps.{$index}.target_user_id" => 'This signing role does not accept a specific user.',
                ]);
            }

            $normalized[] = [
                'role' => $role,
            ];
        }

        if (($normalized[0]['role'] ?? null) !== DocumentRecipientRole::Subject) {
            throw ValidationException::withMessages([
                'steps.0.recipient_role' => 'The first signing step must be the subject employee.',
            ]);
        }
    }

    private function assertEligibleCompanySignatory(int $companyId, int $userId, int $index): void
    {
        $user = User::query()->whereKey($userId)->first();

        if ($user === null || $user->status !== 'active') {
            throw ValidationException::withMessages([
                "steps.{$index}.target_user_id" => 'The selected company signatory is not an active user.',
            ]);
        }

        $membership = $this->companyAccess->accessibleMembershipByUserId($companyId, [$userId]);

        if (! ($membership[$userId] ?? false)) {
            throw ValidationException::withMessages([
                "steps.{$index}.target_user_id" => 'The selected company signatory does not belong to the active company.',
            ]);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        if (! $user->can('documents.recipient-requests.respond')) {
            throw ValidationException::withMessages([
                "steps.{$index}.target_user_id" => 'The selected company signatory is not authorized to respond to recipient requests.',
            ]);
        }
    }
}
