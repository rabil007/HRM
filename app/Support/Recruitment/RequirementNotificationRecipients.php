<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class RequirementNotificationRecipients
{
    /**
     * @param  list<User>  $additionalCcUsers
     * @return array{
     *     to_user_id: int|null,
     *     cc_user_ids: list<int>,
     *     skipped: list<array{user_id: int, reason: string}>
     * }
     */
    public static function resolveUserIds(
        RecruitmentRequirement $requirement,
        ?User $primaryRecipient,
        array $additionalCcUsers = [],
        bool $primaryMustBeEligibleApprover = false,
    ): array {
        $companyId = (int) $requirement->company_id;
        $skipped = [];
        $toUserId = null;

        if ($primaryRecipient !== null) {
            $primaryCheck = self::evaluateRecipient(
                $primaryRecipient,
                $companyId,
                requireApprovePermission: $primaryMustBeEligibleApprover,
            );

            if ($primaryCheck['eligible']) {
                $toUserId = (int) $primaryRecipient->id;
            } else {
                $skipped[] = [
                    'user_id' => (int) $primaryRecipient->id,
                    'reason' => $primaryCheck['reason'] ?? 'ineligible',
                ];
            }
        }

        $ccUserIds = [];
        $seenUserIds = $toUserId !== null ? [$toUserId => true] : [];

        foreach ($additionalCcUsers as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $userId = (int) $user->id;
            if (isset($seenUserIds[$userId])) {
                continue;
            }

            $check = self::evaluateRecipient($user, $companyId, requireApprovePermission: false);
            if (! $check['eligible']) {
                $skipped[] = [
                    'user_id' => $userId,
                    'reason' => $check['reason'] ?? 'ineligible',
                ];

                continue;
            }

            $seenUserIds[$userId] = true;
            $ccUserIds[] = $userId;
        }

        if ($skipped !== []) {
            Log::info('Recruitment requirement notification skipped ineligible recipients.', [
                'requirement_id' => $requirement->id,
                'company_id' => $companyId,
                'skipped' => $skipped,
            ]);
        }

        return [
            'to_user_id' => $toUserId,
            'cc_user_ids' => $ccUserIds,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{eligible: bool, reason: string|null, email: string|null}
     */
    public static function evaluateRecipient(
        User $user,
        int $companyId,
        bool $requireApprovePermission = false,
    ): array {
        if ($user->deleted_at !== null) {
            return ['eligible' => false, 'reason' => 'deleted', 'email' => null];
        }

        if ($user->status !== 'active') {
            return ['eligible' => false, 'reason' => 'inactive', 'email' => null];
        }

        if (! CompanyUserOptionsQuery::isActiveCompanyMember((int) $user->id, $companyId)) {
            return ['eligible' => false, 'reason' => 'not_company_member', 'email' => null];
        }

        if ($requireApprovePermission && ! RecruiterOptionsQuery::isEligibleApprover((int) $user->id, $companyId)) {
            return ['eligible' => false, 'reason' => 'missing_approve_permission', 'email' => null];
        }

        $email = self::usableEmail($user);
        if ($email === null) {
            return ['eligible' => false, 'reason' => 'no_usable_email', 'email' => null];
        }

        return ['eligible' => true, 'reason' => null, 'email' => $email];
    }

    public static function usableEmail(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $email = trim((string) $user->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Deduplicate email addresses (normalized lowercase) preserving order.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function dedupeEmails(array $emails): array
    {
        $seen = [];
        $result = [];

        foreach ($emails as $email) {
            $key = strtolower(trim($email));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $email;
        }

        return $result;
    }
}
