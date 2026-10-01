<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class RequirementNotificationRecipients
{
    /**
     * @return array{to: string|null, cc: list<string>, skipped_user_ids: list<int>}
     */
    public static function resolve(
        RecruitmentRequirement $requirement,
        ?User $primaryRecipient,
        bool $includeRequesterInCc = true,
        bool $excludePrimaryFromCc = true,
    ): array {
        $requirement->loadMissing([
            'creator:id,name,email',
            'notificationRecipients.user:id,name,email',
        ]);

        $toEmail = self::usableEmail($primaryRecipient);
        $cc = [];
        $skipped = [];
        $seen = [];

        if ($toEmail !== null) {
            $seen[strtolower($toEmail)] = true;
        }

        $candidates = [];

        if ($includeRequesterInCc && $requirement->creator !== null) {
            $candidates[] = $requirement->creator;
        }

        foreach ($requirement->notificationRecipients as $recipient) {
            if ($recipient->user !== null) {
                $candidates[] = $recipient->user;
            }
        }

        foreach ($candidates as $user) {
            $email = self::usableEmail($user);
            if ($email === null) {
                $skipped[] = (int) $user->id;

                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }

            if ($excludePrimaryFromCc && $primaryRecipient !== null && (int) $user->id === (int) $primaryRecipient->id) {
                continue;
            }

            $seen[$key] = true;
            $cc[] = $email;
        }

        if ($skipped !== []) {
            Log::info('Recruitment requirement notification skipped recipients without usable email.', [
                'requirement_id' => $requirement->id,
                'company_id' => $requirement->company_id,
                'skipped_user_ids' => $skipped,
            ]);
        }

        return [
            'to' => $toEmail,
            'cc' => $cc,
            'skipped_user_ids' => $skipped,
        ];
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
}
